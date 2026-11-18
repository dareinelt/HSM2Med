<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\ParameterMapping;
use App\Patient\PatientRepository;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientName;
use App\Report\ReportSummary;
use App\Report\ReportSummaryBuilder;
use App\Repository\ImportRepository;
use App\Security\FileName;
use App\Support\Clock;
use App\Support\Logger;
use App\Support\SourceDate;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Analysiert Exportdateien (Merlin-Text, Biotronik-XML) und speichert sie atomar
 * (eine Transaktion) als Bericht-Snapshot.
 */
class ImportService
{
    /** Version des Berichts-Snapshots (Struktur von reports/report_parameters/summary_snapshot). */
    public const int REPORT_VERSION = 1;

    private const int PARAMETER_CHUNK_SIZE = 100;

    private readonly ImportRepository $imports;
    private readonly PatientRepository $patients;

    public function __construct(
        protected readonly PDO $pdo,
        private readonly ParserChain $parser,
        private readonly ImportValidator $validator,
        private readonly ParameterMapping $mapping,
        private readonly ReportSummaryBuilder $summaryBuilder,
        private readonly Clock $clock,
        private readonly ?ImportArchive $archive = null,
        private readonly ?Logger $logger = null,
        ?ImportRepository $imports = null,
        ?PatientRepository $patients = null,
    ) {
        $this->imports = $imports ?? new ImportRepository($this->pdo);
        $this->patients = $patients ?? new PatientRepository($this->pdo);
    }

    /**
     * @param ?int $activePatientId Aktiver Patient der Sitzung; wird nur dann automatisch
     *                              verwendet, wenn die Datei keinerlei Patientendaten enthaelt.
     */
    public function analyze(string $bytes, string $originalFilename, ?int $activePatientId = null): ImportAnalysis
    {
        $parser = $this->parser->forBytes($bytes, $originalFilename);
        $result = $parser->parse($bytes);
        $assignments = [];
        foreach ($result->records as $record) {
            $assignments[$record->position] = $this->mapping->resolve($record->parameterId, $record->name);
        }
        $summary = $this->summaryBuilder->build($result->records);
        $hash = hash('sha256', $bytes);
        $patientResolution = $this->resolvePatient($summary, $activePatientId, false);

        return new ImportAnalysis(
            filename: FileName::sanitize($originalFilename),
            fileSize: strlen($bytes),
            fileHash: $hash,
            parserName: $parser->name(),
            parserVersion: $parser->version(),
            parseResult: $result,
            assignments: $assignments,
            summary: $summary,
            validation: $this->validator->validate($result, $summary, $patientResolution->activePatient !== null),
            previousImports: $this->findImportsByHash($hash),
            patientResolution: $patientResolution,
        );
    }

    /**
     * @return list<array{id: int, imported_at: string, status: string, report_id: ?int}>
     */
    public function findImportsByHash(string $hash): array
    {
        return $this->imports->findByHash($hash);
    }

