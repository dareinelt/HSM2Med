<?php

declare(strict_types=1);

namespace App\Repository;

use App\User\User;
use PDO;

/**
 * Datenzugriff der Benutzer (Migration 013).
 *
 * Kennwoerter werden ausschliesslich als Hash gespeichert; die Hash-Spalte wird nur an
 * UserService::authenticate() weitergereicht und verlaesst die Datenzugriffsschicht nie
 * in Richtung Oberflaeche. Benutzer werden nie geloescht, nur deaktiviert.
 */
final class UserRepository
{
    private const string COLUMNS = 'id, username, display_name, is_active, failed_attempts, locked_until,'
        . ' last_login_at, password_changed_at, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function find(int $id): ?User
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return null;
        }
        return User::fromRow($row, $this->groupsFor($id), $this->permissionsFor($id));
    }

    /**
     * Zeile inklusive Kennwort-Hash – nur fuer die Anmeldung.
     *
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Fingerabdruck des aktuellen Kennwort-Hashes (SHA-256) fuer die Sitzungsbindung.
     * Der Hash selbst verlaesst die Datenzugriffsschicht nicht; null, wenn es das Konto nicht gibt.
     */
    public function credentialFingerprint(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) ? hash('sha256', $hash) : null;
    }

    public function exists(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetchColumn() !== false;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /**
     * Alle Benutzer alphabetisch, mit Gruppen und Rechten.
     *
     * @return list<User>
     */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, username, display_name, is_active, last_login_at, created_at, updated_at'
            . ' FROM users ORDER BY is_active DESC, username'
        )->fetchAll(PDO::FETCH_ASSOC);
        $groups = $this->groupMap();
        $permissions = $this->permissionMap();

        $users = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $users[] = User::fromRow($row, $groups[$id] ?? [], $permissions[$id] ?? []);
        }
        return $users;
    }

    /**
     * @return list<array{id:int,code:string,label:string}>
     */
    public function groupsFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.id, g.code, g.label FROM user_group_members m'
            . ' JOIN user_groups g ON g.id = m.group_id WHERE m.user_id = ? ORDER BY g.sort_order, g.label'
        );
        $stmt->execute([$userId]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'label' => (string) $row['label']];
        }
        return $groups;
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.permission FROM user_group_members m'
            . ' JOIN user_group_permissions p ON p.group_id = m.group_id WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        $permissions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
            if (is_string($permission)) {
                $permissions[] = $permission;
            }
        }
        return $permissions;
    }

    public function create(string $username, string $displayName, string $passwordHash, bool $isActive, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, display_name, password_hash, is_active, created_at, updated_at, password_changed_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$username, $displayName, $passwordHash, $isActive ? 1 : 0, $now, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $username, string $displayName, bool $isActive, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET username = ?, display_name = ?, is_active = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$username, $displayName, $isActive ? 1 : 0, $now, $id]);
    }

    public function existsOther(string $username, int $excludeUserId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id <> ?');
        $stmt->execute([$username, $excludeUserId]);
        return $stmt->fetchColumn() !== false;
    }

    public function setPassword(int $id, string $passwordHash, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = ?, password_changed_at = ?, updated_at = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $now, $now, $id]);
    }

    /**
     * Ersetzt die Gruppenzuordnung vollstaendig.
     *
     * @param list<int> $groupIds
     */
    public function setGroups(int $userId, array $groupIds, string $now): void
    {
        $delete = $this->pdo->prepare('DELETE FROM user_group_members WHERE user_id = ?');
        $delete->execute([$userId]);
        if ($groupIds === []) {
            return;
        }
        $insert = $this->pdo->prepare('INSERT INTO user_group_members (user_id, group_id, created_at) VALUES (?, ?, ?)');
        foreach ($groupIds as $groupId) {
            $insert->execute([$userId, $groupId, $now]);
        }
    }

    public function registerFailure(int $id, int $attempts, ?string $lockedUntil, string $now): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$attempts, $lockedUntil, $now, $id]);
    }

    public function clearFailures(int $id, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $now, $id]);
    }

    /**
     * Zahl der aktiven Benutzer, die ein Recht besitzen (Schutz vor Selbstaussperrung).
     */
    public function countActiveWithPermission(string $permission, ?int $excludeUserId = null): int
    {
        $sql = 'SELECT COUNT(DISTINCT u.id) FROM users u'
            . ' JOIN user_group_members m ON m.user_id = u.id'
            . ' JOIN user_group_permissions p ON p.group_id = m.group_id'
            . ' WHERE u.is_active = 1 AND p.permission = ?';
        $params = [$permission];
        if ($excludeUserId !== null) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeUserId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, list<array{id:int,code:string,label:string}>>
     */
    private function groupMap(): array
    {
        $rows = $this->pdo->query(
            'SELECT m.user_id, g.id, g.code, g.label FROM user_group_members m'
            . ' JOIN user_groups g ON g.id = m.group_id ORDER BY g.sort_order, g.label'
        )->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
            ];
        }
        return $map;
    }

    /**
     * @return array<int, list<string>>
     */
    private function permissionMap(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT m.user_id, p.permission FROM user_group_members m'
            . ' JOIN user_group_permissions p ON p.group_id = m.group_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            if (is_string($row['permission'])) {
                $map[(int) $row['user_id']][] = $row['permission'];
            }
        }
        return $map;
    }
}
