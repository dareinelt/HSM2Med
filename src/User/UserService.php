<?php

declare(strict_types=1);

namespace App\User;

use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Support\Clock;
use PDO;
use Throwable;

/**
 * Benutzerverwaltung und Anmeldung.
 *
 * Regeln:
 *  * Benutzer werden nie geloescht, nur deaktiviert – historische Vorgaenge bleiben zuordenbar.
 *  * Die letzte aktive Person mit dem Recht "Benutzerverwaltung" kann sich nicht selbst
 *    aussperren: weder durch Deaktivieren, noch durch Entfernen des Rechts aus ihrer Gruppe.
 *  * Kennwoerter werden nur als Hash gespeichert (password_hash, PASSWORD_DEFAULT). Nach zu
 *    vielen Fehlanmeldungen wird das Konto zeitweise gesperrt.
 *  * Das Kennwort des Administrators kommt beim ersten Start aus der Umgebung
 *    (ADMIN_USERNAME/ADMIN_PASSWORD) – es steht nie im Quelltext.
 */
final class UserService
{
    /** Fehlanmeldungen bis zur Sperre. */
    public const int MAX_FAILED_ATTEMPTS = 5;

    /** Dauer der Sperre in Minuten. */
    public const int LOCK_MINUTES = 15;

    /** Vergleichswert gegen Zeitunterschiede bei unbekanntem Anmeldenamen (kein echtes Konto). */
    private const string DUMMY_HASH = '$2y$12$2qkI9XT4ussNaL.mPQVnM.eUinzuCODkD6.5I0CHWZ3NlRwgpWUOe';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        return $this->users->all();
    }

    public function user(int $id): ?User
    {
        return $this->users->find($id);
    }

    /**
     * @return list<array{id:int,code:string,label:string,description:string,is_system:bool,member_count:int,permissions:list<string>}>
     */
    public function groups(): array
    {
        return $this->groups->all();
    }

    public function group(int $id): ?array
    {
        return $this->groups->find($id);
    }

    /**
     * Meldet eine Person an. Bei Erfolg werden Fehlversuche und Sperre zurueckgesetzt.
     */
    public function authenticate(string $username, string $password): User
    {
        $now = $this->now();
        $username = UserInput::normalizeUsername($username);
        $row = $username === '' ? null : $this->users->findByUsername($username);

        if ($row === null) {
            // Gleicher Zeitaufwand wie bei bekanntem Anmeldenamen (keine Rueckschluesse).
            password_verify($password, self::DUMMY_HASH);
            throw AuthException::invalidCredentials();
        }

        $id = (int) $row['id'];
        $lockedUntil = $row['locked_until'] === null ? null : (string) $row['locked_until'];
        if ($lockedUntil !== null && $lockedUntil > $now) {
            throw AuthException::locked(self::minutesUntil($lockedUntil, $now));
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            $attempts = (int) $row['failed_attempts'] + 1;
            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $lock = $this->clock->now()->modify('+' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
                $this->users->registerFailure($id, $attempts, $lock, $now);
                throw AuthException::locked(self::LOCK_MINUTES);
            }
            $this->users->registerFailure($id, $attempts, null, $now);
            throw AuthException::invalidCredentials();
        }

        if ((int) $row['is_active'] !== 1) {
            throw AuthException::disabled();
        }

        $this->users->clearFailures($id, $now);
        $user = $this->users->find($id);
        if ($user === null) {
            throw AuthException::invalidCredentials();
        }
        return $user;
    }

    /**
     * Legt einen Benutzer an (Kennwort nur als Hash).
     */
    public function create(UserInput $input, string $password): int
    {
        UserInput::requirePassword($password, $input->username);
        if ($this->users->exists($input->username)) {
            throw UserException::rule('username', 'Dieser Anmeldename ist bereits vergeben.');
        }
        $this->requireKnownGroups($input->groupIds);

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = $this->now();
            $id = $this->users->create(
                $input->username,
                $input->displayName,
                self::hash($password),
                $input->isActive,
                $now,
            );
            $this->users->setGroups($id, $input->groupIds, $now);
            if ($own) {
                $this->pdo->commit();
            }
            return $id;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Aendert Anmeldename, Anzeigename, Aktivzustand und Gruppen eines Benutzers.
     *
     * @param int|null $currentUserId Angemeldete Person (Schutz vor Selbstaussperrung)
     */
    public function update(int $id, UserInput $input, ?int $currentUserId = null): void
    {
        $existing = $this->users->find($id);
        if ($existing === null) {
            throw UserException::rule('username', 'Der Benutzer existiert nicht mehr.');
        }
        if ($this->users->existsOther($input->username, $id)) {
            throw UserException::rule('username', 'Dieser Anmeldename ist bereits vergeben.');
        }
        $this->requireKnownGroups($input->groupIds);

        if ($currentUserId === $id && !$input->isActive) {
            throw UserException::rule('is_active', 'Das eigene Benutzerkonto kann nicht deaktiviert werden.');
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = $this->now();
            $before = $this->userAdministrators();
            $this->users->update($id, $input->username, $input->displayName, $input->isActive, $now);
            $this->users->setGroups($id, $input->groupIds, $now);
            $this->guardUserAdministration($before);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Setzt ein neues Kennwort (Administration) und hebt eine Sperre auf.
     */
    public function setPassword(int $id, string $password): void
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw UserException::rule('password', 'Der Benutzer existiert nicht mehr.');
        }
        UserInput::requirePassword($password, $user->username);
        $this->users->setPassword($id, self::hash($password), $this->now());
    }

    /**
     * Aendert das eigene Kennwort; das bisherige Kennwort ist nachzuweisen.
     */
    public function changeOwnPassword(User $user, string $currentPassword, string $newPassword, string $repeatPassword): void
    {
        $row = $this->users->findByUsername($user->username);
        if ($row === null || !password_verify($currentPassword, (string) $row['password_hash'])) {
            throw UserException::rule('current_password', 'Das bisherige Kennwort ist falsch.');
        }
        if ($newPassword !== $repeatPassword) {
            throw UserException::rule('repeat_password', 'Die Wiederholung stimmt nicht mit dem neuen Kennwort überein.');
        }
        if ($newPassword === $currentPassword) {
            throw UserException::rule('password', 'Das neue Kennwort muss sich vom bisherigen unterscheiden.');
        }
        UserInput::requirePassword($newPassword, $user->username);
        $this->users->setPassword($user->id, self::hash($newPassword), $this->now());
    }

    /**
     * Speichert Name, Beschreibung und Rechtematrix einer Gruppe in einem Schritt.
     *
     * @param list<string> $permissions
     */
    public function saveGroup(int $groupId, string $label, string $description, array $permissions): void
    {
        if ($this->groups->find($groupId) === null) {
            throw UserException::rule('label', 'Die Gruppe existiert nicht mehr.');
        }
        $label = trim($label);
        if ($label === '') {
            throw UserException::rule('label', 'Bitte einen Namen für die Gruppe angeben.');
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $now = $this->now();
            $before = $this->userAdministrators();
            $this->groups->rename($groupId, mb_substr($label, 0, 64), mb_substr(trim($description), 0, 255), $now);
            $this->groups->savePermissions($groupId, $permissions);
            $this->guardUserAdministration($before);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createGroup(string $code, string $label, string $description): int
    {
        $code = mb_strtolower(trim($code));
        $label = trim($label);
        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $code) !== 1) {
            throw UserException::rule('code', 'Die Kennung braucht 3–32 Zeichen: Buchstaben, Ziffern und Unterstrich, Beginn mit einem Buchstaben.');
        }
        if ($label === '') {
            throw UserException::rule('label', 'Bitte einen Namen für die Gruppe angeben.');
        }
        if ($this->groups->codeExists($code)) {
            throw UserException::rule('code', 'Diese Kennung ist bereits vergeben.');
        }

        $now = $this->now();
        $id = $this->groups->create($code, mb_substr($label, 0, 64), mb_substr(trim($description), 0, 255), $this->groups->nextSortOrder(), $now);
        // Neue Gruppen starten ohne Rechte; die Vergabe erfolgt bewusst in der Matrix.
        return $id;
    }

    /**
     * Loescht eine selbst angelegte Gruppe (vorgegebene Gruppen bleiben erhalten).
     */
    public function deleteGroup(int $id): void
    {
        $group = $this->groups->find($id);
        if ($group === null) {
            throw UserException::rule('permissions', 'Die Gruppe existiert nicht mehr.');
        }
        if ($group['is_system']) {
            throw UserException::rule('permissions', 'Vorgegebene Gruppen können nicht gelöscht werden.');
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $before = $this->userAdministrators();
            $this->groups->delete($id);
            $this->guardUserAdministration($before);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Legt den Administrator aus der Umgebung an (idempotent) oder setzt sein Kennwort neu.
     *
     * @return array{created:bool,password_reset:bool,user_id:int}
     */
    public function seedAdmin(string $username, string $password, bool $resetPassword = false): array
    {
        $username = UserInput::normalizeUsername($username);
        if (!UserInput::usernameIsValid($username)) {
            throw UserException::rule('username', 'ADMIN_USERNAME ist ungültig (3–64 Zeichen, Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich).');
        }
        UserInput::requirePassword($password, $username);

        $admin = $this->adminGroupId();
        $existing = $this->users->findByUsername($username);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            if (!$resetPassword) {
                return ['created' => false, 'password_reset' => false, 'user_id' => $id];
            }
            $this->users->setPassword($id, self::hash($password), $this->now());
            $this->users->update($id, $username, (string) $existing['display_name'], true, $this->now());
            return ['created' => false, 'password_reset' => true, 'user_id' => $id];
        }

        $now = $this->now();
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $id = $this->users->create($username, 'Administrator', self::hash($password), true, $now);
            $this->users->setGroups($id, [$admin], $now);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return ['created' => true, 'password_reset' => false, 'user_id' => $id];
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Kennung der vorgegebenen Admin-Gruppe.
     */
    private function adminGroupId(): int
    {
        foreach ($this->groups->all() as $group) {
            if ($group['code'] === 'admin') {
                return $group['id'];
            }
        }
        throw UserException::rule('groups', 'Die Gruppe "Admin" fehlt. Bitte die Migrationen ausführen.');
    }

    /**
     * @param list<int> $groupIds
     */
    private function requireKnownGroups(array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            if ($this->groups->find($groupId) === null) {
                throw UserException::rule('groups', 'Eine gewählte Gruppe existiert nicht mehr. Bitte die Auswahl erneuern.');
            }
        }
    }

    /**
     * Mindestens eine aktive Person muss die Benutzerverwaltung erreichen koennen.
     */
    /**
     * Verhindert, dass die letzte Person mit dem Recht "Benutzerverwaltung" ausgesperrt wird.
     *
     * Geprueft wird nur, ob eine Aenderung dieses Recht entzieht: gab es vorher niemanden mit
     * dem Recht (Erstinstallation ohne Benutzerkonten), gibt es auch nichts zu schuetzen.
     *
     * @param int $before Anzahl der aktiven Personen mit diesem Recht vor der Aenderung
     */
    private function guardUserAdministration(int $before): void
    {
        if ($before === 0 || $this->users->countActiveWithPermission(Permission::USERS) > 0) {
            return;
        }
        throw UserException::rule(
            'groups',
            'Mindestens eine aktive Person braucht die Gruppe mit dem Recht "Benutzerverwaltung".',
        );
    }

    /** Anzahl der aktiven Personen mit dem Recht "Benutzerverwaltung". */
    private function userAdministrators(): int
    {
        return $this->users->countActiveWithPermission(Permission::USERS);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private static function minutesUntil(string $lockedUntil, string $now): int
    {
        $seconds = strtotime($lockedUntil) - strtotime($now);
        return (int) max(1, ceil($seconds / 60));
    }
}
