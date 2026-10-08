<?php

declare(strict_types=1);

namespace App\Letter;

use App\Patient\PatientRepository;
use PDO;

/**
 * Datenzugriff der Briefe (Migration 006). Ausschliesslich Prepared Statements.
 *
 * Grundsaetze:
 *  * Ein Brief wird nie ueberschrieben: Snapshot (JSON) und PDF (MEDIUMBLOB + SHA-256) werden
 *    gemeinsam in einer Transaktion eingefuegt.
 *  * sequence_no zaehlt je Patient, letter_version je Patient und zugrunde liegendem Bericht
 *    (ohne Bericht: je Patient). Beide Nummern dienen der Nachvollziehbarkeit.
 *
 * Patienten und Patienten-Stammdaten liegen in PatientRepository und werden von hier geerbt:
 * Brief, Patientenakte und Patientenausweis greifen auf dieselben Daten zu.
 */
final class LetterRepository extends PatientRepository
{
    private const string LETTER_COLUMNS = 'l.id, l.patient_id, l.report_id, l.settings_version_id, l.sequence_no,'
        . ' l.letter_version, l.last_name, l.first_name, l.date_of_birth, l.patient_name, l.letter_date,'
        . ' l.pdf_filename, l.pdf_sha256, l.pdf_size, l.created_at,'
        . " COALESCE(JSON_LENGTH(JSON_EXTRACT(l.snapshot, '$.appendix.sections')), 0) AS appendix_sections";

    public function insertLetter(array $letter): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_letters (patient_id, report_id, settings_version_id, sequence_no, letter_version,'
            . ' last_name, first_name, date_of_birth, patient_name, letter_date, snapshot, pdf_filename, pdf_sha256,'
            . ' pdf_size, pdf_content, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $letter['patient_id'],
            $letter['report_id'],
            $letter['settings_version_id'],
            $letter['sequence_no'],
            $letter['letter_version'],
            $letter['last_name'],
            $letter['first_name'],
            $letter['date_of_birth'],
            $letter['patient_name'],
            $letter['letter_date'],
            $letter['snapshot'],
            $letter['pdf_filename'],
            $letter['pdf_sha256'],
            $letter['pdf_size'],
            $letter['pdf_content'],
            $letter['created_at'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function letter(int $letterId, bool $withContent = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LETTER_COLUMNS . ', l.snapshot' . ($withContent ? ', l.pdf_content' : '')
            . ' FROM patient_letters l WHERE l.id = ?'
        );
        $stmt->execute([$letterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function nextSequence(int $patientId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM patient_letters WHERE patient_id = ?');
        $stmt->execute([$patientId]);
        return (int) $stmt->fetchColumn();
    }

    public function nextLetterVersion(int $patientId, ?int $reportId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(letter_version), 0) + 1 FROM patient_letters WHERE patient_id = ? AND report_id <=> ?'
        );
        $stmt->execute([$patientId, $reportId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lettersByPatient(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LETTER_COLUMNS . ', r.session_timestamp, r.interrogation_timestamp,'
            . ' r.device_model_name_snapshot, r.device_serial_snapshot'
            . ' FROM patient_letters l LEFT JOIN reports r ON r.id = l.report_id'
            . ' WHERE l.patient_id = ? ORDER BY l.created_at DESC, l.id DESC'
        );
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function previousLetters(int $patientId, int $excludeLetterId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LETTER_COLUMNS . ' FROM patient_letters l'
            . ' WHERE l.patient_id = ? AND l.id <> ? ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$patientId, $excludeLetterId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $where[] = '(l.patient_name LIKE :q_name OR l.pdf_filename LIKE :q_file)';
            $params[':q_name'] = '%' . $filters['q'] . '%';
            $params[':q_file'] = '%' . $filters['q'] . '%';
        }
        if (($filters['patient'] ?? '') !== '') {
            $where[] = 'l.patient_name LIKE :patient';
            $params[':patient'] = '%' . $filters['patient'] . '%';
        }
        if (($filters['report'] ?? '') !== '') {
            $where[] = 'l.report_id = :report';
            $params[':report'] = (int) $filters['report'];
        }
        $from = ' FROM patient_letters l' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT ' . self::LETTER_COLUMNS . ', r.session_timestamp, r.interrogation_timestamp,'
            . ' r.device_model_name_snapshot, r.device_serial_snapshot'
            . ' FROM patient_letters l LEFT JOIN reports r ON r.id = l.report_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY l.created_at DESC, l.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function countLetters(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM patient_letters');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * Neuester Patientenausweis eines Patienten samt Snapshot. Der Brief uebernimmt daraus
     * ausschliesslich die Angabe zur MRT-Tauglichkeit (eine Quelle, siehe Konzept §11.2).
     *
     * @return array<string, mixed>|null
     */
    public function latestCard(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.sequence_no, c.card_version, c.created_at, c.snapshot'
            . ' FROM patient_cards c WHERE c.patient_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 1'
        );
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
