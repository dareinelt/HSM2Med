<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ImportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $where = $status !== null ? ' WHERE i.status = :status' : '';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM imports i' . $where);
        $count->execute($status !== null ? [':status' => $status] : []);

        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.filename, i.file_hash, i.file_size, i.encoding, i.imported_at, i.record_count,'
            . ' i.valid_record_count, i.warning_count, i.error_count, i.status, i.failure_reason, r.id AS report_id'
            . ' FROM imports i LEFT JOIN reports r ON r.import_id = i.id' . $where
            . ' ORDER BY i.imported_at DESC, i.id DESC LIMIT :limit OFFSET :offset'
        );
        if ($status !== null) {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $count->fetchColumn()];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, r.id AS report_id FROM imports i LEFT JOIN reports r ON r.import_id = i.id WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(int $importId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT severity, error_code, message, record_position, raw_record FROM import_errors'
            . ' WHERE import_id = ? ORDER BY record_position IS NULL DESC, record_position, id'
        );
        $stmt->execute([$importId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
