<?php

declare(strict_types=1);

namespace App\PatientCard;

use App\Report\Pdf\ImageData;
use App\Report\ReportData;
use App\Report\ReportService;
use App\Security\FileName;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Ablauf des Patientenausweises: Identitaet bestimmen, Daten zusammenfuehren, Ausweis erzeugen.
 *
 * Regeln:
 *  * Die Identitaet ist Nachname + Vorname + Geburtsdatum. Bei mehreren Treffern wird nie
 *    automatisch entschieden; der Benutzer waehlt den Patienten aus.
 *  * Vorhandene Angaben werden vorgeschlagen, aber nie still ueberschrieben: Abweichungen
 *    erscheinen als Konflikt und werden je Feld entschieden.
 *  * Ohne beide Bestaetigungen wird weder ein Ausweis noch ein PDF erzeugt.
 *  * Der Ausweis ist unveraenderlich: Snapshot (JSON) und PDF (MEDIUMBLOB + SHA-256) werden
 *    gemeinsam in einer Transaktion gespeichert.
 */
final class PatientCardService
{
    public const string PATIENT_CARD_VERSION = '1.0';
    public const int CARD_VERSION = 1;

    /** Anzahl der auf Seite 2 dargestellten vergangenen Untersuchungen. */
    public const int HISTORY_LIMIT = 20;

    /** Quellparameter des Merlin-Exports, die nicht in der Parameterzuordnung gefuehrt werden. */
    private const array INDICATION_IDS = ['2441'];
    private const array INDICATION_NAMES = ['indications for implant: list', 'indication'];
    private const array PHYSICIAN_IDS = ['2432'];
    private const array PHYSICIAN_NAMES = ['follow-up physician', 'follow up physician'];

    /** Spalten der bestaetigten Patientenstammdaten (Schutz gegen dynamische SQL-Felder). */
    private const array MASTER_COLUMNS = [
        'street', 'postal_code', 'city', 'phone', 'indication', 'device_implant_location',
        'emergency_contact_name', 'emergency_contact_phone',
        'physician_name', 'physician_practice', 'physician_postal_code', 'physician_city', 'physician_phone',
        'control_physician', 'next_control_date', 'next_control_raw',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PatientCardRepository $repository,
        private readonly ReportService $reportService,
        private readonly PatientCardPdfGenerator $generator,
        private readonly Clock $clock,
    ) {
    }

    public static function pdfFilename(string $patientName, ?string $date, int $sequence): string
    {
        $parts = ['Patientenausweis', $patientName];
        if ($date !== null && $date !== '') {
            $parts[] = substr($date, 0, 10);
        }
        $parts[] = 'Nr' . $sequence;
        return FileName::downloadName(implode('_', $parts) . '.pdf');
    }

    // ------------------------------------------------------------------ Assistent

    /**
     * Ausgangsdaten fuer den Assistenten (Schritt 1).
     *
     * @return array{
     *     report: ReportData,
     *     values: array<string, string>,
     *     masterData: array<string, mixed>|null,
     *     candidates: list<array<string, mixed>>,
     *     existingCard: array<string, mixed>|null,
     *     settingsConfigured: bool
     * }
     */
    public function wizard(int $reportId): array
    {
        $report = $this->loadReport($reportId);
        $patientId = $this->repository->reportPatientId($reportId);
        $masterData = $patientId === null ? null : $this->repository->masterData($patientId);
        $values = $this->prefill($report, $masterData);
        $identityKey = PatientName::identityKey($values['last_name'], $values['first_name'], $values['date_of_birth']);

        return [
            'report' => $report,
            'values' => $values,
            'masterData' => $masterData,
            'candidates' => $identityKey === null ? [] : $this->repository->findPatientsByIdentity($identityKey),
            'existingCard' => $this->repository->latestCardForReport($reportId),
            'settingsConfigured' => $this->repository->settingsVersion($this->repository->currentSettingsVersionId() ?? 0) !== null,
        ];
    }

    public function loadReport(int $reportId): ReportData
    {
        return $this->reportService->load($reportId)
            ?? throw new RuntimeException('Der Bericht wurde nicht gefunden.');
    }

