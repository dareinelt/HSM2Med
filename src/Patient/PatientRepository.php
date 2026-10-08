<?php

declare(strict_types=1);

namespace App\Patient;

use PDO;

/**
 * Datenzugriff auf Patienten und ihre Stammdaten. Ausschliesslich Prepared Statements.
 *
 * Grundsaetze:
 *  * Die Identitaet eines Patienten ist Nachname + Vorname + Geburtsdatum und liegt als
 *    generierte Spalte patients.identity_key vor (NULL, solange eine Angabe fehlt).
 *  * Der Index auf identity_key ist bewusst nicht eindeutig: mehrere Patienten mit gleichem
 *    Namen und Geburtsdatum sind moeglich. Ein Treffer ist deshalb nur ein Hinweis, keine
 *    Entscheidung; die Bestaetigung erfolgt durch den Benutzer.
 *  * Stammdaten (patient_card_master_data) halten den aktuellen, bestaetigten Datenstand und
 *    werden als Ganzes fortgeschrieben (ein Datensatz je Patient).
 *  * Patienten werden nie geloescht (Fremdschluessel ON DELETE RESTRICT).
 *
 * Der Patientenausweis erweitert diesen Zugriff um die ausweisspezifischen Tabellen
 * (PatientCardRepository).
 */
class PatientRepository
{
    public function __construct(protected readonly PDO $pdo)
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

    public function patientIdentifierInUse(string $identifier, ?int $excludePatientId = null): bool
    {
        $sql = 'SELECT 1 FROM patients WHERE patient_identifier = ?';
        $params = [$identifier];
        if ($excludePatientId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludePatientId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
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

    /**
     * Schreibt die Stammdaten der Akte fort (Identitaet und Zusatzangaben).
     */
    public function updatePatient(
        int $patientId,
        ?string $identifier,
        string $patientName,
        string $lastName,
        string $firstName,
        string $dateOfBirth,
        ?string $dateOfBirthRaw,
        string $now,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE patients SET patient_identifier = ?, patient_name = ?, last_name = ?, first_name = ?,'
            . ' date_of_birth = ?, date_of_birth_raw = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$identifier, $patientName, $lastName, $firstName, $dateOfBirth, $dateOfBirthRaw, $now, $patientId]);
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

    /**
     * Patientenuebersicht mit Suche. Die Kennzahlen der Akte werden je Zeile mitgezaehlt,
     * damit die Liste ohne weitere Abfragen auskommt.
     *
     * @param array<string, string> $filters q, identifier
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function searchPatients(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            // Zwei getrennte Parameter: native Prepared Statements erlauben keine Wiederholung.
            $where[] = '(p.patient_name LIKE :q_name OR p.last_name LIKE :q_last OR p.first_name LIKE :q_first)';
            // Fix: Platzhalter (%, _, Backslash) im Suchbegriff werden woertlich gesucht, nicht als Muster.
            $params[':q_name'] = self::like($filters['q']);
            $params[':q_last'] = self::like($filters['q']);
            $params[':q_first'] = self::like($filters['q']);
        }
        if (($filters['identifier'] ?? '') !== '') {
            $where[] = 'p.patient_identifier LIKE :identifier';
            $params[':identifier'] = self::like($filters['identifier']);
        }
        if (($filters['dob'] ?? '') !== '') {
            $where[] = 'p.date_of_birth = :dob';
            $params[':dob'] = $filters['dob'];
        }
        $from = ' FROM patients p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.patient_identifier, p.patient_name, p.last_name, p.first_name, p.date_of_birth,'
            . ' p.updated_at,'
            . ' (SELECT COUNT(*) FROM reports r WHERE r.patient_id = p.id) AS report_count,'
            . ' (SELECT COUNT(*) FROM patient_records pr WHERE pr.patient_id = p.id) AS record_count,'
            . ' (SELECT MAX(pr.updated_at) FROM patient_records pr WHERE pr.patient_id = p.id) AS records_updated_at'
            . $from . ' ORDER BY p.patient_name, p.date_of_birth, p.id LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function countPatients(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM patients');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Berichte, die bereits mit dem Patienten verknuepft sind (Untersuchungen).
     *
     * @return list<array<string, mixed>>
     */
    public function reportsByPatient(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.session_timestamp, r.interrogation_timestamp, r.created_at, r.device_serial_snapshot,'
            . ' r.device_model_name_snapshot, r.parameter_count, i.filename, i.imported_at'
            . ' FROM reports r JOIN imports i ON i.id = r.import_id'
            . ' WHERE r.patient_id = ?'
            . ' ORDER BY COALESCE(r.session_timestamp, r.interrogation_timestamp, r.created_at) DESC, r.id DESC'
        );
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        // Defense in depth: Spaltennamen werden in das SQL eingesetzt und muessen Bezeichner sein.
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column) !== 1) {
                throw new \InvalidArgumentException('Ungueltige Spalte fuer Stammdaten.');
            }
        }
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(static fn (string $column): string => sprintf('%s = VALUES(%s)', $column, $column), $columns));
        $sql = 'INSERT INTO patient_card_master_data (patient_id, ' . implode(', ', $columns) . ', created_at, updated_at)'
            . ' VALUES (?, ' . $placeholders . ', ?, ?)'
            . ' ON DUPLICATE KEY UPDATE ' . $updates . ', updated_at = VALUES(updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$patientId, ...array_values($values), $now, $now]);
    }

    /**
     * LIKE-Muster "enthaelt" mit maskierten Platzhaltern (%, _ und Backslash werden woertlich gesucht).
     */
    private static function like(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
