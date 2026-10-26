<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Datenzugriff der Unterschriften (Migration 017).
 *
 * Wie bei den Logos der Stammdaten wird eine Datei nur einmal gespeichert (Pruefsumme) und
 * Zeilen werden nie geaendert oder geloescht: bereits erstellte Briefe verweisen auf die
 * damalige Unterschrift und bleiben so reproduzierbar. Die Bilddaten verlassen die
 * Datenzugriffsschicht nur an die PDF-Erzeugung und an die Auslieferung des Bildes.
 */
final class SignatureRepository
{
    private const string COLUMNS = 'id, sha256, mime_type, filename, width, height, created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Angaben zu einer Unterschrift ohne Bilddaten.
     *
     * @return array{id: int, sha256: string, mime_type: string, filename: string, width: int, height: int, created_at: string}|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM user_signatures WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Unterschrift samt Bilddaten.
     *
     * @return array{id: int, sha256: string, mime_type: string, filename: string, width: int, height: int, created_at: string, content: string}|null
     */
    public function findWithContent(int $id): ?array
    {
        return $this->fetchWithContent('SELECT ' . self::COLUMNS . ', content FROM user_signatures WHERE id = ?', [$id]);
    }

    /**
     * Rueckfall fuer die Reproduktion: gleiche Datei ueber ihre Pruefsumme finden.
     *
     * @return array{id: int, sha256: string, mime_type: string, filename: string, width: int, height: int, created_at: string, content: string}|null
     */
    public function findByHash(string $sha256): ?array
    {
        return $this->fetchWithContent('SELECT ' . self::COLUMNS . ', content FROM user_signatures WHERE sha256 = ?', [$sha256]);
    }

    /**
     * Legt eine Unterschrift ab und liefert ihre Kennung. Eine bereits vorhandene Datei
     * (gleiche Pruefsumme) wird wiederverwendet.
     */
    public function store(
        string $content,
        string $sha256,
        string $mimeType,
        string $filename,
        int $width,
        int $height,
        string $now,
    ): int {
        $existing = $this->findByHash($sha256);
        if ($existing !== null) {
            return $existing['id'];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_signatures (sha256, mime_type, filename, width, height, content, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sha256, $mimeType, $filename, $width, $height, $content, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, sha256: string, mime_type: string, filename: string, width: int, height: int, created_at: string}
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'sha256' => (string) $row['sha256'],
            'mime_type' => (string) $row['mime_type'],
            'filename' => (string) $row['filename'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param list<int|string> $params
     * @return array{id: int, sha256: string, mime_type: string, filename: string, width: int, height: int, created_at: string, content: string}|null
     */
    private function fetchWithContent(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $signature = self::hydrate($row);
        $signature['content'] = (string) $row['content'];
        return $signature;
    }
}