    /**
     * Vorschlagswerte aus Bericht und vorhandenen Patientenstammdaten.
     *
     * @param array<string, mixed>|null $masterData
     * @return array<string, string>
     */
    public function prefill(ReportData $report, ?array $masterData): array
    {
        $name = PatientName::split($report->report['patient_name_snapshot'] ?? null);
        $dateOfBirth = $this->reportDateOfBirth($report);

        $values = [
            'last_name' => $name['last'] ?? '',
            'first_name' => $name['first'] ?? '',
            'date_of_birth' => $dateOfBirth,
        ];
        foreach (PatientCardInput::TEXT_FIELDS as $field => $max) {
            $values[$field] = (string) ($masterData[$field] ?? '');
        }
        $values['indication'] = $values['indication'] !== ''
            ? $values['indication']
            : $this->reportValue($report, self::INDICATION_IDS, self::INDICATION_NAMES);
        $values['control_physician'] = $values['control_physician'] !== ''
            ? $values['control_physician']
            : $this->reportValue($report, self::PHYSICIAN_IDS, self::PHYSICIAN_NAMES);
        $values['next_control_date'] = (string) ($masterData['next_control_date'] ?? '');

        return $values;
    }

    /**
     * Patienten mit identischer Identitaet (Nachname + Vorname + Geburtsdatum).
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(PatientCardInput $input): array
    {
        $key = $input->identityKey();
        return $key === '' ? [] : $this->repository->findPatientsByIdentity($key);
    }

    /**
     * Konflikte zwischen den Assistentenwerten und den bisher bestaetigten Angaben.
     * Nur Abweichungen von vorhandenen Werten gelten als Konflikt.
     *
     * @param array<string, mixed>|null $masterData
     * @return list<array{field: string, label: string, stored: string, new: string}>
     */
    public function conflicts(PatientCardInput $input, ?array $masterData): array
    {
        if ($masterData === null) {
            return [];
        }
        $labels = self::fieldLabels();
        $new = $input->allValues();
        $conflicts = [];
        foreach (PatientCardInput::MERGE_FIELDS as $field) {
            $stored = (string) ($masterData[$field] ?? '');
            $candidate = (string) ($new[$field] ?? '');
            if ($stored === '' || $stored === $candidate) {
                continue;
            }
            $conflicts[] = [
                'field' => $field,
                'label' => $labels[$field] ?? $field,
                'stored' => $stored,
                'new' => $candidate,
            ];
        }
        return $conflicts;
    }

    /**
     * Werte nach Anwendung der Konfliktentscheidungen.
     *
     * @param array<string, mixed>|null $masterData
     * @return array<string, string>
     */
    public function mergedValues(PatientCardInput $input, ?array $masterData): array
    {
        $values = $input->allValues();
        foreach ($input->conflictChoices as $field => $choice) {
            if ($choice === 'stored' && $masterData !== null) {
                $values[$field] = (string) ($masterData[$field] ?? '');
            }
        }
        return $values;
    }

    /**
     * @return array<string, string>
     */
    public static function fieldLabels(): array
    {
        return [
            'street' => 'Straße',
            'postal_code' => 'Postleitzahl',
            'city' => 'Wohnort',
            'phone' => 'Telefon',
            'indication' => 'Indikation',
            'device_implant_location' => 'Implantationsort des Geräts',
            'emergency_contact_name' => 'Notfallkontakt: Name',
            'emergency_contact_phone' => 'Notfallkontakt: Telefon',
            'physician_name' => 'Hausarzt: Name',
            'physician_practice' => 'Hausarzt: Praxis',
            'physician_postal_code' => 'Hausarzt: Postleitzahl',
            'physician_city' => 'Hausarzt: Ort',
            'physician_phone' => 'Hausarzt: Telefon',
            'control_physician' => 'Arzt zur Kontrolle',
            'next_control_date' => 'Nächste Kontrolle',
        ];
    }

    // ------------------------------------------------------------------ Erzeugen

