<?php

declare(strict_types=1);

namespace App\Letter;

use App\Patient\PatientRecordService;
use App\Patient\PatientRecordType;
use App\PatientCard\PatientCardRepository;
use App\PatientCard\PatientName;
use App\Report\Pdf\ImageData;
use App\Report\ReportData;
use App\Report\ReportService;
use App\Security\FileName;
use App\Support\Clock;
use PDO;
use RuntimeException;

/**
 * Ablauf des Briefes zur Schrittmacher-/ICD-Abfrage.
 *
 * Regeln (Konzept §6):
 *  * Der Brief entsteht ausschliesslich aus vorhandenen Daten: Patient (Pflicht), optional ein
 *    Bericht als Befundteil und die aktuellen Fassungen der Bausteine.
 *  * Ohne beide Bestaetigungen wird weder ein Brief noch ein PDF erzeugt.
 *  * Der Brief ist unveraenderlich: Snapshot (JSON) und PDF (MEDIUMBLOB + SHA-256) werden
 *    gemeinsam in einer Transaktion gespeichert. Der Snapshot friert Patientendaten,
 *    Baustein-Fassungen, Berichtsdaten und Stammdatenfassung ein; das PDF ist daraus
 *    reproduzierbar.
 *  * Die MRT-Tauglichkeit wird nicht im Brief gepflegt, sondern aus dem neuesten
 *    Patientenausweis uebernommen und nur lesend gezeigt.
 */
final class LetterService
{
    /** Fassung des Brief-Snapshots. */
    public const int LETTER_VERSION = 1;

    /** Fassung der Briefvorlage (Aufbau und Reihenfolge der Abschnitte). */
    public const string LETTER_TEMPLATE_VERSION = '1.0';

    /** Bausteine, die in den Brieftext einfliessen. */
    public const array TEXT_TYPES = [
        PatientRecordType::Anamnesis,
        PatientRecordType::Premedication,
        PatientRecordType::Epicrisis,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LetterRepository $repository,
        private readonly PatientCardRepository $cards,
        private readonly PatientRecordService $records,
        private readonly ReportService $reports,
        private readonly DeviceCheckAppendix $appendix,
        private readonly LetterPdfGenerator $generator,
        private readonly Clock $clock,
    ) {
    }

    public static function pdfFilename(string $patientName, ?string $date, int $sequence): string
    {
        $parts = ['Brief', 'Schrittmacher-ICD-Abfrage', $patientName];
        if ($date !== null && $date !== '') {
            $parts[] = substr($date, 0, 10);
        }
        $parts[] = 'Nr' . $sequence;
        return FileName::downloadName(implode('_', $parts) . '.pdf');
    }

    // ------------------------------------------------------------------ Assistent

    /**
     * Patientenauswahl fuer Schritt 1 (Name, Patienten-ID oder Geburtsdatum).
     *
     * @return list<array<string, mixed>>
     */
    public function patientChoices(string $query, int $limit = 25): array
    {
        $result = $this->repository->searchPatients(['q' => $query], $limit, 0);
        return $result['rows'];
    }

    /**
     * Berichte eines Patienten fuer Schritt 2 (optionaler Befundteil).
     *
     * @return list<array<string, mixed>>
     */
    public function reportsFor(int $patientId): array
    {
        return $this->repository->reportsByPatient($patientId);
    }

