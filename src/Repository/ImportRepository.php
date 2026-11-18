<?php

declare(strict_types=1);

namespace App\Repository;

use App\Import\ImportIssue;
use PDO;

/**
 * Lese- und Schreibzugriffe fuer Importprotokoll, Berichts-Snapshots und Gerate-/Sonden-Stammdaten.
 * Ausschliesslich Prepared Statements.
 */
final class ImportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, imported_at: string, status: string, report_id: ?int}>
     */
    public function findByHash(string $hash): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.imported_at, i.status, r.id AS report_id"
            . " FROM imports i LEFT JOIN reports r ON r.import_id = i.id"
            . " WHERE i.file_hash = ? AND i.status <> 'failed'"
            . " ORDER BY i.id"
        );
        $stmt->execute([$hash]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'imported_at' => (string) $row['imported_at'],
            'status' => (string) $row['status'],
            'report_id' => $row['report_id'] === null ? null : (int) $row['report_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $where = $status !== null ? ' WHERE i.status = :status' : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM imports i' . $where);
        $count->execute($status !== null ? [':status' => $status] : []);

        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.filename, i.file_hash, i.file_size, i.encoding, i.imported_at, i.record_count,'
            . ' i.valid_record_count, i.warning_count, i.error_count, i.status, i.failure_reason, r.id AS report_id'
            . ' FROM imports i LEFT JOIN reports r ON r.import_id = i.id' . $where
            . ' ORDER BY i.imported_at DESC, i.id DESC LIMIT :limit OFFSET :offset'
        );
        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, r.id AS report_id FROM imports i LEFT JOIN reports r ON r.import_id = i.id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(int $importId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT severity, error_code, message, record_position, raw_record FROM import_errors'
            . ' WHERE import_id = ? ORDER BY record_position IS NULL DESC, record_position, id'
        );
        $stmt->execute([$importId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function upsertDevice(
        string $serial,
        ?string $modelNumber,
        ?int $patientId,
        ?string $manufacturer,
        ?string $modelName,
        ?string $implantDate,
        ?string $implantDateRaw,
        string $now,
    ): int {
        $stmt = $this->pdo->prepare('SELECT id FROM devices WHERE serial_number = ? AND model_number_key = ? FOR UPDATE');
        $stmt->execute([$serial, $modelNumber ?? '']);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            $this->pdo->prepare(
                'UPDATE devices SET patient_id = COALESCE(patient_id, ?),'
                . ' manufacturer = COALESCE(manufacturer, ?),'
                . ' model_name = COALESCE(model_name, ?),'
                . ' implant_date = COALESCE(implant_date, ?),'
                . ' implant_date_raw = COALESCE(implant_date_raw, ?),'
                . ' updated_at = ?'
                . ' WHERE id = ?'
            )->execute([$patientId, $manufacturer, $modelName, $implantDate, $implantDateRaw, $now, $id]);
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO devices (patient_id, manufacturer, model_name, implant_date, implant_date_raw,'
            . ' model_number, serial_number, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$patientId, $manufacturer, $modelName, $implantDate, $implantDateRaw, $modelNumber, $serial, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function upsertLead(
        int $deviceId,
        string $chamber,
        string $chamberSource,
        ?string $serial,
        ?string $manufacturer,
        ?string $modelLabel,
        ?string $modelNumber,
        ?string $leadType,
        ?string $implantDate,
        ?string $implantDateRaw,
        string $now,
    ): int {
        if ($serial !== null) {
            $stmt = $this->pdo->prepare('SELECT id FROM leads WHERE device_id = ? AND chamber = ? AND serial_number = ? FOR UPDATE');
            $stmt->execute([$deviceId, $chamber, $serial]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM leads WHERE device_id = ? AND chamber = ? AND serial_number IS NULL'
                . ' AND model_number <=> ? AND implant_date_raw <=> ? ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$deviceId, $chamber, $modelNumber, $implantDateRaw]);
        }
        $id = $stmt->fetchColumn();

        $values = [$manufacturer, $modelLabel, $modelNumber, $leadType, $implantDate, $implantDateRaw];
        if ($id !== false) {
            $this->pdo->prepare(
                'UPDATE leads SET manufacturer = COALESCE(manufacturer, ?), model_label = COALESCE(model_label, ?),'
                . ' model_number = COALESCE(model_number, ?), lead_type = COALESCE(lead_type, ?),'
                . ' implant_date = COALESCE(implant_date, ?), implant_date_raw = COALESCE(implant_date_raw, ?),'
                . ' updated_at = ?'
                . ' WHERE id = ?'
            )->execute([...$values, $now, $id]);
            return (int) $id;
        }

        $this->pdo->prepare(
            'INSERT INTO leads (manufacturer, model_label, model_number, lead_type, implant_date, implant_date_raw,'
            . ' device_id, chamber, chamber_source, serial_number, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([...$values, $deviceId, $chamber, $chamberSource, $serial, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertImport(
        string $filename,
        string $fileHash,
        int $fileSize,
        string $encoding,
        ?string $archiveFilename,
        string $importedAt,
        string $parserVersion,
        int $recordCount,
        int $validRecordCount,
        int $warningCount,
        int $errorCount,
        string $status,
        ?string $failureReason = null,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO imports (filename, file_hash, file_size, encoding, archive_filename, imported_at, parser_version,'
            . ' record_count, valid_record_count, warning_count, error_count, status, failure_reason)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $filename,
            $fileHash,
            $fileSize,
            $encoding,
            $archiveFilename,
            $importedAt,
            $parserVersion,
            $recordCount,
            $validRecordCount,
            $warningCount,
            $errorCount,
            $status,
            $failureReason,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *     import_id: int,
     *     patient_id: ?int,
     *     device_id: ?int,
     *     session_timestamp: ?string,
     *     session_timestamp_raw: ?string,
     *     interrogation_timestamp: ?string,
     *     interrogation_timestamp_raw: ?string,
     *     report_version: int,
     *     parser_version: string,
     *     mapping_version: string,
     *     patient_name_snapshot: ?string,
     *     patient_identifier_snapshot: ?string,
     *     patient_dob_snapshot: ?string,
     *     device_manufacturer_snapshot: ?string,
     *     device_model_name_snapshot: ?string,
     *     device_model_number_snapshot: ?string,
     *     device_serial_snapshot: ?string,
     *     summary_snapshot: string,
     *     parameter_count: int,
     *     created_at: string
     * } $data
     */
    public function insertReport(array $data): int
    {
        $this->pdo->prepare(
            'INSERT INTO reports (import_id, patient_id, device_id, session_timestamp, session_timestamp_raw,'
            . ' interrogation_timestamp, interrogation_timestamp_raw, report_version, parser_version,'
            . ' mapping_version, patient_name_snapshot, patient_identifier_snapshot, patient_dob_snapshot,'
            . ' device_manufacturer_snapshot, device_model_name_snapshot, device_model_number_snapshot,'
            . ' device_serial_snapshot, summary_snapshot, parameter_count, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $data['import_id'],
            $data['patient_id'],
            $data['device_id'],
            $data['session_timestamp'],
            $data['session_timestamp_raw'],
            $data['interrogation_timestamp'],
            $data['interrogation_timestamp_raw'],
            $data['report_version'],
            $data['parser_version'],
            $data['mapping_version'],
            $data['patient_name_snapshot'],
            $data['patient_identifier_snapshot'],
            $data['patient_dob_snapshot'],
            $data['device_manufacturer_snapshot'],
            $data['device_model_name_snapshot'],
            $data['device_model_number_snapshot'],
            $data['device_serial_snapshot'],
            $data['summary_snapshot'],
            $data['parameter_count'],
            $data['created_at'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<int> $leadIds
     */
    public function linkLeads(int $reportId, array $leadIds): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO report_leads (report_id, lead_id) VALUES (?, ?)');
        foreach ($leadIds as $leadId) {
            $stmt->execute([$reportId, $leadId]);
        }
    }

    /**
     * @param list<array{
     *     source_parameter_id: string,
     *     source_name: string,
     *     category_key: string,
     *     mapping_source: string,
     *     mapping_version: string,
     *     standard_system: ?string,
     *     standard_code: ?string,
     *     occurrence_count: int
     * }> $definitions
     * @return array<string, int> Quell-ID => parameter_definitions.id
     */
    public function upsertParameterDefinitions(array $definitions, string $now): array
    {
        if ($definitions === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO parameter_definitions (source_parameter_id, source_name, category_key, mapping_source, mapping_version,'
            . ' standard_system, standard_code, occurrence_count, first_seen_at, last_seen_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS new'
            . ' ON DUPLICATE KEY UPDATE source_name = new.source_name, category_key = new.category_key,'
            . ' mapping_source = new.mapping_source, mapping_version = new.mapping_version,'
            . ' standard_system = new.standard_system, standard_code = new.standard_code,'
            . ' occurrence_count = parameter_definitions.occurrence_count + new.occurrence_count,'
            . ' last_seen_at = new.last_seen_at'
        );
        $sourceIds = [];
        foreach ($definitions as $def) {
            $stmt->execute([
                $def['source_parameter_id'],
                $def['source_name'],
                $def['category_key'],
                $def['mapping_source'],
                $def['mapping_version'],
                $def['standard_system'],
                $def['standard_code'],
                $def['occurrence_count'],
                $now,
                $now,
            ]);
            $sourceIds[] = $def['source_parameter_id'];
        }

        $ids = [];
        foreach (array_chunk($sourceIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $select = $this->pdo->prepare("SELECT id, source_parameter_id FROM parameter_definitions WHERE source_parameter_id IN ($placeholders)");
            $select->execute($chunk);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $ids[(string) $row['source_parameter_id']] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /**
     * @param list<list<int|string|null>> $rows
     */
    public function insertParameterChunk(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($rows), '(' . implode(',', array_fill(0, 14, '?')) . ')'));
        $this->pdo->prepare(
            "INSERT INTO report_parameters (report_id, parameter_definition_id, parameter_id, parameter_name, display_name,"
            . " value, unit, category, category_label, category_sort, mapping_source,"
            . " original_position, raw_record, created_at)"
            . " VALUES $placeholders"
        )->execute(array_merge(...$rows));
    }

    /**
     * @param list<ImportIssue> $issues
     */
    public function insertIssues(int $importId, array $issues, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_errors (import_id, severity, error_code, message, record_position, raw_record, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($issues as $issue) {
            $stmt->execute([$importId, $issue->severity, $issue->code, mb_substr($issue->message, 0, 1000), $issue->position, $issue->rawRecord, $now]);
        }
    }

    public function countReportParameters(int $reportId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM report_parameters WHERE report_id = ?');
        $stmt->execute([$reportId]);
        return (int) $stmt->fetchColumn();
    }
}
