<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\ParameterMapping;
use App\Report\ReportSummary;
use App\Report\ReportSummaryBuilder;
use App\Security\FileName;
use App\Support\Clock;
use App\Support\Logger;
use App\Support\MerlinDate;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Analysiert Merlin-Dateien und speichert sie atomar (eine Transaktion) als Bericht-Snapshot.
 */
class ImportService
{
    /** Version des Berichts-Snapshots (Struktur von reports/report_parameters/summary_snapshot). */
    public const int REPORT_VERSION = 1;

    private const int PARAMETER_CHUNK_SIZE = 100;

    public function __construct(
        protected readonly PDO $pdo,
        private readonly MerlinParser $parser,
        private readonly ImportValidator $validator,
        private readonly ParameterMapping $mapping,
        private readonly ReportSummaryBuilder $summaryBuilder,
        private readonly Clock $clock,
        private readonly ?ImportArchive $archive = null,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function analyze(string $bytes, string $originalFilename): ImportAnalysis
    {
        $result = $this->parser->parse($bytes);
        $assignments = [];
        foreach ($result->records as $record) {
            $assignments[$record->position] = $this->mapping->resolve($record->parameterId, $record->name);
        }
        $summary = $this->summaryBuilder->build($result->records);
        $hash = hash('sha256', $bytes);

        return new ImportAnalysis(
            filename: FileName::sanitize($originalFilename),
            fileSize: strlen($bytes),
            fileHash: $hash,
            parseResult: $result,
            assignments: $assignments,
            summary: $summary,
            validation: $this->validator->validate($result, $summary),
            previousImports: $this->findImportsByHash($hash),
        );
    }

    /**
     * @return list<array{id: int, imported_at: string, status: string, report_id: ?int}>
     */
    public function findImportsByHash(string $hash): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.imported_at, i.status, r.id AS report_id
               FROM imports i LEFT JOIN reports r ON r.import_id = i.id
              WHERE i.file_hash = ? AND i.status <> 'failed'
              ORDER BY i.id"
        );
        $stmt->execute([$hash]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'imported_at' => (string) $row['imported_at'],
            'status' => (string) $row['status'],
            'report_id' => $row['report_id'] === null ? null : (int) $row['report_id'],
        ], $stmt->fetchAll());
    }

    public function import(ImportAnalysis $analysis, string $bytes, bool $allowDuplicate = false): ImportOutcome
    {
        if (!hash_equals($analysis->fileHash, hash('sha256', $bytes))) {
            throw new ImportFailedException('Die Datei wurde seit der Analyse veraendert.');
        }
        if (!$analysis->validation->isValid()) {
            $failedId = $this->recordFailedImport($analysis, implode(' ', $analysis->validation->blockingErrors));
            throw new ImportFailedException(
                'Import abgelehnt: ' . implode(' ', $analysis->validation->blockingErrors),
                $failedId,
            );
        }
        if (!$allowDuplicate && $this->findImportsByHash($analysis->fileHash) !== []) {
            throw new DuplicateImportException('Diese Datei wurde bereits importiert.');
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $summary = $analysis->summary;

        try {
            $this->pdo->beginTransaction();

            $patientId = $this->upsertPatient($summary, $now);
            $deviceId = $this->upsertDevice($summary, $patientId, $now);
            $leadIds = $deviceId === null ? [] : $this->upsertLeads($summary, $deviceId, $now);
            $archiveFilename = $this->archiveOriginal($analysis->fileHash, $bytes);
            $importId = $this->insertImport($analysis, $archiveFilename, $now);
            $reportId = $this->insertReport($analysis, $importId, $patientId, $deviceId, $now);
            $this->linkLeads($reportId, $leadIds);
            $definitionIds = $this->upsertParameterDefinitions($analysis, $now);
            $this->insertParameters($analysis, $reportId, $definitionIds, $now);
            $this->insertIssues($importId, $analysis->issues(), $now);
            $this->verifyParameterCount($reportId, $analysis->parseResult->validRecordCount());

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $reference = $this->logger?->error('Import fehlgeschlagen (Rollback)', ['file_hash' => $analysis->fileHash], $e);
            $failedId = $this->recordFailedImport($analysis, 'Technischer Fehler beim Speichern' . ($reference !== null ? ' (Ref. ' . $reference . ')' : ''));
            throw new ImportFailedException(
                'Der Import ist fehlgeschlagen. Es wurden keine Berichtsdaten gespeichert.',
                $failedId,
                $e,
            );
        }

        $this->logger?->info('Import abgeschlossen', ['import_id' => $importId, 'report_id' => $reportId]);

        return new ImportOutcome(
            $importId,
            $reportId,
            $analysis->parseResult->validRecordCount(),
            $analysis->status(),
            $archiveFilename,
        );
    }

    protected function upsertPatient(ReportSummary $summary, string $now): ?int
    {
        $identifier = $summary->nonEmpty('patient_identifier');
        $name = $summary->nonEmpty('patient_name');
        if ($identifier === null && $name === null) {
            return null;
        }
        $dobRaw = $summary->nonEmpty('patient_dob');
        $dob = MerlinDate::parse($dobRaw)?->format('Y-m-d');

        if ($identifier !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM patients WHERE patient_identifier = ? FOR UPDATE');
            $stmt->execute([$identifier]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM patients
                  WHERE patient_identifier IS NULL
                    AND patient_name = ? COLLATE utf8mb4_bin
                    AND date_of_birth_raw <=> ?
                  ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$name, $dobRaw]);
        }
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            // Stammdaten werden nur ergaenzt, nie ueberschrieben (Berichte haben eigene Snapshots).
            $this->pdo->prepare(
                'UPDATE patients SET patient_name = COALESCE(patient_name, ?),
                        date_of_birth = COALESCE(date_of_birth, ?),
                        date_of_birth_raw = COALESCE(date_of_birth_raw, ?),
                        updated_at = ?
                  WHERE id = ?'
            )->execute([$name, $dob, $dobRaw, $now, $id]);
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO patients (patient_identifier, patient_name, date_of_birth, date_of_birth_raw, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$identifier, $name, $dob, $dobRaw, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    protected function upsertDevice(ReportSummary $summary, ?int $patientId, string $now): ?int
    {
        $serial = $summary->nonEmpty('device_serial');
        if ($serial === null) {
            return null;
        }
        $modelNumber = $summary->nonEmpty('device_model_number');
        $implantRaw = $summary->nonEmpty('device_implant_date');
        $values = [
            $patientId,
            $summary->nonEmpty('device_manufacturer'),
            $summary->nonEmpty('device_model_name'),
            MerlinDate::parse($implantRaw)?->format('Y-m-d'),
            $implantRaw,
        ];

        $stmt = $this->pdo->prepare('SELECT id FROM devices WHERE serial_number = ? AND model_number_key = ? FOR UPDATE');
        $stmt->execute([$serial, $modelNumber ?? '']);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare(
                'UPDATE devices SET patient_id = COALESCE(patient_id, ?),
                        manufacturer = COALESCE(manufacturer, ?),
                        model_name = COALESCE(model_name, ?),
                        implant_date = COALESCE(implant_date, ?),
                        implant_date_raw = COALESCE(implant_date_raw, ?),
                        updated_at = ?
                  WHERE id = ?'
            )->execute([...$values, $now, $id]);
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO devices (patient_id, manufacturer, model_name, implant_date, implant_date_raw, model_number, serial_number, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([...$values, $modelNumber, $serial, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<int>
     */
    protected function upsertLeads(ReportSummary $summary, int $deviceId, string $now): array
    {
        $ids = [];
        foreach ($summary->leads as $lead) {
            $v = static fn (string $key): ?string => ($lead[$key] === null || trim((string) $lead[$key]) === '') ? null : (string) $lead[$key];
            $serial = $v('serial_number');
            $implantRaw = $v('implant_date');
            $implant = MerlinDate::parse($implantRaw)?->format('Y-m-d');

            if ($serial !== null) {
                $stmt = $this->pdo->prepare('SELECT id FROM leads WHERE device_id = ? AND chamber = ? AND serial_number = ? FOR UPDATE');
                $stmt->execute([$deviceId, $lead['chamber'], $serial]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT id FROM leads WHERE device_id = ? AND chamber = ? AND serial_number IS NULL
                        AND model_number <=> ? AND implant_date_raw <=> ? ORDER BY id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$deviceId, $lead['chamber'], $v('model_number'), $implantRaw]);
            }
            $id = $stmt->fetchColumn();

            $values = [$v('manufacturer'), $v('model_label'), $v('model_number'), $v('lead_type'), $implant, $implantRaw];
            if ($id !== false) {
                $this->pdo->prepare(
                    'UPDATE leads SET manufacturer = COALESCE(manufacturer, ?), model_label = COALESCE(model_label, ?),
                            model_number = COALESCE(model_number, ?), lead_type = COALESCE(lead_type, ?),
                            implant_date = COALESCE(implant_date, ?), implant_date_raw = COALESCE(implant_date_raw, ?),
                            updated_at = ?
                      WHERE id = ?'
                )->execute([...$values, $now, $id]);
                $ids[] = (int) $id;
                continue;
            }
            $this->pdo->prepare(
                'INSERT INTO leads (manufacturer, model_label, model_number, lead_type, implant_date, implant_date_raw,
                                    device_id, chamber, chamber_source, serial_number, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([...$values, $deviceId, $lead['chamber'], $lead['chamber_source'], $serial, $now, $now]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        return array_values(array_unique($ids));
    }

    protected function archiveOriginal(string $hash, string $bytes): ?string
    {
        if ($this->archive === null) {
            return null;
        }
        try {
            return $this->archive->store($hash, $bytes);
        } catch (Throwable $e) {
            // Archivierung ist optional: Berichte werden ausschliesslich aus der Datenbank erzeugt.
            $this->logger?->warning('Originaldatei konnte nicht archiviert werden', ['file_hash' => $hash, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function insertImport(ImportAnalysis $analysis, ?string $archiveFilename, string $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO imports (filename, file_hash, file_size, encoding, archive_filename, imported_at, parser_version,
                                  record_count, valid_record_count, warning_count, error_count, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $analysis->filename,
            $analysis->fileHash,
            $analysis->fileSize,
            $analysis->parseResult->encoding,
            $archiveFilename,
            $now,
            MerlinParser::VERSION,
            $analysis->parseResult->recordCount,
            $analysis->parseResult->validRecordCount(),
            $analysis->warningCount(),
            $analysis->errorCount(),
            $analysis->status(),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    protected function insertReport(ImportAnalysis $analysis, int $importId, ?int $patientId, ?int $deviceId, string $now): int
    {
        $s = $analysis->summary;
        $snapshot = json_encode(
            $this->summaryBuilder->toSnapshot($s),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        $sessionRaw = $s->nonEmpty('session_timestamp');
        $interrogationRaw = $s->nonEmpty('interrogation_timestamp');

        $this->pdo->prepare(
            'INSERT INTO reports (import_id, patient_id, device_id, session_timestamp, session_timestamp_raw,
                                  interrogation_timestamp, interrogation_timestamp_raw, report_version, parser_version,
                                  mapping_version, patient_name_snapshot, patient_identifier_snapshot, patient_dob_snapshot,
                                  device_manufacturer_snapshot, device_model_name_snapshot, device_model_number_snapshot,
                                  device_serial_snapshot, summary_snapshot, parameter_count, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $importId,
            $patientId,
            $deviceId,
            MerlinDate::parse($sessionRaw)?->format('Y-m-d H:i:s'),
            $sessionRaw,
            MerlinDate::parse($interrogationRaw)?->format('Y-m-d H:i:s'),
            $interrogationRaw,
            self::REPORT_VERSION,
            MerlinParser::VERSION,
            $this->mapping->version(),
            $s->value('patient_name'),
            $s->value('patient_identifier'),
            $s->value('patient_dob'),
            $s->value('device_manufacturer'),
            $s->value('device_model_name'),
            $s->value('device_model_number'),
            $s->value('device_serial'),
            $snapshot,
            $analysis->parseResult->validRecordCount(),
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<int> $leadIds
     */
    protected function linkLeads(int $reportId, array $leadIds): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO report_leads (report_id, lead_id) VALUES (?, ?)');
        foreach ($leadIds as $leadId) {
            $stmt->execute([$reportId, $leadId]);
        }
    }

    /**
     * @return array<string, int> Quell-ID => parameter_definitions.id
     */
    protected function upsertParameterDefinitions(ImportAnalysis $analysis, string $now): array
    {
        $occurrences = [];
        $firstRecord = [];
        foreach ($analysis->parseResult->records as $record) {
            $occurrences[$record->parameterId] = ($occurrences[$record->parameterId] ?? 0) + 1;
            $firstRecord[$record->parameterId] ??= $record;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO parameter_definitions (source_parameter_id, source_name, category_key, mapping_source, mapping_version,
                                                standard_system, standard_code, occurrence_count, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE source_name = new.source_name, category_key = new.category_key,
                 mapping_source = new.mapping_source, mapping_version = new.mapping_version,
                 standard_system = new.standard_system, standard_code = new.standard_code,
                 occurrence_count = parameter_definitions.occurrence_count + new.occurrence_count,
                 last_seen_at = new.last_seen_at'
        );
        foreach ($firstRecord as $parameterId => $record) {
            $assignment = $analysis->assignments[$record->position];
            $standard = $this->mapping->standardCode((string) $parameterId);
            $stmt->execute([
                (string) $parameterId,
                $record->name,
                $assignment->key,
                $assignment->source,
                $this->mapping->version(),
                $standard['system'] ?? null,
                $standard['code'] ?? null,
                $occurrences[$parameterId],
                $now,
                $now,
            ]);
        }

        $ids = [];
        foreach (array_chunk(array_map('strval', array_keys($firstRecord)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $select = $this->pdo->prepare("SELECT id, source_parameter_id FROM parameter_definitions WHERE source_parameter_id IN ($placeholders)");
            $select->execute($chunk);
            foreach ($select->fetchAll() as $row) {
                $ids[(string) $row['source_parameter_id']] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /**
     * @param array<string, int> $definitionIds
     */
    protected function insertParameters(ImportAnalysis $analysis, int $reportId, array $definitionIds, string $now): void
    {
        $rows = [];
        foreach ($analysis->parseResult->records as $record) {
            $assignment = $analysis->assignments[$record->position];
            $rows[] = [
                $reportId,
                $definitionIds[$record->parameterId] ?? throw new RuntimeException('Parameterdefinition fehlt.'),
                $record->parameterId,
                $record->name,
                $assignment->displayName,
                $record->value,
                $record->unit,
                $assignment->key,
                $assignment->label,
                $assignment->sort,
                $assignment->source,
                $record->position,
                $record->rawRecord,
                $now,
            ];
        }
        foreach (array_chunk($rows, self::PARAMETER_CHUNK_SIZE) as $index => $chunk) {
            $this->insertParameterChunk($chunk, $index);
        }
    }

    /**
     * @param list<list<int|string|null>> $rows
     */
    protected function insertParameterChunk(array $rows, int $chunkIndex): void
    {
        $placeholders = implode(',', array_fill(0, count($rows), '(' . implode(',', array_fill(0, 14, '?')) . ')'));
        $this->pdo->prepare(
            "INSERT INTO report_parameters (report_id, parameter_definition_id, parameter_id, parameter_name, display_name,
                                            value, unit, category, category_label, category_sort, mapping_source,
                                            original_position, raw_record, created_at)
             VALUES $placeholders"
        )->execute(array_merge(...$rows));
    }

    /**
     * @param list<ImportIssue> $issues
     */
    protected function insertIssues(int $importId, array $issues, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_errors (import_id, severity, error_code, message, record_position, raw_record, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($issues as $issue) {
            $stmt->execute([$importId, $issue->severity, $issue->code, mb_substr($issue->message, 0, 1000), $issue->position, $issue->rawRecord, $now]);
        }
    }

    protected function verifyParameterCount(int $reportId, int $expected): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM report_parameters WHERE report_id = ?');
        $stmt->execute([$reportId]);
        $actual = (int) $stmt->fetchColumn();
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('Parameteranzahl stimmt nicht: erwartet %d, gespeichert %d.', $expected, $actual));
        }
    }

    /**
     * Protokolliert einen fehlgeschlagenen Importversuch (ohne Berichtsdaten).
     */
    private function recordFailedImport(ImportAnalysis $analysis, string $reason): ?int
    {
        try {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $this->pdo->beginTransaction();
            $this->pdo->prepare(
                "INSERT INTO imports (filename, file_hash, file_size, encoding, archive_filename, imported_at, parser_version,
                                      record_count, valid_record_count, warning_count, error_count, status, failure_reason)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 'failed', ?)"
            )->execute([
                $analysis->filename,
                $analysis->fileHash,
                $analysis->fileSize,
                $analysis->parseResult->encoding,
                $now,
                MerlinParser::VERSION,
                $analysis->parseResult->recordCount,
                $analysis->parseResult->validRecordCount(),
                $analysis->warningCount(),
                $analysis->errorCount(),
                mb_substr($reason, 0, 500),
            ]);
            $importId = (int) $this->pdo->lastInsertId();
            $this->insertIssues($importId, $analysis->issues(), $now);
            $this->pdo->commit();
            return $importId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger?->error('Fehlgeschlagener Import konnte nicht protokolliert werden', ['file_hash' => $analysis->fileHash], $e);
            return null;
        }
    }
}
