<?php

declare(strict_types=1);

namespace App\User;

/**
 * Angemeldete Person mit ihren Gruppen und den daraus abgeleiteten Rechten.
 *
 * Die Rechte sind die Vereinigung der Rechte aller Gruppen. Eine Person ohne Gruppe hat
 * keine Rechte; das Kennwort selbst liegt nie in diesem Objekt, nur der Hash in der Datenbank.
 */
final readonly class User
{
    /**
     * @param list<array{id:int,code:string,label:string}> $groups
     * @param list<string> $permissions
     */
    public function __construct(
        public int $id,
        public string $username,
        public string $displayName,
        public bool $isActive,
        public array $groups,
        public array $permissions,
        public ?string $lastLoginAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row Zeile aus users
     * @param list<array{id:int,code:string,label:string}> $groups
     * @param list<string> $permissions
     */
    public static function fromRow(array $row, array $groups = [], array $permissions = []): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            displayName: (string) $row['display_name'],
            isActive: (int) $row['is_active'] === 1,
            groups: $groups,
            permissions: $permissions,
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    /**
     * Recht auf einen Bereich; ohne Bereichskennung ist der Zugriff frei.
     */
    public function hasPermission(?string $permission): bool
    {
        return $permission === null || $permission === '' || in_array($permission, $this->permissions, true);
    }

    /** Darf diese Person die Benutzerverwaltung oeffnen? */
    public function mayManageUsers(): bool
    {
        return $this->hasPermission(Permission::USERS);
    }

    /**
     * @return list<string>
     */
    public function groupCodes(): array
    {
        return array_map(static fn (array $group): string => $group['code'], $this->groups);
    }

    /**
     * @return list<string>
     */
    public function groupLabels(): array
    {
        return array_map(static fn (array $group): string => $group['label'], $this->groups);
    }

    /** Gruppen als Text fuer Listen ("Admin, MFA"). */
    public function groupText(): string
    {
        return implode(', ', $this->groupLabels());
    }
}