    /**
     * Vollstaendige Ausgangsdaten fuer die Schritte 3 bis 5: aktueller Stand der Bausteine,
     * neuester Patientenausweis (MRT-Angabe) und die Anhangstabelle in der Fassung, die der
     * Brief erhalten wuerde.
     *
     * @return array{
     *     patient: array<string, mixed>,
     *     master_data: array<string, mixed>,
     *     report: ReportData|null,
     *     records: array<string, array<string, mixed>>,
     *     mrt: array<string, mixed>,
     *     appendix: array<string, mixed>,
     *     appendix_lines: list<string>,
     *     warnings: list<string>,
     *     next_sequence: int
     * }
     */
    public function prepare(int $patientId, ?int $reportId): array
    {
        $patient = self::withIdentity($this->repository->patient($patientId)
            ?? throw new RuntimeException('Der Patient wurde nicht gefunden.'));
        $masterData = $this->repository->masterData($patientId) ?? [];
        $report = $reportId === null ? null : $this->loadReport($patientId, $reportId);
        $records = $this->records->overview($patientId);
        $mrt = $this->mrtFromCard($patientId);
        $appendix = $this->appendixFor($records, $mrt);

        $warnings = [];
        $identityError = self::identityError($patient);
        if ($identityError !== null) {
            $warnings[] = $identityError;
        }
        foreach (self::TEXT_TYPES as $type) {
            if (($records[$type->value]['empty'] ?? true) === true) {
                $warnings[] = sprintf(
                    'Der Baustein „%s" ist für diesen Patienten noch nicht gefüllt; im Brief erscheint „nicht angegeben".',
                    $type->label(),
                );
            }
        }
        if (($appendix['sections'] ?? []) === []) {
            $warnings[] = 'Es liegt keine Schrittmacher-/ICD-Abfrage vor; der Brief erhält keinen Anhang.';
        }
        if ($report === null) {
            $warnings[] = 'Es ist kein Bericht ausgewählt; der Befundteil „Schrittmacher-/ICD-Abfrage" entfällt.';
        }
        if (($mrt['available'] ?? false) !== true) {
            $warnings[] = 'Im neuesten Patientenausweis ist keine MRT-Tauglichkeit angegeben; im Anhang erscheint die Angabe der Abfrage.';
        }

        return [
            'patient' => $patient,
            'master_data' => $masterData,
            'report' => $report,
            'records' => $records,
            'mrt' => $mrt,
            'appendix' => $appendix,
            'appendix_lines' => DeviceCheckAppendix::lines($appendix),
            'warnings' => $warnings,
            'next_sequence' => $this->repository->nextSequence($patientId),
        ];
    }

    public function loadReport(int $patientId, int $reportId): ReportData
    {
        $owner = $this->repository->reportPatientId($reportId);
        if ($owner !== null && $owner !== $patientId) {
            throw LetterException::rule('report_id', 'Der ausgewählte Bericht gehört zu einem anderen Patienten.');
        }
        return $this->reports->load($reportId)
            ?? throw LetterException::rule('report_id', 'Der ausgewählte Bericht wurde nicht gefunden.');
    }

