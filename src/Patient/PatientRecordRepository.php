<?php

declare(strict_types=1);

namespace App\Patient;

use PDO;
use Throwable;

/**
 * Datenzugriff auf die Bausteine der Patientenakte (Migration 003).
 *
 * Grundsaetze:
 *  * patient_records ist der Behaelter je Patient und Bausteintyp, patient_record_versions
 *    haelt die unveraenderlichen Fassungen. Fassungen werden nie geaendert oder geloescht.
 *  * Die aktuelle Fassung ist die mit der hoechsten Versionsnummer; sie wird ueber den
 *    eindeutigen Schluessel (record_id, version) aufgeloest.
 *  * Ein inhaltsgleicher Speichervorgang erzeugt keine neue Fassung (Abgleich ueber
 *    content_hash).
 */
final class PatientRecordRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Behaelter anlegen oder vorhandenen liefern (ohne Zeitstempel zu veraendern).
     */
    public function ensureRecord(int $patientId, PatientRecordType $type, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_records (patient_id, record_type, created_at, updated_at) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$patientId, $type->value, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function record(int $patientId, PatientRecordType $type): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_records WHERE patient_id = ? AND record_type = ?');
        $stmt->execute([$patientId, $type->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordById(int $recordId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patient_records WHERE id = ?');
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Aktuelle Fassung eines Bausteins.
     *
     * @return array<string, mixed>|null
     */
    public function currentVersion(int $recordId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, record_id, version, content, content_text, content_hash, author_name, created_at'
            . ' FROM patient_record_versions WHERE record_id = ? ORDER BY version DESC LIMIT 1'
        );
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Alle Fassungen eines Bausteins, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(int $recordId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, record_id, version, content, content_text, content_hash, author_name, created_at'
            . ' FROM patient_record_versions WHERE record_id = ? ORDER BY version DESC'
        );
        $stmt->execute([$recordId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function nextVersion(int $recordId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM patient_record_versions WHERE record_id = ?');
        $stmt->execute([$recordId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Legt eine neue, unveraenderliche Fassung an.
     */
    public function insertVersion(
        int $recordId,
        int $version,
        string $contentJson,
        string $contentText,
        string $contentHash,
        ?string $authorName,
        string $now,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_record_versions (record_id, version, content, content_text, content_hash, author_name, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$recordId, $version, $contentJson, $contentText, $contentHash, $authorName, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function touchRecord(int $recordId, string $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE patient_records SET updated_at = ? WHERE id = ?');
        $stmt->execute([$now, $recordId]);
    }

    /**
     * Alle vorhandenen Bausteine eines Patienten mit ihrer aktuellen Fassung.
     *
     * @return array<string, array<string, mixed>> Bausteintyp => Fassung mit Behaelterdaten
     */
    public function currentRecords(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id AS record_id, r.record_type, r.created_at AS record_created_at, r.updated_at AS record_updated_at,'
            . ' v.id AS version_id, v.version, v.content, v.content_text, v.content_hash, v.author_name,'
            . ' v.created_at AS version_created_at,'
            . ' (SELECT COUNT(*) FROM patient_record_versions c WHERE c.record_id = r.id) AS version_count'
            . ' FROM patient_records r'
            . ' JOIN patient_record_versions v ON v.record_id = r.id'
            . ' WHERE r.patient_id = ?'
            . ' AND v.version = (SELECT MAX(m.version) FROM patient_record_versions m WHERE m.record_id = r.id)'
            . ' ORDER BY r.id'
        );
        $stmt->execute([$patientId]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['record_type']] = $row;
        }
        return $result;
    }

    public function countRecords(int $patientId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM patient_records WHERE patient_id = ?');
        $stmt->execute([$patientId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Legt eine neue Fassung an: Behaelter sicherstellen, Versionsnummer bestimmen, Fassung
     * einfuegen und den Behaelter-Zeitstempel fortschreiben. Alles in einer Transaktion.
     *
     * Ein inhaltsgleicher Inhalt erzeugt keine neue Fassung (unchanged = true).
     *
     * @return array{record_id: int, version_id: int, version: int, unchanged: bool}
     */
    public function appendVersion(
        int $patientId,
        PatientRecordType $type,
        string $contentJson,
        string $contentText,
        string $contentHash,
        ?string $authorName,
        string $now,
    ): array {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $recordId = $this->ensureRecord($patientId, $type, $now);
            $current = $this->currentVersion($recordId);
            if ($current !== null && hash_equals((string) $current['content_hash'], $contentHash)) {
                if ($own) {
                    $this->pdo->commit();
                }
                return [
                    'record_id' => $recordId,
                    'version_id' => (int) $current['id'],
                    'version' => (int) $current['version'],
                    'unchanged' => true,
                ];
            }

            $version = $this->nextVersion($recordId);
            $versionId = $this->insertVersion($recordId, $version, $contentJson, $contentText, $contentHash, $authorName, $now);
            $this->touchRecord($recordId, $now);
            if ($own) {
                $this->pdo->commit();
            }
            return ['record_id' => $recordId, 'version_id' => $versionId, 'version' => $version, 'unchanged' => false];
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
