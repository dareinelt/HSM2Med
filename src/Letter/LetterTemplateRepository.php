<?php

declare(strict_types=1);

namespace App\Letter;

use PDO;

/**
 * Datenzugriff der Briefvorlagen (Migration 007, je Empfaengerart ab Migration 009). Fassungen
 * werden nur eingefuegt, nie geaendert. version_no laeuft je Empfaengerart eigenstaendig.
 */
final class LetterTemplateRepository
{
    private const string COLUMNS = 'v.id, v.template_type, v.version_no, v.name, v.comment, v.schema_version, v.content_sha256, v.created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ', v.content FROM letter_template_versions v WHERE v.template_type = ? ORDER BY v.version_no DESC LIMIT 1'
        );
        $stmt->execute([$type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
     * Alle Fassungen einer Empfaengerart (neueste zuerst) mit der Zahl der damit erzeugten Briefe.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $type): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ', (SELECT COUNT(*) FROM patient_letters l WHERE l.template_version_id = v.id) AS letter_count'
            . ' FROM letter_template_versions v WHERE v.template_type = ? ORDER BY v.version_no DESC'
        );
        $stmt->execute([$type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function nextVersionNo(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM letter_template_versions WHERE template_type = ?');
        $stmt->execute([$type]);
        return (int) $stmt->fetchColumn();
    }

    public function insert(string $type, int $versionNo, string $name, ?string $comment, string $content, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO letter_template_versions (template_type, version_no, name, comment, schema_version, content, content_sha256, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $versionNo, $name, $comment, LetterTemplate::SCHEMA, $content, hash('sha256', $content), $createdAt]);
        return (int) $this->pdo->lastInsertId();
    }
}