    /**
     * Erzeugt den Brief: prueft die Bestaetigungen, friert den Stand ein, erzeugt das PDF und
     * speichert beides unveraenderlich.
     *
     * @return array{letter_id: int, patient_id: int, sequence_no: int, page_count: int}
     */
    public function create(LetterInput $input): array
    {
        $errors = [];
        if (!$input->confirmData) {
            $errors['confirm_data'] = 'Die Bestätigung „Ja, die Angaben sind geprüft und vollständig." ist erforderlich.';
        }
        if (!$input->confirmLetter) {
            $errors['confirm_letter'] = 'Die Bestätigung, dass der Brief erzeugt werden darf, ist erforderlich.';
        }
        if ($errors !== []) {
            throw LetterException::validation($errors);
        }

        $prepared = $this->prepare($input->patientId, $input->reportId);
        $patient = $prepared['patient'];
        $identityError = self::identityError($patient);
        if ($identityError !== null) {
            throw LetterException::rule('patient_id', $identityError);
        }
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $settingsVersionId = $this->ensureSettingsVersion($now);
            $settings = $this->withLogoMetadata($this->cards->settingsVersion($settingsVersionId) ?? []);
            $sequence = $this->repository->nextSequence($input->patientId);
            $letterVersion = $this->repository->nextLetterVersion($input->patientId, $input->reportId);
            $generatedAt = $this->clock->now();
            $documentNumber = $this->documentNumber($input->patientId, $sequence, $generatedAt);

            $snapshot = $this->snapshot($prepared, $settings, $settingsVersionId, $sequence, $letterVersion, $documentNumber, $generatedAt);
            $logo = $this->logoImage($settings);
            $pdf = $this->generator->generate($snapshot, $logo, $generatedAt);
            $patientName = (string) $patient['patient_name'];

            $letterId = $this->repository->insertLetter([
                'patient_id' => $input->patientId,
                'report_id' => $input->reportId,
                'settings_version_id' => $settingsVersionId,
                'sequence_no' => $sequence,
                'letter_version' => $letterVersion,
                'last_name' => $patient['last_name'],
                'first_name' => $patient['first_name'],
                'date_of_birth' => $patient['date_of_birth'],
                'patient_name' => $patientName,
                'letter_date' => $snapshot['document']['letter_date'],
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pdf_filename' => self::pdfFilename($patientName, $snapshot['document']['letter_date'], $sequence),
                'pdf_sha256' => hash('sha256', $pdf),
                'pdf_size' => strlen($pdf),
                'pdf_content' => $pdf,
                'created_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'letter_id' => $letterId,
            'patient_id' => $input->patientId,
            'sequence_no' => $sequence,
            'page_count' => LetterPdfGenerator::pageCount($pdf),
        ];
    }

    // ------------------------------------------------------------------- Auslesen

    /**
     * @return array<string, mixed>|null
     */
    public function letter(int $letterId, bool $withContent = false): ?array
    {
        $row = $this->repository->letter($letterId, $withContent);
        if ($row === null) {
            return null;
        }
        $snapshot = json_decode((string) $row['snapshot'], true);
        $row['snapshot'] = is_array($snapshot) ? $snapshot : [];
        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function letterForPatient(int $patientId, int $letterId): ?array
    {
        $letter = $this->letter($letterId);
        if ($letter === null || (int) $letter['patient_id'] !== $patientId) {
            return null;
        }
        return $letter;
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        return $this->repository->search($filters, $limit, $offset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lettersForPatient(int $patientId): array
    {
        return $this->repository->lettersByPatient($patientId);
    }

    public function pdfContent(int $letterId): ?string
    {
        $row = $this->repository->letter($letterId, true);
        return $row === null ? null : (string) $row['pdf_content'];
    }

    // ------------------------------------------------------------------- Snapshot

    /**
     * @param array<string, mixed> $prepared Ergebnis von prepare()
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function snapshot(
        array $prepared,
        array $settings,
        int $settingsVersionId,
        int $sequence,
        int $letterVersion,
        string $documentNumber,
        \DateTimeImmutable $generatedAt,
    ): array {
        $patient = $prepared['patient'];
        $masterData = (array) ($prepared['master_data'] ?? []);
        /** @var ReportData|null $report */
        $report = $prepared['report'];
        $records = $prepared['records'];
        $mrt = $prepared['mrt'];
        $appendix = $prepared['appendix'];

        return [
            'letter_version' => self::LETTER_VERSION,
            'letter_template_version' => self::LETTER_TEMPLATE_VERSION,
            'generated_at' => $this->now(),
            'sequence_no' => $sequence,
            'document' => [
                'document_number' => $documentNumber,
                'letter_date' => $generatedAt->format('Y-m-d'),
                'created_at' => $this->now(),
            ],
            'master' => [
                'settings_version_id' => $settingsVersionId,
                'settings_version' => $settingsVersionId,
                'center_name' => (string) ($settings['center_name'] ?? ''),
                'center_address' => (string) ($settings['center_address'] ?? ''),
                'logo_sha256' => (string) ($settings['logo_sha256'] ?? ''),
                'logo_filename' => (string) ($settings['logo_filename'] ?? ''),
            ],
            'patient' => [
                'patient_id' => (int) $patient['id'],
                'patient_name' => (string) $patient['patient_name'],
                'last_name' => (string) $patient['last_name'],
                'first_name' => (string) $patient['first_name'],
                'date_of_birth' => $patient['date_of_birth'],
                'patient_identifier' => (string) ($patient['patient_identifier'] ?? ''),
                'address' => [
                    'street' => (string) ($masterData['street'] ?? ''),
                    'postal_code' => (string) ($masterData['postal_code'] ?? ''),
                    'city' => (string) ($masterData['city'] ?? ''),
                    'phone' => (string) ($masterData['phone'] ?? ''),
                ],
            ],
            'anamnesis' => $this->recordPart($records[PatientRecordType::Anamnesis->value] ?? null),
            'premedication' => $this->recordPart($records[PatientRecordType::Premedication->value] ?? null),
            'epicrisis' => $this->recordPart($records[PatientRecordType::Epicrisis->value] ?? null),
            'device_check' => $this->recordPart($records[PatientRecordType::DeviceCheck->value] ?? null),
            'report' => $report === null ? null : $this->reportPart($report),
            'appendix' => $appendix,
            'mrt' => $mrt,
            'source' => [
                'patient_id' => (int) $patient['id'],
                'report_id' => $report?->id(),
                'settings_version_id' => $settingsVersionId,
                'card_id' => $mrt['card_id'] ?? null,
                'record_versions' => $this->recordVersions($records),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $presented
     * @return array<string, mixed>
     */
    private function recordPart(?array $presented): array
    {
        if ($presented === null) {
            return [
                'present' => false,
                'record_id' => null,
                'version' => null,
                'version_created_at' => null,
                'author_name' => '',
                'text' => '',
                'entries' => [],
            ];
        }
        return [
            'present' => true,
            'record_id' => $presented['record_id'] ?? null,
            'version' => $presented['version'] ?? null,
            'version_created_at' => $presented['version_created_at'] ?? null,
            'author_name' => (string) ($presented['author_name'] ?? ''),
            'text' => (string) ($presented['text'] ?? ''),
            'entries' => array_values(array_filter((array) ($presented['entries'] ?? []), 'is_array')),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @return array<string, array{record_id: int|null, version: int|null}>
     */
    private function recordVersions(array $records): array
    {
        $versions = [];
        foreach ($records as $type => $record) {
            $versions[$type] = [
                'record_id' => $record['record_id'] ?? null,
                'version' => $record['version'] ?? null,
            ];
        }
        return $versions;
    }

    /**
     * Befundteil aus dem Bericht: Geraet, Sonden und die Messwerte je Kategorie.
     *
     * @return array<string, mixed>
     */
    private function reportPart(ReportData $report): array
    {
        $rows = [];
        foreach ($report->summarySection('device') as $row) {
            $value = $this->withUnit((string) ($row['value'] ?? ''), (string) ($row['unit'] ?? ''));
            if ($value !== '') {
                $rows[] = ['label' => (string) $row['label'], 'value' => $value];
            }
        }

        $leads = [];
        foreach ($report->leads() as $lead) {
            $leadRows = [];
            foreach ([
                ['Hersteller', 'manufacturer'],
                ['Modell', 'model_label'],
                ['Modellnummer', 'model_number'],
                ['Seriennummer', 'serial_number'],
                ['Implantation', 'implant_date_display'],
            ] as [$label, $key]) {
                $value = trim((string) ($lead[$key] ?? ''));
                if ($value !== '') {
                    $leadRows[] = ['label' => $label, 'value' => $value];
                }
            }
            if ($leadRows === []) {
                continue;
            }
            $leads[] = ['label' => 'Sonde ' . (string) ($lead['chamber_label'] ?? ''), 'rows' => $leadRows];
        }

        $groups = [];
        foreach ($report->categories() as $category) {
            $groupRows = [];
            foreach ($category['parameters'] as $parameter) {
                $name = trim((string) ($parameter['display_name'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($parameter['parameter_name'] ?? ''));
                }
                $value = $this->withUnit((string) ($parameter['value'] ?? ''), (string) ($parameter['unit'] ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }
                $groupRows[] = ['label' => $name, 'value' => $value];
            }
            if ($groupRows === []) {
                continue;
            }
            $groups[] = ['label' => (string) $category['label'], 'rows' => $groupRows];
        }

        $date = $this->reportDate($report);
        $meta = sprintf(
            'Bericht Nr. %d%s · Sitzung %s · Datei %s',
            $report->id(),
            $date === null ? '' : ' vom ' . $this->displayDate($date),
            $this->displayDateTime((string) ($report->report['session_timestamp'] ?? ''), true),
            (string) ($report->import['filename'] ?? 'unbekannt'),
        );

        return [
            'report_id' => $report->id(),
            'report_version' => $report->reportVersion(),
            'meta' => $meta,
            'rows' => $rows,
            'leads' => $leads,
            'groups' => $groups,
            'filled' => count($rows) + array_sum(array_map(static fn (array $lead): int => count($lead['rows']), $leads))
                + array_sum(array_map(static fn (array $group): int => count($group['rows']), $groups)),
        ];
    }

    /**
     * Anhangstabelle der aktuellen Abfrage mit der MRT-Angabe des neuesten Patientenausweises.
     *
     * @param array<string, array<string, mixed>> $records
     * @param array<string, mixed> $mrt
     * @return array<string, mixed>
     */
    private function appendixFor(array $records, array $mrt): array
    {
        $deviceCheck = $records[PatientRecordType::DeviceCheck->value]['device_check'] ?? null;
        if (!is_array($deviceCheck)) {
            return [
                'present' => false,
                'device_type' => '',
                'device_type_label' => '',
                'sections' => [],
                'notes' => '',
                'filled' => 0,
                'mrt_label' => '',
            ];
        }
        $override = ($mrt['available'] ?? false) === true
            ? ['value' => (string) $mrt['value'], 'note' => (string) $mrt['note'], 'source_label' => (string) $mrt['source_label']]
            : null;
        $appendix = $this->appendix->build($deviceCheck, $override);

        return [
            'present' => $appendix['sections'] !== [] || $appendix['notes'] !== '',
            'device_type' => $appendix['device_type'],
            'device_type_label' => $appendix['device_type_label'],
            'sections' => $appendix['sections'],
            'notes' => $appendix['notes'],
            'filled' => $appendix['filled'],
            'mrt_label' => $override === null ? '' : 'MRT-Tauglichkeit ' . (string) $mrt['source_label'],
        ];
    }

    /**
     * MRT-Angabe aus dem neuesten Patientenausweis (eine Quelle, nur lesend).
     *
     * @return array<string, mixed>
     */
    private function mrtFromCard(int $patientId): array
    {
        $card = $this->repository->latestCard($patientId);
        $empty = [
            'available' => false,
            'value' => '',
            'note' => '',
            'source_label' => '',
            'card_id' => null,
            'card_sequence' => null,
            'card_created_at' => null,
        ];
        if ($card === null) {
            return $empty;
        }
        $snapshot = json_decode((string) $card['snapshot'], true);
        $device = is_array($snapshot) ? ($snapshot['device'] ?? []) : [];
        $value = trim((string) ($device['mrt_compatibility'] ?? ''));
        if ($value === '') {
            return $empty;
        }
        return [
            'available' => true,
            'value' => $value,
            'note' => trim((string) ($device['mrt_compatibility_note'] ?? '')),
            'source_label' => sprintf('aus Patientenausweis Nr. %d', (int) $card['sequence_no']),
            'card_id' => (int) $card['id'],
            'card_sequence' => (int) $card['sequence_no'],
            'card_created_at' => (string) $card['created_at'],
        ];
    }

    // ------------------------------------------------------------------- Hilfen

    /**
     * Ergaenzt fehlende Nach-/Vornamen aus patient_name ("NACHNAME, VORNAME"). Patienten aus
     * Importen vor Migration 002 haben nur patient_name; last_name/first_name sind dort NULL.
     *
     * @param array<string, mixed> $patient
     * @return array<string, mixed>
     */
    private static function withIdentity(array $patient): array
    {
        $parts = PatientName::split(isset($patient['patient_name']) ? (string) $patient['patient_name'] : null);
        $last = PatientName::normalize(isset($patient['last_name']) ? (string) $patient['last_name'] : null) ?? $parts['last'];
        $first = PatientName::normalize(isset($patient['first_name']) ? (string) $patient['first_name'] : null) ?? $parts['first'];
        $patient['last_name'] = $last;
        $patient['first_name'] = $first ?? '';
        $name = trim((string) ($patient['patient_name'] ?? ''));
        $patient['patient_name'] = $name !== '' ? $name : PatientName::display($last, $first);
        return $patient;
    }

    /**
     * @param array<string, mixed> $patient Ergebnis von withIdentity()
     */
    private static function identityError(array $patient): ?string
    {
        $missing = [];
        if (($patient['last_name'] ?? null) === null) {
            $missing[] = 'Nachname';
        }
        if (($patient['date_of_birth'] ?? null) === null || $patient['date_of_birth'] === '') {
            $missing[] = 'Geburtsdatum';
        }
        if ($missing === []) {
            return null;
        }
        return sprintf(
            'In den Stammdaten des Patienten fehlt: %s. Bitte zuerst in der Patientenakte ergänzen (Stammdaten bearbeiten).',
            implode(', ', $missing),
        );
    }

    private function documentNumber(int $patientId, int $sequence, \DateTimeImmutable $generatedAt): string
    {
        return sprintf('HSM2Med-Brief-%s-%06d-%03d', $generatedAt->format('Ymd'), $patientId, $sequence);
    }

    private function ensureSettingsVersion(string $now): int
    {
        $current = $this->cards->currentSettingsVersionId();
        if ($current !== null) {
            return $current;
        }
        $settings = $this->cards->settings() ?? [];
        return $this->cards->saveSettings([
            'center_name' => $settings['center_name'] ?? null,
            'center_address' => $settings['center_address'] ?? null,
            'notice_text' => $settings['notice_text'] ?? null,
            'flight_notice_de' => $settings['flight_notice_de'] ?? null,
            'flight_notice_en' => $settings['flight_notice_en'] ?? null,
            'logo_id' => $settings['logo_id'] ?? null,
        ], $now);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function withLogoMetadata(array $settings): array
    {
        $settings['logo_sha256'] = '';
        $settings['logo_filename'] = '';
        $logoId = $settings['logo_id'] ?? null;
        if ($logoId === null) {
            return $settings;
        }
        $logo = $this->cards->logo((int) $logoId);
        if ($logo !== null) {
            $settings['logo_sha256'] = (string) $logo['sha256'];
            $settings['logo_filename'] = (string) $logo['filename'];
        }
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function logoImage(array $settings): ?ImageData
    {
        $logoId = $settings['logo_id'] ?? null;
        if ($logoId === null) {
            return null;
        }
        $logo = $this->cards->logo((int) $logoId);
        if ($logo === null) {
            return null;
        }
        try {
            return ImageData::fromBytes((string) $logo['content']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function reportDate(ReportData $report): ?string
    {
        $value = $report->report['session_timestamp']
            ?? $report->report['interrogation_timestamp']
            ?? $report->report['created_at']
            ?? null;
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private function withUnit(string $value, string $unit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $unit = trim($unit);
        return $unit === '' ? $value : $value . ' ' . $unit;
    }

    private function displayDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('d.m.Y', $timestamp);
    }

    private function displayDateTime(string $value, bool $dateOnly = false): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unbekannt';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date($dateOnly ? 'd.m.Y' : 'd.m.Y H:i', $timestamp);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
