<?php

declare(strict_types=1);

namespace App\PatientCard;

use PDO;

/**
 * Datenzugriff des Patientenausweises (Migration 002). Ausschliesslich Prepared Statements.
 *
 * Grundsaetze:
 *  * Patienten werden ueber patients.identity_key (Nachname + Vorname + Geburtsdatum) gefunden.
 *  * Stammdatenaenderungen erzeugen eine neue, unveraenderliche Fassung
 *    (patient_card_settings_versions); Ausweise verweisen auf ihre Fassung.
 *  * Ausweise und Logos werden nie ueberschrieben.
 */
final class PatientCardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ---------------------------------------------------------------- Patienten

    /**
     * @return list<array<string, mixed>>
     */
    public function findPatientsByIdentity(string $identityKey): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, patient_identifier, patient_name, last_name, first_name, date_of_birth, date_of_birth_raw'
            . ' FROM patients WHERE identity_key = ? ORDER BY id'
        );
        $stmt->execute([$identityKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function patient(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patients WHERE id = ?');
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function patientIdentifierInUse(string $identifier): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM patients WHERE patient_identifier = ? LIMIT 1');
        $stmt->execute([$identifier]);
        return $stmt->fetchColumn() !== false;
    }

    public function createPatient(
        ?string $identifier,
        string $patientName,
        string $lastName,
        string $firstName,
        string $dateOfBirth,
        ?string $dateOfBirthRaw,
        string $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patients (patient_identifier, patient_name, last_name, first_name, date_of_birth, date_of_birth_raw, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$identifier, $patientName, $lastName, $firstName, $dateOfBirth, $dateOfBirthRaw, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ergaenzt Identitaetsangaben an einem vorhandenen Patienten (nur fehlende Werte).
     */
    public function fillPatientIdentity(int $patientId, string $lastName, string $firstName, string $dateOfBirth, ?string $dateOfBirthRaw, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE patients SET last_name = COALESCE(last_name, ?), first_name = COALESCE(first_name, ?),'
            . ' date_of_birth = COALESCE(date_of_birth, ?), date_of_birth_raw = COALESCE(date_of_birth_raw, ?), updated_at = ?'
            . ' WHERE id = ?'
        );
        $stmt->execute([$lastName, $firstName, $dateOfBirth, $dateOfBirthRaw, $now, $patientId]);
    }

    public function reportPatientId(int $reportId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT patient_id FROM reports WHERE id = ?');
        $stmt->execute([$reportId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    /**
     * Verknuepft einen Bericht mit dem bestaetigten Patienten (Zusammenfuehrung).
     */
    public function linkReportToPatient(int $reportId, int $patientId): void
    {
        $stmt = $this->pdo->prepare('UPDATE reports SET patient_id = ? WHERE id = ?');
        $stmt->execute([$patientId, $reportId]);
    }

    // ------------------------------------------------------------ Stammdaten je Patient

    /**
     * @return array<string, mixed>|null
     */
    public function masterData(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_card_master_data WHERE patient_id = ?');
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string|null> $values
     */
    public function saveMasterData(int $patientId, array $values, string $now): void
    {
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(static fn (string $column): string => sprintf('%s = VALUES(%s)', $column, $column), $columns));
        $sql = 'INSERT INTO patient_card_master_data (patient_id, ' . implode(', ', $columns) . ', created_at, updated_at)'
            . ' VALUES (?, ' . $placeholders . ', ?, ?)'
            . ' ON DUPLICATE KEY UPDATE ' . $updates . ', updated_at = VALUES(updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$patientId, ...array_values($values), $now, $now]);
    }

    // ------------------------------------------------------------------- Stammdaten

    /**
     * @return array<string, mixed>|null
     */
    public function settings(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM patient_card_settings WHERE id = 1');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function settingsVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_card_settings_versions WHERE id = ?');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function currentSettingsVersionId(): ?int
    {
        $stmt = $this->pdo->query('SELECT MAX(id) FROM patient_card_settings_versions');
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    public function countSettingsVersions(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM patient_card_settings_versions');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Schreibt die aktuellen Stammdaten und legt eine neue, unveraenderliche Fassung an.
     *
     * @param array<string, string|null> $values
     */
    public function saveSettings(array $values, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_card_settings (id, center_name, center_address, notice_text, flight_notice_de, flight_notice_en, logo_id, updated_at)'
            . ' VALUES (1, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE center_name = VALUES(center_name), center_address = VALUES(center_address),'
            . ' notice_text = VALUES(notice_text), flight_notice_de = VALUES(flight_notice_de),'
            . ' flight_notice_en = VALUES(flight_notice_en), logo_id = VALUES(logo_id), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            $values['center_name'] ?? null,
            $values['center_address'] ?? null,
            $values['notice_text'] ?? null,
            $values['flight_notice_de'] ?? null,
            $values['flight_notice_en'] ?? null,
            $values['logo_id'] ?? null,
            $now,
        ]);

        $version = $this->pdo->prepare(
            'INSERT INTO patient_card_settings_versions (center_name, center_address, notice_text, flight_notice_de, flight_notice_en, logo_id, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $version->execute([
            $values['center_name'] ?? null,
            $values['center_address'] ?? null,
            $values['notice_text'] ?? null,
            $values['flight_notice_de'] ?? null,
            $values['flight_notice_en'] ?? null,
            $values['logo_id'] ?? null,
            $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------------- Logos

    /**
     * @return array<string, mixed>|null
     */
    public function logo(int $logoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_card_logos WHERE id = ?');
        $stmt->execute([$logoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findLogoIdByHash(string $sha256): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM patient_card_logos WHERE sha256 = ?');
        $stmt->execute([$sha256]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function insertLogo(string $sha256, string $mimeType, string $filename, int $width, int $height, string $content, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_card_logos (sha256, mime_type, filename, width, height, content, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sha256, $mimeType, $filename, $width, $height, $content, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------- Ausweise

    /**
     * @param array<string, mixed> $card
     */
    public function insertCard(array $card): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_cards (patient_id, report_id, settings_version_id, sequence_no, card_version, last_name, first_name,'
            . ' date_of_birth, patient_name, follow_up_date, snapshot, pdf_filename, pdf_sha256, pdf_size, pdf_content, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $card['patient_id'],
            $card['report_id'],
            $card['settings_version_id'],
            $card['sequence_no'],
            $card['card_version'],
            $card['last_name'],
            $card['first_name'],
            $card['date_of_birth'],
            $card['patient_name'],
            $card['follow_up_date'],
            $card['snapshot'],
            $card['pdf_filename'],
            $card['pdf_sha256'],
            $card['pdf_size'],
            $card['pdf_content'],
            $card['created_at'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private const string CARD_COLUMNS = 'c.id, c.patient_id, c.report_id, c.settings_version_id, c.sequence_no, c.card_version,'
        . ' c.last_name, c.first_name, c.date_of_birth, c.patient_name, c.follow_up_date, c.pdf_filename, c.pdf_sha256,'
        . ' c.pdf_size, c.created_at';

    /**
     * @return array<string, mixed>|null
     */
    public function card(int $cardId, bool $withContent = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ', c.snapshot' . ($withContent ? ', c.pdf_content' : '') . ' FROM patient_cards c WHERE c.id = ?'
        );
        $stmt->execute([$cardId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Juengster Ausweis zu einem Bericht.
     *
     * @return array<string, mixed>|null
     */
    public function latestCardForReport(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM patient_cards c WHERE c.report_id = ? ORDER BY c.card_version DESC, c.id DESC LIMIT 1'
        );
        $stmt->execute([$reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Juengster Ausweis je Bericht – fuer die Berichtsauswahl (Seite "Ausweis erstellen").
     *
     * @param list<int> $reportIds
     * @return array<int, array<string, mixed>> Bericht-ID => Ausweis
     */
    public function latestCardsForReports(array $reportIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $reportIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM patient_cards c WHERE c.report_id IN (' . $placeholders . ')'
            . ' ORDER BY c.report_id, c.card_version DESC, c.id DESC'
        );
        $stmt->execute($ids);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['report_id']] ??= $row;
        }
        return $result;
    }

    public function nextSequence(int $patientId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM patient_cards WHERE patient_id = ?');
        $stmt->execute([$patientId]);
        return (int) $stmt->fetchColumn();
    }

    public function nextCardVersion(int $reportId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(card_version), 0) + 1 FROM patient_cards WHERE report_id = ?');
        $stmt->execute([$reportId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cardsByPatient(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ', r.session_timestamp, r.interrogation_timestamp'
            . ' FROM patient_cards c JOIN reports r ON r.id = c.report_id'
            . ' WHERE c.patient_id = ? ORDER BY c.created_at DESC, c.id DESC'
        );
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alle Ausweisfassungen zu einem Bericht (historische Uebersicht, §14).
     *
     * @return list<array<string, mixed>>
     */
    public function cardsForReport(int $reportId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM patient_cards c'
            . ' WHERE c.report_id = ? ORDER BY c.card_version ASC, c.id ASC'
        );
        $stmt->execute([$reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Frueher erstellte Ausweise desselben Patienten (Verlauf in der Oberflaeche).
     *
     * @return list<array<string, mixed>>
     */
    public function previousCards(int $patientId, int $excludeCardId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM patient_cards c'
            . ' WHERE c.patient_id = ? AND c.id <> ? ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$patientId, $excludeCardId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Vergangene Untersuchungen desselben Patienten (Quelle der Messwertspalten auf Seite 2).
     *
     * @return list<array<string, mixed>>
     */
    public function pastReports(int $patientId, int $excludeReportId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.session_timestamp, r.interrogation_timestamp, r.created_at, r.device_serial_snapshot,'
            . ' r.device_model_name_snapshot, r.parameter_count, i.filename, i.imported_at'
            . ' FROM reports r JOIN imports i ON i.id = r.import_id'
            . ' WHERE r.patient_id = ? AND r.id <> ?'
            . ' ORDER BY COALESCE(r.session_timestamp, r.interrogation_timestamp, r.created_at) DESC, r.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$patientId, $excludeReportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Nachsorgearzt (Quellparameter 2432) je Bericht – in einer Abfrage fuer den Verlauf.
     *
     * @param list<int> $reportIds
     * @return array<int, string> Bericht-ID => Arzt
     */
    public function followUpPhysicians(array $reportIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $reportIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT report_id, value FROM report_parameters'
            . ' WHERE report_id IN (' . $placeholders . ') AND parameter_id = ? AND value IS NOT NULL AND value <> \'\''
            . ' ORDER BY original_position'
        );
        $stmt->execute([...$ids, '2432']);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['report_id']] ??= trim((string) $row['value']);
        }
        return $result;
    }

    /**
     * Messwerte mehrerer Berichte fuer die Messwerttabelle auf Seite 2 des Ausweises.
     * Gelesen werden nur die angefragten Parameter-IDs und -Bezeichnungen; je Bericht und
     * Parameter gewinnt der erste nicht leere Wert (Reihenfolge wie im Bericht).
     *
     * @param list<int> $reportIds
     * @param list<string> $parameterIds
     * @param list<string> $parameterNames bereits normalisiert (Kleinbuchstaben, ohne Rand-Leerzeichen)
     * @return array<int, array{ids: array<string, string>, names: array<string, string>}>
     */
    public function measurementValues(array $reportIds, array $parameterIds, array $parameterNames): array
    {
        $ids = array_values(array_filter(array_map('intval', $reportIds), static fn (int $id): bool => $id > 0));
        $parameters = array_values(array_filter(
            array_map('strval', $parameterIds),
            static fn (string $id): bool => $id !== '',
        ));
        $names = array_values(array_filter(
            array_map(static fn (mixed $name): string => mb_strtolower(trim((string) $name)), $parameterNames),
            static fn (string $name): bool => $name !== '',
        ));
        if ($ids === [] || ($parameters === [] && $names === [])) {
            return [];
        }

        $conditions = [];
        $bindings = $ids;
        if ($parameters !== []) {
            $conditions[] = 'parameter_id IN (' . implode(', ', array_fill(0, count($parameters), '?')) . ')';
            $bindings = [...$bindings, ...$parameters];
        }
        if ($names !== []) {
            $conditions[] = 'LOWER(TRIM(parameter_name)) IN (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $bindings = [...$bindings, ...$names];
        }

        $stmt = $this->pdo->prepare(
            'SELECT report_id, parameter_id, parameter_name, value FROM report_parameters'
            . ' WHERE report_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' AND (' . implode(' OR ', $conditions) . ')'
            . ' AND value IS NOT NULL AND value <> \'\''
            . ' ORDER BY report_id, original_position'
        );
        $stmt->execute($bindings);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = trim((string) $row['value']);
            if ($value === '') {
                continue;
            }
            $reportId = (int) $row['report_id'];
            $result[$reportId] ??= ['ids' => [], 'names' => []];
            $result[$reportId]['ids'][(string) $row['parameter_id']] ??= $value;
            $result[$reportId]['names'][mb_strtolower(trim((string) $row['parameter_name']))] ??= $value;
        }
        return $result;
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            // Zwei getrennte Parameter: native Prepared Statements erlauben keine Wiederholung.
            $where[] = '(c.patient_name LIKE :q_name OR c.pdf_filename LIKE :q_file)';
            $params[':q_name'] = '%' . $filters['q'] . '%';
            $params[':q_file'] = '%' . $filters['q'] . '%';
        }
        if (($filters['patient'] ?? '') !== '') {
            $where[] = 'c.patient_name LIKE :patient';
            $params[':patient'] = '%' . $filters['patient'] . '%';
        }
        if (($filters['serial'] ?? '') !== '') {
            $where[] = 'r.device_serial_snapshot = :serial';
            $params[':serial'] = $filters['serial'];
        }
        $from = ' FROM patient_cards c JOIN reports r ON r.id = c.report_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ', r.session_timestamp, r.interrogation_timestamp, r.device_serial_snapshot,'
            . ' r.device_model_name_snapshot' . $from . ' ORDER BY c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function countCards(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM patient_cards');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