    /**
     * @param ?int $activePatientId Aktiver Patient der Sitzung.
     * @param ?string $selectedPatientTarget Vom Benutzer bestaetigte/ausgewaehlte Zuordnung
     *                                       ('patient:<id>', 'new' oder 'none').
     */
    public function import(
        ImportAnalysis $analysis,
        string $bytes,
        bool $allowDuplicate = false,
        ?int $activePatientId = null,
        ?string $selectedPatientTarget = null,
    ): ImportOutcome {
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

        $effectiveTarget = $this->verifyTargetBeforeTransaction($analysis->patientResolution, $selectedPatientTarget);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $summary = $analysis->summary;

        try {
            $this->pdo->beginTransaction();

            $patientId = $this->upsertPatient($summary, $now, $activePatientId, $effectiveTarget);
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
        } catch (PatientResolutionException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
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

    private function verifyTargetBeforeTransaction(PatientResolution $resolution, ?string $selectedTarget): string
    {
        if ($selectedTarget !== null) {
            if ($selectedTarget === '') {
                throw new PatientResolutionException(
                    $resolution->requiresDecision()
                        ? 'Bitte wählen Sie aus, welchem Patienten der Bericht zugeordnet werden soll.'
                        : 'Die Bestätigung des Zielpatienten fehlt. Bitte prüfen und bestätigen Sie die Importübersicht erneut.',
                    $resolution,
                );
            }
            if (!$resolution->accepts($selectedTarget)) {
                throw new PatientResolutionException(
                    $resolution->requiresDecision()
                        ? 'Die gewählte Patientenzuordnung ist ungültig oder nicht mehr aktuell. Bitte wählen Sie den Zielpatienten erneut aus.'
                        : 'Die Patientenzuordnung hat sich seit der Vorschau geändert (z. B. durch Wechsel des aktiven Patienten). Bitte prüfen und bestätigen Sie die Importübersicht erneut.',
                    $resolution,
                );
            }
            return $selectedTarget;
        }

        if ($resolution->requiresDecision() || $resolution->defaultTarget === null) {
            throw new PatientResolutionException($resolution->reason, $resolution);
        }

        return $resolution->defaultTarget;
    }

    /**
     * Ermittelt die Patientenzuordnung anhand der harten Identitaetsregel
     * (Nachname + Vorname + Geburtsdatum). Bei Mehrdeutigkeit, Teil-Identitaet oder
     * abweichender Patienten-ID wird niemals automatisch zugeordnet.
     */
    private function resolvePatient(ReportSummary $summary, ?int $activePatientId, bool $forUpdate): PatientResolution
    {
        $activeRow = $activePatientId === null ? null : $this->patients->patient($activePatientId, $forUpdate);
        $activeInfo = $activeRow === null ? null : self::patientInfo($activeRow);

        $identifier = $summary->nonEmpty('patient_identifier');
        $name = $summary->nonEmpty('patient_name');
        $dobRaw = $summary->nonEmpty('patient_dob');
        $dob = SourceDate::parse($dobRaw)?->format('Y-m-d');
        $parts = PatientName::split($name);
        $identityKey = PatientName::identityKey($parts['last'], $parts['first'], $dob);
        $hasFileData = $identifier !== null || $name !== null || $dobRaw !== null;

        if (!$hasFileData) {
            if ($activeInfo !== null) {
                $target = 'patient:' . $activeInfo['id'];
                return new PatientResolution(
                    mode: PatientResolutionMode::ActivePatientFallback,
                    defaultTarget: $target,
                    summary: sprintf('Patient Nr. %d – %s', $activeInfo['id'], $activeInfo['name']),
                    reason: 'Aktiver Patient der Sitzung (die Datei enthält keinerlei Patientendaten)',
                    options: [self::patientOption($activeInfo, true)],
                    targetPatient: $activeInfo,
                    activePatient: $activeInfo,
                );
            }
            return PatientResolution::unassigned();
        }

        if ($identityKey !== null) {
            $matches = array_map(
                self::patientInfo(...),
                $this->patients->findPatientsByIdentity($identityKey, $forUpdate),
            );
            if (count($matches) === 1) {
                $match = $matches[0];
                $target = 'patient:' . $match['id'];
                $notice = ($activeInfo !== null && $activeInfo['id'] !== $match['id'])
                    ? sprintf(
                        'Weicht vom aktiven Patienten (Nr. %d – %s) ab; maßgeblich ist die vollständige Identität in der Datei.',
                        $activeInfo['id'],
                        $activeInfo['name'],
                    )
                    : null;
                return new PatientResolution(
                    mode: PatientResolutionMode::IdentitySingle,
                    defaultTarget: $target,
                    summary: sprintf('Patient Nr. %d – %s', $match['id'], $match['name']),
                    reason: 'Eindeutiger Treffer über Nachname, Vorname und Geburtsdatum',
                    options: [self::patientOption($match, $activeInfo !== null && $activeInfo['id'] === $match['id'])],
                    targetPatient: $match,
                    activePatient: $activeInfo,
                    notice: $notice,
                );
            }

            if (count($matches) > 1) {
                $options = array_map(
                    static fn (array $candidate): PatientResolutionOption => self::patientOption(
                        $candidate,
                        $activeInfo !== null && $activeInfo['id'] === $candidate['id'],
                    ),
                    $matches,
                );
                return new PatientResolution(
                    mode: PatientResolutionMode::IdentityMultiple,
                    defaultTarget: null,
                    summary: 'Mehrere Patienten mit gleicher Identität – Auswahl erforderlich',
                    reason: 'Zu Nachname, Vorname und Geburtsdatum existieren mehrere Patienten. Bitte wählen Sie den Zielpatienten ausdrücklich aus.',
                    options: $options,
                    activePatient: $activeInfo,
                );
            }

            $existingByIdRow = $identifier === null ? null : $this->patients->findPatientByIdentifier($identifier, $forUpdate);
            if ($existingByIdRow === null) {
                $dobDisplay = SourceDate::display($dobRaw, true);
                $detail = 'Geburtsdatum ' . $dobDisplay . ($identifier !== null ? ' · Patient-ID ' . $identifier : '');
                $notice = $activeInfo !== null
                    ? sprintf(
                        'Weicht vom aktiven Patienten (Nr. %d – %s) ab; es wird ein eigener Patient angelegt.',
                        $activeInfo['id'],
                        $activeInfo['name'],
                    )
                    : null;
                return new PatientResolution(
                    mode: PatientResolutionMode::NewPatient,
                    defaultTarget: 'new',
                    summary: sprintf('Neuer Patient wird angelegt: %s', (string) $name),
                    reason: 'Kein vorhandener Patient mit dieser Identität (Nachname, Vorname, Geburtsdatum)',
                    options: [new PatientResolutionOption(target: 'new', label: sprintf('Neuen Patienten anlegen (%s)', (string) $name), detail: $detail)],
                    activePatient: $activeInfo,
                    notice: $notice,
                );
            }

            $conflictInfo = self::patientInfo($existingByIdRow);
            $options = [
                new PatientResolutionOption(
                    target: 'new',
                    label: sprintf('Neuen Patienten aus den Dateiangaben anlegen (%s)', (string) $name),
                    detail: sprintf(
                        'Geburtsdatum %s · die bereits vergebene Patienten-ID „%s“ verbleibt bei Patient Nr. %d (bleibt im Berichts-Snapshot erhalten)',
                        SourceDate::display($dobRaw, true),
                        (string) $identifier,
                        $conflictInfo['id'],
                    ),
                ),
                self::patientOption(
                    $conflictInfo,
                    $activeInfo !== null && $activeInfo['id'] === $conflictInfo['id'],
                    sprintf('vorhandener Patient mit Patienten-ID „%s“', (string) $identifier),
                ),
            ];
            if ($activeInfo !== null && $activeInfo['id'] !== $conflictInfo['id']) {
                $options[] = self::patientOption($activeInfo, true);
            }

            return new PatientResolution(
                mode: PatientResolutionMode::IdentifierConflict,
                defaultTarget: null,
                summary: 'Konflikt bei der Patienten-ID – Entscheidung erforderlich',
                reason: sprintf(
                    'Die Patienten-ID „%s“ gehört bereits zu Patient Nr. %d (%s), dessen Identitätsangaben von der Datei abweichen. Die Patienten-ID allein dient nicht als Zuordnungsmerkmal.',
                    (string) $identifier,
                    $conflictInfo['id'],
                    $conflictInfo['name'],
                ),
                options: $options,
                activePatient: $activeInfo,
                conflictingPatient: $conflictInfo,
            );
        }

        // Teil-Identitaet in der Datei ($hasFileData && $identityKey === null):
        $existingByIdRow = $identifier === null ? null : $this->patients->findPatientByIdentifier($identifier, $forUpdate);
        $conflictInfo = $existingByIdRow === null ? null : self::patientInfo($existingByIdRow);

        $options = [];
        if ($activeInfo !== null) {
            $options[] = self::patientOption($activeInfo, true);
        }
        if ($conflictInfo !== null && ($activeInfo === null || $activeInfo['id'] !== $conflictInfo['id'])) {
            $options[] = self::patientOption(
                $conflictInfo,
                false,
                sprintf('vorhandener Patient mit Patienten-ID „%s“', (string) $identifier),
            );
        }

        $canCreateNew = $name !== null || ($identifier !== null && $conflictInfo === null);
        if ($canCreateNew) {
            $newLabel = $name !== null
                ? sprintf('Neuen Patienten aus den Dateiangaben anlegen (%s)', $name)
                : sprintf('Neuen Patienten mit Patienten-ID „%s“ anlegen', (string) $identifier);
            $newDetails = [];
            if ($dobRaw !== null) {
                $newDetails[] = 'Geburtsdatum ' . SourceDate::display($dobRaw, true);
            }
            if ($identifier !== null) {
                $newDetails[] = $conflictInfo === null
                    ? 'Patient-ID ' . $identifier
                    : sprintf('ohne Übernahme der bereits an Patient Nr. %d vergebenen Patienten-ID „%s“', $conflictInfo['id'], $identifier);
            }
            $options[] = new PatientResolutionOption(
                target: 'new',
                label: $newLabel,
                detail: implode(' · ', $newDetails),
            );
        } else {
            $options[] = new PatientResolutionOption(
                target: 'none',
                label: 'Ohne Patientenzuordnung speichern',
                detail: 'Die Datei enthält weder einen Namen noch eine freie Patienten-ID für einen neuen Datensatz.',
            );
        }

        return new PatientResolution(
            mode: PatientResolutionMode::IncompleteIdentity,
            defaultTarget: null,
            summary: 'Unvollständige Patientenidentität – Auswahl erforderlich',
            reason: 'Die Datei enthält Patientenangaben, aber keine vollständige Identität (Nachname, Vorname und gültiges Geburtsdatum). Bitte wählen Sie die gewünschte Zuordnung ausdrücklich aus.',
            options: $options,
            activePatient: $activeInfo,
            conflictingPatient: $conflictInfo,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, date_of_birth: ?string, identifier: ?string}
     */
    private static function patientInfo(array $row): array
    {
        $name = trim((string) ($row['patient_name'] ?? ''));
        $dob = $row['date_of_birth'] !== null && trim((string) $row['date_of_birth']) !== ''
            ? (string) $row['date_of_birth']
            : null;
        $identifier = $row['patient_identifier'] !== null && trim((string) $row['patient_identifier']) !== ''
            ? (string) $row['patient_identifier']
            : null;
        return [
            'id' => (int) $row['id'],
            'name' => $name !== '' ? $name : 'ohne Namen',
            'date_of_birth' => $dob,
            'identifier' => $identifier,
        ];
    }

    /**
     * @param array{id: int, name: string, date_of_birth: ?string, identifier: ?string} $patient
     */
    private static function patientOption(array $patient, bool $isActive, string $extra = ''): PatientResolutionOption
    {
        $details = [];
        if ($patient['date_of_birth'] !== null) {
            $details[] = 'geb. ' . PatientCardInput::formatDate($patient['date_of_birth']);
        }
        if ($patient['identifier'] !== null) {
            $details[] = 'Patient-ID ' . $patient['identifier'];
        }
        if ($extra !== '') {
            $details[] = $extra;
        }
        return new PatientResolutionOption(
            target: 'patient:' . $patient['id'],
            label: sprintf('Patient Nr. %d – %s', $patient['id'], $patient['name']),
            detail: implode(' · ', $details),
            patientId: $patient['id'],
            isActivePatient: $isActive,
        );
    }

    protected function upsertPatient(
        ReportSummary $summary,
        string $now,
        ?int $activePatientId = null,
        ?string $selectedTarget = null,
    ): ?int {
        $resolution = $this->resolvePatient($summary, $activePatientId, true);
        $target = $selectedTarget ?? $resolution->defaultTarget;
        if ($target === null || !$resolution->accepts($target)) {
            throw new PatientResolutionException(
                'Die Patientenzuordnung hat sich zwischenzeitlich geändert oder erfordert eine Auswahl. Bitte prüfen Sie die Importübersicht erneut.',
                $resolution,
            );
        }

        if ($target === 'none') {
            return null;
        }

        $identifier = $summary->nonEmpty('patient_identifier');
        $name = $summary->nonEmpty('patient_name');
        $dobRaw = $summary->nonEmpty('patient_dob');
        $dob = SourceDate::parse($dobRaw)?->format('Y-m-d');
        $parts = PatientName::split($name);

        if (str_starts_with($target, 'patient:')) {
            $patientId = (int) substr($target, 8);
            if ($this->patients->patient($patientId, true) === null) {
                throw new PatientResolutionException('Der gewählte Zielpatient existiert nicht mehr.', $resolution);
            }
            if ($name !== null || $dobRaw !== null) {
                $this->patients->supplementPatientFromImport(
                    $patientId,
                    $name,
                    $parts['last'],
                    $parts['first'],
                    $dob,
                    $dobRaw,
                    $now,
                );
            }
            return $patientId;
        }

        if ($target === 'new') {
            $identifierToStore = ($identifier !== null && !$this->patients->patientIdentifierInUse($identifier))
                ? $identifier
                : null;
            if ($identifierToStore === null && $name === null) {
                return null;
            }
            return $this->patients->createImportedPatient(
                $identifierToStore,
                $name,
                $parts['last'],
                $parts['first'],
                $dob,
                $dobRaw,
                $now,
            );
        }

        throw new PatientResolutionException('Unbekannte Patientenzuordnung.', $resolution);
    }

    protected function upsertDevice(ReportSummary $summary, ?int $patientId, string $now): ?int
    {
        $serial = $summary->nonEmpty('device_serial');
        if ($serial === null) {
            return null;
        }
        $implantRaw = $summary->nonEmpty('device_implant_date');
        return $this->imports->upsertDevice(
            serial: $serial,
            modelNumber: $summary->nonEmpty('device_model_number'),
            patientId: $patientId,
            manufacturer: $summary->nonEmpty('device_manufacturer'),
            modelName: $summary->nonEmpty('device_model_name'),
            implantDate: SourceDate::parse($implantRaw)?->format('Y-m-d'),
            implantDateRaw: $implantRaw,
            now: $now,
        );
    }

    /**
     * @return list<int>
     */
    protected function upsertLeads(ReportSummary $summary, int $deviceId, string $now): array
    {
        $ids = [];
        foreach ($summary->leads as $lead) {
            $v = static fn (string $key): ?string => ($lead[$key] === null || trim((string) $lead[$key]) === '') ? null : (string) $lead[$key];
            $implantRaw = $v('implant_date');
            $ids[] = $this->imports->upsertLead(
                deviceId: $deviceId,
                chamber: (string) $lead['chamber'],
                chamberSource: (string) $lead['chamber_source'],
                serial: $v('serial_number'),
                manufacturer: $v('manufacturer'),
                modelLabel: $v('model_label'),
                modelNumber: $v('model_number'),
                leadType: $v('lead_type'),
                implantDate: SourceDate::parse($implantRaw)?->format('Y-m-d'),
                implantDateRaw: $implantRaw,
                now: $now,
            );
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
        return $this->imports->insertImport(
            filename: $analysis->filename,
            fileHash: $analysis->fileHash,
            fileSize: $analysis->fileSize,
            encoding: $analysis->parseResult->encoding,
            archiveFilename: $archiveFilename,
            importedAt: $now,
            parserVersion: $analysis->parserVersion,
            recordCount: $analysis->parseResult->recordCount,
            validRecordCount: $analysis->parseResult->validRecordCount(),
            warningCount: $analysis->warningCount(),
            errorCount: $analysis->errorCount(),
            status: $analysis->status(),
        );
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

        return $this->imports->insertReport([
            'import_id' => $importId,
            'patient_id' => $patientId,
            'device_id' => $deviceId,
            'session_timestamp' => SourceDate::parse($sessionRaw)?->format('Y-m-d H:i:s'),
            'session_timestamp_raw' => $sessionRaw,
            'interrogation_timestamp' => SourceDate::parse($interrogationRaw)?->format('Y-m-d H:i:s'),
            'interrogation_timestamp_raw' => $interrogationRaw,
            'report_version' => self::REPORT_VERSION,
            'parser_version' => $analysis->parserVersion,
            'mapping_version' => $this->mapping->version(),
            'patient_name_snapshot' => $s->value('patient_name'),
            'patient_identifier_snapshot' => $s->value('patient_identifier'),
            'patient_dob_snapshot' => $s->value('patient_dob'),
            'device_manufacturer_snapshot' => $s->value('device_manufacturer'),
            'device_model_name_snapshot' => $s->value('device_model_name'),
            'device_model_number_snapshot' => $s->value('device_model_number'),
            'device_serial_snapshot' => $s->value('device_serial'),
            'summary_snapshot' => $snapshot,
            'parameter_count' => $analysis->parseResult->validRecordCount(),
            'created_at' => $now,
        ]);
    }

    /**
     * @param list<int> $leadIds
     */
    protected function linkLeads(int $reportId, array $leadIds): void
    {
        $this->imports->linkLeads($reportId, $leadIds);
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

        $definitions = [];
        foreach ($firstRecord as $parameterId => $record) {
            $assignment = $analysis->assignments[$record->position];
            $standard = $this->mapping->standardCode((string) $parameterId);
            $definitions[] = [
                'source_parameter_id' => (string) $parameterId,
                'source_name' => $record->name,
                'category_key' => $assignment->key,
                'mapping_source' => $assignment->source,
                'mapping_version' => $this->mapping->version(),
                'standard_system' => $standard['system'] ?? null,
                'standard_code' => $standard['code'] ?? null,
                'occurrence_count' => $occurrences[$parameterId],
            ];
        }

        return $this->imports->upsertParameterDefinitions($definitions, $now);
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
        $this->imports->insertParameterChunk($rows);
    }

    /**
     * @param list<ImportIssue> $issues
     */
    protected function insertIssues(int $importId, array $issues, string $now): void
    {
        $this->imports->insertIssues($importId, $issues, $now);
    }

    protected function verifyParameterCount(int $reportId, int $expected): void
    {
        $actual = $this->imports->countReportParameters($reportId);
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
            $importId = $this->imports->insertImport(
                filename: $analysis->filename,
                fileHash: $analysis->fileHash,
                fileSize: $analysis->fileSize,
                encoding: $analysis->parseResult->encoding,
                archiveFilename: null,
                importedAt: $now,
                parserVersion: $analysis->parserVersion,
                recordCount: $analysis->parseResult->recordCount,
                validRecordCount: $analysis->parseResult->validRecordCount(),
                warningCount: $analysis->warningCount(),
                errorCount: $analysis->errorCount(),
                status: 'failed',
                failureReason: mb_substr($reason, 0, 500),
            );
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
