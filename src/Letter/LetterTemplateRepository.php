<?php

declare(strict_types=1);

namespace App\Letter;

use PDO;

/**
 * Datenzugriff der Briefvorlagen (Migration 007). Fassungen werden nur eingefuegt, nie geaendert.
 */
final class LetterTemplateRepository
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
        $row = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ', v.content FROM letter_template_versions v ORDER BY v.version_no DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function version(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', v.content FROM letter_template_versions v WHERE v.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Alle Fassungen (neueste zuerst) mit der Zahl der damit erzeugten Briefe.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        return $this->pdo->query(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM patient_letters l WHERE l.template_version_id = v.id) AS letter_count'
            . ' FROM letter_template_versions v ORDER BY v.version_no DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function nextVersionNo(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(version_no), 0) + 1 FROM letter_template_versions')->fetchColumn();
    }

    public function insert(int $versionNo, string $name, ?string $comment, string $content, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO letter_template_versions (version_no, name, comment, schema_version, content, content_sha256, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$versionNo, $name, $comment, LetterTemplate::SCHEMA, $content, hash('sha256', $content), $createdAt]);
        return (int) $this->pdo->lastInsertId();
    }
}
