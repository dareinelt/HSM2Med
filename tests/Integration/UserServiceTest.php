<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\User\AuthException;
use App\User\Permission;
use App\User\UserException;
use App\User\UserInput;
use App\User\UserService;
use DateTimeImmutable;

/**
 * Benutzerverwaltung gegen die echte Datenbank.
 *
 * Schwerpunkte: Kennwoerter liegen nur als Hash vor, Benutzer werden nie geloescht, die letzte
 * Person mit dem Recht "Benutzerverwaltung" kann sich nicht aussperren, und die Sperre nach
 * Fehlanmeldungen greift und laeuft wieder ab.
 */
final class UserServiceTest extends DatabaseTestCase
{
    private UserService $service;
    private UserRepository $users;
    private GroupRepository $groups;

    public function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $this->groups = new GroupRepository($this->pdo);
        $this->service = new UserService($this->pdo, $this->users, $this->groups, $this->clock);
    }

    public function testMigrationProvidesTheThreeRequiredGroups(): void
    {
        $codes = array_column($this->service->groups(), 'code');
        $this->assertSame(['admin', 'mfa', 'arzt'], $codes);

        foreach ($this->service->groups() as $group) {
            $this->assertTrue($group['is_system'], 'Vorgegebene Gruppe: ' . $group['code']);
            $this->assertTrue($group['permissions'] !== [], 'Gruppe ohne Rechte: ' . $group['code']);
        }

        $byCode = array_column($this->service->groups(), null, 'code');
        $all = Permission::all();
        sort($all);
        $this->assertSame($all, $byCode['admin']['permissions']);
        $this->assertHasPermission(Permission::USERS, $byCode['admin']['permissions']);
        $this->assertHasNoPermission(Permission::USERS, $byCode['mfa']['permissions']);
        $this->assertHasNoPermission(Permission::USERS, $byCode['arzt']['permissions']);
        $this->assertHasPermission(Permission::PATIENTS, $byCode['mfa']['permissions']);
        $this->assertHasPermission(Permission::PATIENTS, $byCode['arzt']['permissions']);
        $this->assertHasNoPermission(Permission::LETTER_TEMPLATES, $byCode['mfa']['permissions']);
        $this->assertHasPermission(Permission::LETTER_TEMPLATES, $byCode['arzt']['permissions']);
    }

    public function testSeedAdminCreatesTheAccountOnce(): void
    {
        $first = $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $this->assertTrue($first['created']);
        $this->assertFalse($first['password_reset']);

        $second = $this->service->seedAdmin('admin', 'ein-anderes-kennwort');
        $this->assertFalse($second['created']);
        $this->assertFalse($second['password_reset']);
        $this->assertSame($first['user_id'], $second['user_id']);
        $this->assertSame(1, $this->users->count(), 'Der Administrator darf nur einmal angelegt werden.');

        // Das Kennwort des zweiten Aufrufs gilt nicht: der erste Wert bleibt bestehen.
        $this->assertSame('admin', $this->service->authenticate('admin', 'admin-kennwort-2026')->username);
        $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('admin', 'ein-anderes-kennwort'));
    }

    public function testSeedAdminCanResetThePassword(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $result = $this->service->seedAdmin('admin', 'zweites-kennwort-2026', true);

        $this->assertFalse($result['created']);
        $this->assertTrue($result['password_reset']);
        $this->assertSame('admin', $this->service->authenticate('admin', 'zweites-kennwort-2026')->username);
    }

    public function testSeedAdminValidatesItsEnvironmentValues(): void
    {
        $this->assertThrows(UserException::class, fn () => $this->service->seedAdmin('ab', 'admin-kennwort-2026'));
        $this->assertThrows(UserException::class, fn () => $this->service->seedAdmin('admin', 'kurz'));
        $this->assertSame(0, $this->users->count());
    }

    public function testAdminHasAllRightsAfterSeeding(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');

        $this->assertTrue($admin->isActive);
        $this->assertSame(['admin'], $admin->groupCodes());
        $this->assertSame('Admin', $admin->groupText());
        $this->assertTrue($admin->mayManageUsers());
        foreach (Permission::all() as $permission) {
            $this->assertTrue($admin->hasPermission($permission), 'Fehlendes Recht: ' . $permission);
        }
    }

    public function testPasswordIsStoredAsHashOnly(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $hash = (string) $this->pdo->query('SELECT password_hash FROM users WHERE username = \'admin\'')->fetchColumn();

        $this->assertNotSame('admin-kennwort-2026', $hash);
        $this->assertNotContains('admin-kennwort-2026', $hash);
        $this->assertTrue(password_verify('admin-kennwort-2026', $hash));
        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);
        $this->assertNotContains('password', $hash, 'Der Hash darf kein Klartextkennwort enthalten.');
    }

    public function testCreateUserWithGroups(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $mfa = $this->groupByCode('mfa');

        $id = $this->service->create(
            UserInput::fromPost([
                'username' => 'MFA.Anna',
                'display_name' => 'Anna Beispiel',
                'is_active' => '1',
                'groups' => [(string) $mfa],
            ]),
            'anna-kennwort-2026',
        );

        $user = $this->service->user($id);
        $this->assertTrue($user !== null);
        $this->assertSame('mfa.anna', $user->username);
        $this->assertSame('Anna Beispiel', $user->displayName);
        $this->assertTrue($user->hasPermission(Permission::IMPORT));
        $this->assertFalse($user->mayManageUsers());
        $this->assertSame('MFA', $user->groupText());
    }

    public function testCreateRejectsDuplicateUsernameAndUnknownGroup(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');

        $this->assertThrows(
            UserException::class,
            fn () => $this->service->create(
                UserInput::fromPost(['username' => 'admin', 'display_name' => 'Zweiter Admin']),
                'zweites-kennwort-2026',
            ),
        );
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->create(
                UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna', 'groups' => ['999']]),
                'anna-kennwort-2026',
            ),
        );
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->create(
                UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna']),
                'kurz',
            ),
        );
        $this->assertSame(1, $this->users->count(), 'Ungueltige Eingaben duerfen nichts anlegen.');
    }

    public function testUpdateChangesGroupsAndBlocksSelfDeactivation(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $mfa = $this->groupByCode('mfa');
        $arzt = $this->groupByCode('arzt');

        $id = $this->service->create(
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel', 'is_active' => '1', 'groups' => [(string) $mfa]]),
            'anna-kennwort-2026',
        );

        $this->service->update(
            $id,
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel', 'is_active' => '1', 'groups' => [(string) $arzt, (string) $mfa]]),
            $admin->id,
        );
        // Reihenfolge nach sort_order der Gruppen: MFA (20) vor Arzt (30).
        $this->assertSame('MFA, Arzt', $this->service->user($id)->groupText());

        // Das eigene Konto laesst sich nicht deaktivieren.
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->update(
                $admin->id,
                UserInput::fromPost(['username' => 'admin', 'display_name' => 'Administrator', 'is_active' => '0', 'groups' => [$this->groupByCode('admin')]]),
                $admin->id,
            ),
        );
        $this->assertTrue($this->service->user($admin->id)->isActive);

        // Ein anderer Administrator darf das Konto deaktivieren.
        $second = $this->service->create(
            UserInput::fromPost(['username' => 'admin2', 'display_name' => 'Zweiter Admin', 'is_active' => '1', 'groups' => [(string) $this->groupByCode('admin')]]),
            'admin2-kennwort-2026',
        );
        $this->service->update(
            $id,
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel', 'is_active' => '0', 'groups' => [(string) $mfa]]),
            $admin->id,
        );
        $this->assertFalse($this->service->user($id)->isActive);
        $this->assertTrue($this->service->user($second)->isActive);
    }

    public function testDeactivatedUserCannotLoginAndKeepsData(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $id = $this->service->create(
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel', 'is_active' => '1', 'groups' => [(string) $this->groupByCode('mfa')]]),
            'anna-kennwort-2026',
        );
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $id);

        $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('anna', 'anna-kennwort-2026'));
        $this->assertTrue($this->service->user($id) !== null, 'Deaktivierte Konten bleiben erhalten.');
    }

    public function testFailedLoginsLockTheAccountAndExpire(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');

        for ($attempt = 1; $attempt < UserService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $error = $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('admin', 'falsch'));
            $this->assertContains('falsch', $error->getMessage());
        }

        // Der letzte Fehlversuch sperrt das Konto – auch mit richtigem Kennwort.
        $error = $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('admin', 'falsch'));
        $this->assertContains('gesperrt', $error->getMessage());
        $this->assertContains('gesperrt', $this->assertThrows(
            AuthException::class,
            fn () => $this->service->authenticate('admin', 'admin-kennwort-2026'),
        )->getMessage());

        // Nach Ablauf der Sperre gilt das richtige Kennwort wieder.
        $this->clock->set(new DateTimeImmutable('2026-10-07 08:16:00'));
        $this->assertSame('admin', $this->service->authenticate('admin', 'admin-kennwort-2026')->username);
        $this->assertSame(0, (int) $this->pdo->query('SELECT failed_attempts FROM users WHERE username = \'admin\'')->fetchColumn());
    }

    public function testUnknownUsernameAndEmptyUsernameFailAlike(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');

        $unknown = $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('gibtsnicht', 'irgendwas'));
        $empty = $this->assertThrows(AuthException::class, fn () => $this->service->authenticate('', 'irgendwas'));
        $this->assertSame($unknown->getMessage(), $empty->getMessage());
        $this->assertSame('Benutzername oder Kennwort ist falsch.', $unknown->getMessage());
    }

    public function testSetPasswordUnlocksAndRejectsShortValues(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $id = $this->service->create(
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna', 'is_active' => '1', 'groups' => [(string) $this->groupByCode('mfa')]]),
            'anna-kennwort-2026',
        );

        $this->assertThrows(UserException::class, fn () => $this->service->setPassword($id, 'kurz'));
        $this->assertThrows(UserException::class, fn () => $this->service->setPassword(999, 'anna-kennwort-2026'));

        $this->pdo->exec('UPDATE users SET failed_attempts = 4, locked_until = \'2026-10-07 09:00:00\' WHERE id = ' . $id);
        $this->service->setPassword($id, 'neues-kennwort-2026');

        $this->assertSame('anna', $this->service->authenticate('anna', 'neues-kennwort-2026')->username);
        $this->assertNull($this->pdo->query('SELECT locked_until FROM users WHERE id = ' . $id)->fetchColumn() ?: null);
    }

    public function testSaveGroupReplacesThePermissionMatrix(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $arzt = $this->groupByCode('arzt');

        $this->service->saveGroup($arzt, 'Ärztlicher Dienst', 'Beschreibung', [Permission::DASHBOARD, 'unbekannt', Permission::REPORTS, Permission::REPORTS]);

        $group = $this->service->group($arzt);
        $this->assertSame('Ärztlicher Dienst', $group['label']);
        $this->assertSame('Beschreibung', $group['description']);
        $this->assertSame([Permission::DASHBOARD, Permission::REPORTS], $group['permissions']);

        $this->assertThrows(UserException::class, fn () => $this->service->saveGroup($arzt, '  ', '', []));
        $this->assertThrows(UserException::class, fn () => $this->service->saveGroup(999, 'Unbekannt', '', []));
    }

    public function testTheLastUserAdministratorCannotBeLockedOut(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $adminGroup = $this->groupByCode('admin');

        // Recht aus der Gruppe entfernen: nicht erlaubt, solange niemand sonst es hat.
        $error = $this->assertThrows(
            UserException::class,
            fn () => $this->service->saveGroup($adminGroup, 'Admin', 'Test', [Permission::DASHBOARD]),
        );
        $this->assertContains('Benutzerverwaltung', implode(' ', $error->fieldErrors()));
        $this->assertHasPermission(Permission::USERS, $this->service->group($adminGroup)['permissions']);

        // Das eigene Konto auf eine Gruppe ohne dieses Recht umstellen: ebenfalls nicht erlaubt.
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->update(
                $admin->id,
                UserInput::fromPost(['username' => 'admin', 'display_name' => 'Administrator', 'is_active' => '1', 'groups' => []]),
                $admin->id,
            ),
        );
        $this->assertSame(['admin'], $this->service->user($admin->id)->groupCodes());

        // Mit einer zweiten Person mit diesem Recht ist beides moeglich.
        $this->service->create(
            UserInput::fromPost(['username' => 'admin2', 'display_name' => 'Zweiter Admin', 'is_active' => '1', 'groups' => [(string) $adminGroup]]),
            'admin2-kennwort-2026',
        );
        $this->service->saveGroup($adminGroup, 'Admin', 'Test', [Permission::USERS, Permission::DASHBOARD]);
        $this->assertSame([Permission::DASHBOARD, Permission::USERS], $this->service->group($adminGroup)['permissions']);

        $this->service->update(
            $admin->id,
            UserInput::fromPost(['username' => 'admin', 'display_name' => 'Administrator', 'is_active' => '1', 'groups' => []]),
            $admin->id,
        );
        $this->assertSame([], $this->service->user($admin->id)->groupCodes());
        $this->assertFalse($this->service->user($admin->id)->mayManageUsers());
    }

    public function testCreateGroupStartsWithNoPermissions(): void
    {
        $id = $this->service->createGroup('pflege', 'Pflege', 'Stationäre Pflege');

        $group = $this->service->group($id);
        $this->assertSame('pflege', $group['code']);
        $this->assertSame('Pflege', $group['label']);
        $this->assertSame([], $group['permissions'], 'Neue Gruppen starten ohne Rechte.');

        $this->assertThrows(UserException::class, fn () => $this->service->createGroup('1pflege', 'Pflege', ''));
        $this->assertThrows(UserException::class, fn () => $this->service->createGroup('pflege', 'Pflege', ''));
        $this->assertThrows(UserException::class, fn () => $this->service->createGroup('pflege2', '  ', ''));
        $this->assertSame(4, count($this->service->groups()));
    }

    public function testSystemGroupsCannotBeDeleted(): void
    {
        foreach ($this->service->groups() as $group) {
            $this->assertThrows(
                UserException::class,
                fn () => $this->service->deleteGroup($group['id']),
                'Vorgegebene Gruppe wurde geloescht: ' . $group['code'],
            );
        }
        $this->assertSame(3, count($this->service->groups()));

        $id = $this->service->createGroup('pflege', 'Pflege', '');
        $this->service->deleteGroup($id);
        $this->assertNull($this->service->group($id));
        $this->assertThrows(UserException::class, fn () => $this->service->deleteGroup($id));
    }

    public function testUsersAreListedAlphabeticallyAndNeverDeleted(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $mfa = $this->groupByCode('mfa');
        foreach (['zoe', 'anna'] as $username) {
            $this->service->create(
                UserInput::fromPost(['username' => $username, 'display_name' => ucfirst($username), 'is_active' => '1', 'groups' => [(string) $mfa]]),
                $username . '-kennwort-2026',
            );
        }

        $names = array_map(static fn ($user): string => $user->username, $this->service->all());
        $this->assertSame(['admin', 'anna', 'zoe'], $names);
        $this->assertSame(3, count($names));
    }

    public function testGroupMembersAreCounted(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $byCode = array_column($this->service->groups(), null, 'code');
        $this->assertSame(1, $byCode['admin']['member_count']);
        $this->assertSame(0, $byCode['mfa']['member_count']);

        $this->service->create(
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna', 'is_active' => '1', 'groups' => [(string) $byCode['mfa']['id']]]),
            'anna-kennwort-2026',
        );
        $byCode = array_column($this->service->groups(), null, 'code');
        $this->assertSame(1, $byCode['mfa']['member_count']);
    }

    public function testUpdateRejectsDuplicateUsername(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $mfa = $this->groupByCode('mfa');
        $id = $this->service->create(
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna', 'is_active' => '1', 'groups' => [(string) $mfa]]),
            'anna-kennwort-2026',
        );

        $this->assertThrows(
            UserException::class,
            fn () => $this->service->update(
                $id,
                UserInput::fromPost(['username' => 'admin', 'display_name' => 'Anna', 'is_active' => '1', 'groups' => [(string) $mfa]]),
            ),
        );
        // Der eigene Name bleibt zulaessig.
        $this->service->update(
            $id,
            UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna Beispiel', 'is_active' => '1', 'groups' => [(string) $mfa]]),
        );
        $this->assertSame('Anna Beispiel', $this->service->user($id)->displayName);

        $this->assertThrows(
            UserException::class,
            fn () => $this->service->update(
                999,
                UserInput::fromPost(['username' => 'anna', 'display_name' => 'Anna', 'is_active' => '1', 'groups' => []]),
            ),
        );
    }

    private function groupByCode(string $code): int
    {
        foreach ($this->service->groups() as $group) {
            if ($group['code'] === $code) {
                return $group['id'];
            }
        }
        $this->fail('Gruppe fehlt: ' . $code);
    }
    /**
     * @param list<string> $permissions
     */
    private function assertHasPermission(string $permission, array $permissions): void
    {
        $this->assertTrue(in_array($permission, $permissions, true), 'Recht fehlt: ' . $permission);
    }

    /**
     * @param list<string> $permissions
     */
    private function assertHasNoPermission(string $permission, array $permissions): void
    {
        $this->assertFalse(in_array($permission, $permissions, true), 'Recht unerwartet: ' . $permission);
    }

}
