<?php

declare(strict_types=1);

namespace App\PatientCard;

use PDO;

/**
 * Datenzugriff der Ausweisvorlagen (Migration 014). Fassungen werden nur eingefuegt, nie
 * geaendert; version_no laeuft fortlaufend.
 */
final class PatientCardTemplateRepository
{
    private const string COLUMNS = 'v.id, v.version_no, v.name, v.comment, v.schema_version, v.content_sha256, v.created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ', v.content FROM patient_card_template_versions v ORDER BY v.version_no DESC LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function version(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', v.content FROM patient_card_template_versions v WHERE v.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Alle Fassungen (neueste zuerst) mit der Zahl der damit erzeugten Ausweise.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM patient_cards c WHERE c.template_version_id = v.id) AS card_count'
            . ' FROM patient_card_template_versions v ORDER BY v.version_no DESC'
        );
        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countVersions(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM patient_card_template_versions');
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    public function nextVersionNo(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(version_no), 0) + 1 FROM patient_card_template_versions');
        return $stmt === false ? 1 : (int) $stmt->fetchColumn();
    }

    public function insert(int $versionNo, string $name, ?string $comment, string $content, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO patient_card_template_versions (version_no, name, comment, schema_version, content, content_sha256, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$versionNo, $name, $comment, PatientCardTemplate::SCHEMA, $content, hash('sha256', $content), $createdAt]);
        return (int) $this->pdo->lastInsertId();
    }
}