    /**
     * Erzeugt den Ausweis samt PDF. Ohne beide Bestaetigungen wird nichts gespeichert.
     *
     * @param array<string, mixed>|null $masterData
     * @return array{card_id: int, patient_id: int, sequence_no: int, created: bool}
     */
    public function create(PatientCardInput $input, ReportData $report, ?array $masterData, ?int $selectedPatientId = null): array
    {
        $confirmations = $input->confirmations();
        $errors = [];
        if (!$confirmations['patient']) {
            $errors['confirm_patient'] = 'Die Bestätigung „Ja, dies ist der richtige Patient." ist erforderlich.';
        }
        if (!$confirmations['merge']) {
            $errors['confirm_merge'] = 'Die Bestätigung, dass die Daten zusammengeführt werden dürfen, ist erforderlich.';
        }
        if ($errors !== []) {
            throw PatientCardException::validation($errors);
        }

        $candidates = $this->candidates($input);
        if (count($candidates) > 1 && $selectedPatientId === null) {
            throw PatientCardException::rule(
                'patient_id',
                'Zu diesem Namen und Geburtsdatum existieren mehrere Patienten. Bitte den richtigen Patienten auswählen.',
            );
        }

        $now = $this->now();
        $values = $this->mergedValues($input, $masterData);
        $patientName = $input->displayName();

        $this->pdo->beginTransaction();
        try {
            $patientId = $this->resolvePatient($input, $report, $candidates, $selectedPatientId, $now);
            $this->repository->saveMasterData($patientId, $this->masterValues($values), $now);
            $this->repository->linkReportToPatient($report->id(), $patientId);

            $settingsVersionId = $this->ensureSettingsVersion($now);
            $sequence = $this->repository->nextSequence($patientId);
            $cardVersion = $this->repository->nextCardVersion($report->id());
            $settings = $this->repository->settingsVersion($settingsVersionId) ?? [];
            $settings = $this->withLogoMetadata($settings);
            $history = $this->historyEntries($patientId, $report->id(), $settings);

            $snapshot = $this->snapshot($input, $report, $values, $settings, $settingsVersionId, $history, $sequence, $cardVersion, $now);
            $logo = $this->logoImage($settings);
            $generatedAt = $this->clock()->now();
            $pdf = $this->generator->generate($snapshot, $logo, $generatedAt);
            $filename = self::pdfFilename($patientName, (string) ($snapshot['follow_up']['report_date'] ?? ''), $sequence);

            $cardId = $this->repository->insertCard([
                'patient_id' => $patientId,
                'report_id' => $report->id(),
                'settings_version_id' => $settingsVersionId,
                'sequence_no' => $sequence,
                'card_version' => $cardVersion,
                'last_name' => $input->lastName,
                'first_name' => $input->firstName,
                'date_of_birth' => $input->dateOfBirth,
                'patient_name' => $patientName,
                'follow_up_date' => $snapshot['follow_up']['report_date'],
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pdf_filename' => $filename,
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

        return ['card_id' => $cardId, 'patient_id' => $patientId, 'sequence_no' => $sequence, 'created' => $candidates === []];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function resolvePatient(PatientCardInput $input, ReportData $report, array $candidates, ?int $selectedPatientId, string $now): int
    {
        if ($selectedPatientId !== null) {
            foreach ($candidates as $candidate) {
                if ((int) $candidate['id'] === $selectedPatientId) {
                    return $selectedPatientId;
                }
            }
            throw PatientCardException::rule('patient_id', 'Der ausgewählte Patient passt nicht zu den eingegebenen Identitätsangaben.');
        }
        if (count($candidates) === 1) {
            $patientId = (int) $candidates[0]['id'];
            $this->repository->fillPatientIdentity(
                $patientId,
                $input->lastName,
                $input->firstName,
                $input->dateOfBirth,
                $input->dateOfBirthRaw,
                $now,
            );
            return $patientId;
        }

        $identifier = (string) ($report->report['patient_identifier_snapshot'] ?? '');
        if ($identifier === '' || $this->repository->patientIdentifierInUse($identifier)) {
            $identifier = null;
        }
        return $this->repository->createPatient(
            $identifier,
            $input->displayName(),
            $input->lastName,
            $input->firstName,
            $input->dateOfBirth,
            $input->dateOfBirthRaw,
            $now,
        );
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string|null>
     */
    private function masterValues(array $values): array
    {
        $data = [];
        foreach (self::MASTER_COLUMNS as $column) {
            $value = (string) ($values[$column] ?? '');
            if ($column === 'next_control_date') {
                $data[$column] = $value === '' ? null : $value;
                continue;
            }
            $data[$column] = $value;
        }
        return $data;
    }

    /**
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>
     */
    private function snapshot(
        PatientCardInput $input,
        ReportData $report,
        array $values,
        array $settings,
        int $settingsVersionId,
        array $history,
        int $sequence,
        int $cardVersion,
        string $now,
    ): array {
        $summaryPatient = $this->summaryValues($report, 'patient');
        $summaryDevice = $this->summaryValues($report, 'device');
        $reportDate = $this->reportDate($report);

        $leads = [];
        foreach ($report->leads() as $lead) {
            $leads[] = [
                'chamber_label' => (string) ($lead['chamber_label'] ?? ''),
                'manufacturer' => (string) ($lead['manufacturer'] ?? ''),
                'model_number' => (string) ($lead['model_number'] ?? ''),
                'model_label' => (string) ($lead['model_label'] ?? ''),
                'serial_number' => (string) ($lead['serial_number'] ?? ''),
                'lead_type' => (string) ($lead['lead_type'] ?? ''),
                'implant_date_display' => (string) ($lead['implant_date_display'] ?? ''),
            ];
        }

        $reportLabel = sprintf('Bericht Nr. %d%s', $report->id(), $reportDate === null ? '' : ' vom ' . PatientCardInput::formatDate($reportDate));

        return [
            'card_version' => self::CARD_VERSION,
            'patient_card_version' => self::PATIENT_CARD_VERSION,
            'generated_at' => $now,
            'sequence_no' => $sequence,
            'card_no' => $cardVersion,
            'settings_version_id' => $settingsVersionId,
            'settings' => [
                'center_name' => (string) ($settings['center_name'] ?? ''),
                'center_address' => (string) ($settings['center_address'] ?? ''),
                'notice_text' => (string) ($settings['notice_text'] ?? ''),
                'flight_notice_de' => (string) ($settings['flight_notice_de'] ?? ''),
                'flight_notice_en' => (string) ($settings['flight_notice_en'] ?? ''),
                'logo_sha256' => (string) ($settings['logo_sha256'] ?? ''),
                'logo_filename' => (string) ($settings['logo_filename'] ?? ''),
            ],
            'patient' => [
                'last_name' => $input->lastName,
                'first_name' => $input->firstName,
                'patient_name' => $input->displayName(),
                'date_of_birth' => $input->dateOfBirth,
                'date_of_birth_display' => PatientCardInput::formatDate($input->dateOfBirth),
                'date_of_birth_raw' => $input->dateOfBirthRaw ?? (string) ($summaryPatient['Geburtsdatum'] ?? ''),
                'patient_identifier' => (string) ($summaryPatient['Patient-ID'] ?? ''),
                'street' => $values['street'],
                'postal_code' => $values['postal_code'],
                'city' => $values['city'],
                'phone' => $values['phone'],
                'indication' => $values['indication'],
            ],
            'device' => [
                'manufacturer' => (string) ($summaryDevice['Hersteller'] ?? ''),
                'model_name' => (string) ($summaryDevice['Modell'] ?? ''),
                'model_number' => (string) ($summaryDevice['Modellnummer'] ?? ''),
                'serial_number' => (string) ($summaryDevice['Seriennummer'] ?? ''),
                'implant_date' => (string) ($summaryDevice['Implantation'] ?? ''),
                'implant_date_display' => (string) ($summaryDevice['Implantation'] ?? ''),
                'implant_location' => $values['device_implant_location'],
                'mode' => (string) ($summaryDevice['Modus'] ?? ''),
                'base_rate' => (string) ($summaryDevice['Grundfrequenz'] ?? ''),
            ],
            'leads' => $leads,
            'emergency_contact' => [
                'name' => $values['emergency_contact_name'],
                'phone' => $values['emergency_contact_phone'],
            ],
            'physician' => [
                'name' => $values['physician_name'],
                'practice' => $values['physician_practice'],
                'postal_code' => $values['physician_postal_code'],
                'city' => $values['physician_city'],
                'phone' => $values['physician_phone'],
            ],
            'follow_up' => [
                'report_id' => $report->id(),
                'report_label' => $reportLabel,
                'report_date' => $reportDate,
                'report_date_display' => PatientCardInput::formatDate($reportDate),
                'report_filename' => (string) ($report->import['filename'] ?? ''),
                'next_control_date' => $values['next_control_date'] === '' ? null : $values['next_control_date'],
                'next_control_display' => PatientCardInput::formatDate($values['next_control_date']),
                'control_physician' => $values['control_physician'],
            ],
            'history' => $history,
            'source' => [
                'report_id' => $report->id(),
                'import_id' => (int) $report->report['import_id'],
                'filename' => (string) ($report->import['filename'] ?? ''),
                'session_timestamp' => (string) ($report->report['session_timestamp'] ?? ''),
                'parser_version' => (string) $report->report['parser_version'],
                'mapping_version' => (string) $report->report['mapping_version'],
                'report_version' => $report->reportVersion(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    private function historyEntries(int $patientId, int $excludeReportId, array $settings): array
    {
        $reports = $this->repository->pastReports($patientId, $excludeReportId, self::HISTORY_LIMIT);
        if ($reports === []) {
            return [];
        }
        $physicians = $this->repository->followUpPhysicians(array_map(static fn (array $row): int => (int) $row['id'], $reports));
        $center = (string) ($settings['center_name'] ?? '');

        $entries = [];
        foreach ($reports as $row) {
            $date = $row['session_timestamp'] ?? $row['interrogation_timestamp'] ?? $row['created_at'];
            $entries[] = [
                'report_id' => (int) $row['id'],
                'date' => $date === null ? null : substr((string) $date, 0, 10),
                'date_display' => PatientCardInput::formatDate($date === null ? null : substr((string) $date, 0, 10)),
                'report_label' => 'Bericht Nr. ' . (int) $row['id'],
                'physician' => $physicians[(int) $row['id']] ?? '',
                'center' => $center,
                'filename' => (string) $row['filename'],
            ];
        }
        return $entries;
    }

    private function ensureSettingsVersion(string $now): int
    {
        $current = $this->repository->currentSettingsVersionId();
        if ($current !== null) {
            return $current;
        }
        $settings = $this->repository->settings() ?? [];
        return $this->repository->saveSettings([
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
     */
    private function logoImage(array $settings): ?ImageData
    {
        $logoId = $settings['logo_id'] ?? null;
        if ($logoId === null) {
            return null;
        }
        $logo = $this->repository->logo((int) $logoId);
        if ($logo === null) {
            return null;
        }
        try {
            return ImageData::fromBytes((string) $logo['content']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Ergaenzt die Stammdatenfassung um die Kennung des hinterlegten Logos, damit der Snapshot
     * eines Ausweises die verwendete Logodatei nachvollziehbar festhaelt.
     *
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
        $logo = $this->repository->logo((int) $logoId);
        if ($logo !== null) {
            $settings['logo_sha256'] = (string) $logo['sha256'];
            $settings['logo_filename'] = (string) $logo['filename'];
        }
        return $settings;
    }

    // ------------------------------------------------------------------- Auslesen

    /**
     * @return array<string, mixed>|null
     */
    public function card(int $cardId, bool $withContent = false): ?array
    {
        return $this->repository->card($cardId, $withContent);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        return $this->repository->search($filters, $limit, $offset);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cardsForPatient(int $patientId): array
    {
        return $this->repository->cardsByPatient($patientId);
    }

    /**
     * Alle Ausweisfassungen zu einem Bericht (historische Uebersicht, §14).
     *
     * @return list<array<string, mixed>>
     */
    public function cardsForReport(int $reportId): array
    {
        return $this->repository->cardsForReport($reportId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestCardForReport(int $reportId): ?array
    {
        return $this->repository->latestCardForReport($reportId);
    }

    // -------------------------------------------------------------------- Helfer

    private function now(): string
    {
        return $this->clock()->now()->format('Y-m-d H:i:s');
    }

    private function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * @return array<string, string> Label => Wert
     */
    private function summaryValues(ReportData $report, string $section): array
    {
        $values = [];
        foreach ($report->summarySection($section) as $row) {
            $values[(string) $row['label']] = (string) ($row['value'] ?? '');
        }
        return $values;
    }

    /**
     * @param list<string> $ids
     * @param list<string> $names
     */
    private function reportValue(ReportData $report, array $ids, array $names): string
    {
        foreach ($ids as $id) {
            foreach ($report->parameters as $parameter) {
                if ((string) $parameter['parameter_id'] === $id) {
                    $value = trim((string) ($parameter['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        $wanted = array_map(static fn (string $name): string => mb_strtolower($name), $names);
        foreach ($wanted as $name) {
            foreach ($report->parameters as $parameter) {
                if (mb_strtolower(trim((string) $parameter['parameter_name'])) === $name) {
                    $value = trim((string) ($parameter['value'] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        return '';
    }

    private function reportDateOfBirth(ReportData $report): string
    {
        $patientId = $this->repository->reportPatientId($report->id());
        if ($patientId !== null) {
            $patient = $this->repository->patient($patientId);
            if ($patient !== null && ($patient['date_of_birth'] ?? null) !== null) {
                return (string) $patient['date_of_birth'];
            }
        }
        foreach ($report->summarySection('patient') as $row) {
            if (($row['label'] ?? '') === 'Geburtsdatum') {
                $parsed = PatientCardInput::parseDate((string) ($row['value'] ?? ''));
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        $parsed = PatientCardInput::parseDate((string) ($report->report['patient_dob_snapshot'] ?? ''));
        return $parsed ?? '';
    }

    private function reportDate(ReportData $report): ?string
    {
        $value = $report->report['session_timestamp']
            ?? $report->report['interrogation_timestamp']
            ?? $report->report['created_at']
            ?? null;
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
