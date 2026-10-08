<?php

declare(strict_types=1);

namespace App\Repository;

use App\User\Permission;
use PDO;

/**
 * Datenzugriff der Gruppen und der Rechtematrix (Migration 013).
 *
 * Die Rechtematrix ist die Tabelle user_group_permissions (Gruppe x Bereich). Sie wird als
 * Ganzes je Gruppe ersetzt, damit An- und Abwahl in einem Schritt gespeichert werden.
 * Vorgegebene Gruppen (is_system = 1) koennen nicht geloescht werden.
 */
final class GroupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle Gruppen mit Mitgliederzahl und Rechten, in Anzeigereihenfolge.
     *
     * @return list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}>
     */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT g.id, g.code, g.label, COALESCE(g.description, \'\') AS description, g.is_system,'
            . ' (SELECT COUNT(*) FROM user_group_members m WHERE m.group_id = g.id) AS member_count'
            . ' FROM user_groups g ORDER BY g.sort_order, g.label'
        )->fetchAll(PDO::FETCH_ASSOC);
        $permissions = $this->permissionMap();

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = $this->hydrate($row, $permissions[(int) $row['id']] ?? []);
        }
        return $groups;
    }

    /**
     * @return array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.code, g.label, COALESCE(g.description, \'\') AS description, g.is_system,'
            . ' (SELECT COUNT(*) FROM user_group_members m WHERE m.group_id = g.id) AS member_count'
            . ' FROM user_groups g WHERE g.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->hydrate($row, $this->permissionsOf($id));
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM user_groups WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetchColumn() !== false;
    }

    public function nextSortOrder(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM user_groups')->fetchColumn();
    }

    public function create(string $code, string $label, string $description, int $sortOrder, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_groups (code, label, description, is_system, sort_order, created_at, updated_at)'
            . ' VALUES (?, ?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([$code, $label, $description === '' ? null : $description, $sortOrder, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $label, string $description, string $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE user_groups SET label = ?, description = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$label, $description === '' ? null : $description, $now, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_groups WHERE id = ? AND is_system = 0');
        $stmt->execute([$id]);
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(int $groupId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission FROM user_group_permissions WHERE group_id = ? ORDER BY permission');
        $stmt->execute([$groupId]);
        $permissions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
            if (is_string($permission)) {
                $permissions[] = $permission;
            }
        }
        return $permissions;
    }

    /**
     * Ersetzt die Rechte einer Gruppe vollstaendig.
     *
     * @param list<string> $permissions
     */
    public function savePermissions(int $groupId, array $permissions): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $delete = $this->pdo->prepare('DELETE FROM user_group_permissions WHERE group_id = ?');
            $delete->execute([$groupId]);
            $insert = $this->pdo->prepare('INSERT INTO user_group_permissions (group_id, permission) VALUES (?, ?)');
            foreach (Permission::normalize($permissions) as $permission) {
                $insert->execute([$groupId, $permission]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $permissions
     * @return array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}
     */
    private function hydrate(array $row, array $permissions): array
    {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'description' => (string) $row['description'],
            'is_system' => (int) $row['is_system'] === 1,
            'member_count' => (int) $row['member_count'],
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array<int, list<string>>
     */
    private function permissionMap(): array
    {
        $rows = $this->pdo->query('SELECT group_id, permission FROM user_group_permissions')->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            if (is_string($row['permission'])) {
                $map[(int) $row['group_id']][] = $row['permission'];
            }
        }
        return $map;
    }
}
