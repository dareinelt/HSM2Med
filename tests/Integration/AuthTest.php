<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Security\Auth;
use App\User\Permission;
use App\User\UserException;
use App\User\UserInput;
use App\User\UserService;
use DateTimeImmutable;

/**
 * Anmeldestatus der Sitzung gegen die echte Datenbank.
 *
 * Geprueft werden die Eigenschaften, die die Anmeldung schuetzen: keine Anmeldung ohne
 * Gueltigkeitspruefung, Ablauf nach Ruhezeit, sofortiges Ende bei deaktiviertem Konto und
 * Rechte, die bei jedem Zugriff frisch aus der Datenbank kommen.
 */
final class AuthTest extends DatabaseTestCase
{
    private UserService $service;
    private UserRepository $users;
    private Auth $auth;

    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->users = new UserRepository($this->pdo);
        $this->service = new UserService($this->pdo, $this->users, new GroupRepository($this->pdo), $this->clock);
        $this->auth = new Auth($this->users, $this->clock, 30 * 60);
    }

    public function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testWithoutLoginNoUser(): void
    {
        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->user());
        $this->assertNull($this->auth->id());
        $this->assertFalse($this->auth->can(Permission::USERS));
        // Auch Bereiche ohne Recht bleiben ohne Anmeldung gesperrt.
        $this->assertFalse($this->auth->can(null));
    }

    public function testLoginAndLogout(): void
    {
        $id = $this->createAdmin();

        $user = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $this->auth->login($user);

        $this->assertTrue($this->auth->check());
        $this->assertSame($id, $this->auth->id());
        $this->assertTrue($this->auth->can(Permission::USERS));
        $this->assertFalse($this->auth->can('gibt-es-nicht'));
        $this->assertSame($id, $_SESSION['_auth_user_id']);

        $this->auth->logout();
        $this->assertFalse($this->auth->check());
        $this->assertNull($this->auth->id());
        $this->assertFalse(isset($_SESSION['_auth_user_id']));
    }

    public function testSessionExpiresAfterIdleTime(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));

        // Genau an der Grenze bleibt die Sitzung bestehen …
        $this->clock->set(new DateTimeImmutable('2026-10-07 08:30:00'));
        $this->assertTrue($this->freshAuth()->check());

        // … jede Bedienung verschiebt die Ruhezeit weiter …
        $this->clock->set(new DateTimeImmutable('2026-10-07 08:45:00'));
        $this->assertTrue($this->freshAuth()->check());

        // … und erst danach ist eine erneute Anmeldung noetig.
        $this->clock->set(new DateTimeImmutable('2026-10-07 09:15:01'));
        $this->assertFalse($this->freshAuth()->check());
        $this->assertFalse(isset($_SESSION['_auth_user_id']));
    }

    public function testSessionWithFutureTimestampIsRejected(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));

        $this->clock->set(new DateTimeImmutable('2026-10-07 07:00:00'));
        $this->assertFalse($this->freshAuth()->check(), 'Eine Sitzung aus der Zukunft ist ungueltig.');
    }

    public function testDeactivatedAccountEndsTheSession(): void
    {
        $id = $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));
        $this->assertTrue($this->freshAuth()->check());

        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $id);

        $this->assertFalse($this->freshAuth()->check());
        $this->assertFalse(isset($_SESSION['_auth_user_id']));
    }

    public function testDeletedAccountEndsTheSession(): void
    {
        $id = $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);

        $this->assertFalse($this->freshAuth()->check());
    }

    public function testPermissionsAreReadFreshOnEveryRequest(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));
        $this->assertTrue($this->freshAuth()->can(Permission::IMPORT));

        // Rechte der Gruppe aendern: wirkt ohne erneute Anmeldung.
        $adminGroup = $this->groupByCode('admin');
        $this->service->saveGroup($adminGroup, 'Admin', 'Test', [Permission::USERS, Permission::DASHBOARD]);

        $this->assertTrue($this->freshAuth()->check(), 'Die Anmeldung selbst bleibt bestehen.');
        $this->assertFalse($this->freshAuth()->can(Permission::IMPORT));
        $this->assertTrue($this->freshAuth()->can(Permission::USERS));
        $this->assertTrue($this->freshAuth()->can(Permission::DASHBOARD));
    }

    public function testIdleTimeIsRefreshedByActivity(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));

        foreach (['08:20:00', '08:40:00', '09:00:00'] as $time) {
            $this->clock->set(new DateTimeImmutable('2026-10-07 ' . $time));
            $this->assertTrue($this->freshAuth()->check(), 'Sitzung bei ' . $time . ' abgelaufen.');
        }
    }

    public function testLogoutDiscardsAllSessionData(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));
        $_SESSION['active_patient_id'] = 42;
        $_SESSION['pending_uploads'] = ['abc' => true];
        $_SESSION['_csrf'] = 'alt';

        $this->auth->logout();

        // Kein Patientenbezug und kein Upload darf in die naechste Anmeldung uebergehen.
        $this->assertSame([], $_SESSION);
    }

    public function testIdleTimeoutDiscardsAllSessionData(): void
    {
        $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));
        $_SESSION['active_patient_id'] = 42;

        $this->clock->set(new DateTimeImmutable('2026-10-07 09:00:01'));
        $this->assertFalse($this->freshAuth()->check());
        $this->assertFalse(isset($_SESSION['active_patient_id']));
    }

    public function testLoginStartsWithACleanSession(): void
    {
        $id = $this->createAdmin();
        $_SESSION['active_patient_id'] = 42;
        $_SESSION['pending_uploads'] = ['abc' => true];
        $_SESSION['_csrf'] = 'vor-der-anmeldung';

        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));

        $this->assertSame($id, $this->auth->id());
        $this->assertFalse(isset($_SESSION['active_patient_id']));
        $this->assertFalse(isset($_SESSION['pending_uploads']));
        $this->assertFalse(isset($_SESSION['_csrf']));
    }

    public function testPasswordChangeElsewhereEndsTheSession(): void
    {
        $id = $this->createAdmin();
        $this->auth->login($this->service->authenticate('admin', 'admin-kennwort-2026'));
        $this->assertTrue($this->freshAuth()->check());

        // Kennwort wird in einer anderen Sitzung (oder durch die Verwaltung) geaendert.
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash('ganz-neues-kennwort-2026', PASSWORD_DEFAULT), $id]);

        $this->assertFalse($this->freshAuth()->check());
        $this->assertFalse(isset($_SESSION['_auth_user_id']));
    }

    public function testRefreshCredentialKeepsTheOwnSessionAfterPasswordChange(): void
    {
        $this->createAdmin();
        $user = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $this->auth->login($user);

        $this->service->changeOwnPassword($user, 'admin-kennwort-2026', 'neues-kennwort-2026', 'neues-kennwort-2026');
        $this->freshAuth()->refreshCredential();

        $this->assertTrue($this->freshAuth()->check());
    }

    private function freshAuth(): Auth
    {
        return new Auth($this->users, $this->clock, 30 * 60);
    }

    private function createAdmin(): int
    {
        $result = $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        return $result['user_id'];
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

    public function testOwnPasswordCanBeChangedAndOldOneStopsWorking(): void
    {
        $this->createAdmin();
        $user = $this->service->authenticate('admin', 'admin-kennwort-2026');

        $this->assertThrows(
            UserException::class,
            fn () => $this->service->changeOwnPassword($user, 'falsch-falsch', 'neues-kennwort-2026', 'neues-kennwort-2026'),
        );
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->changeOwnPassword($user, 'admin-kennwort-2026', 'neues-kennwort-2026', 'anders-2026'),
        );
        $this->assertThrows(
            UserException::class,
            fn () => $this->service->changeOwnPassword($user, 'admin-kennwort-2026', 'admin-kennwort-2026', 'admin-kennwort-2026'),
        );

        $this->service->changeOwnPassword($user, 'admin-kennwort-2026', 'neues-kennwort-2026', 'neues-kennwort-2026');
        $this->assertSame('admin', $this->service->authenticate('admin', 'neues-kennwort-2026')->username);
        $this->assertThrows(
            \App\User\AuthException::class,
            fn () => $this->service->authenticate('admin', 'admin-kennwort-2026'),
        );
    }

    public function testUserNameInputIsNormalizedForLogin(): void
    {
        $this->createAdmin();
        $this->assertSame('admin', $this->service->authenticate('  ADMIN ', 'admin-kennwort-2026')->username);
        $this->assertSame('admin', UserInput::normalizeUsername('ADMIN'));
    }
}
