<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\AccountController;
use App\Http\Controller\LoginController;
use App\Http\Controller\UserController;
use App\Http\Request;
use App\Http\View;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Security\Auth;
use App\User\Permission;
use App\User\UserInput;
use App\User\UserService;

/**
 * Anmeldefenster und Benutzerverwaltung in der HTML-Schicht.
 *
 * Schuetzt die Zusagen der Oberflaeche: ohne Anmeldung erscheint die gesperrte Anwendung mit
 * dem Anmeldefenster darueber, das Fenster ist das einzige bedienbare Element, und die
 * Benutzerverwaltung zeigt Konten, Gruppen und die Rechtematrix vollstaendig an.
 */
final class LoginViewTest extends DatabaseTestCase
{
    private Application $app;
    private UserService $service;

    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
        $this->service = new UserService(
            $this->pdo,
            new UserRepository($this->pdo),
            new GroupRepository($this->pdo),
            $this->clock,
        );
    }

    public function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testLockedPageShowsOverlayOverBlurredBackground(): void
    {
        $html = $this->login()->locked(new Request('GET', '/'))->body;

        $this->assertContains('class="login-overlay"', $html);
        $this->assertContains('id="login-username"', $html);
        $this->assertContains('id="login-password"', $html);
        $this->assertContains('action="/login"', $html);
        $this->assertContains('name="_csrf"', $html);
        $this->assertContains('Anmeldung', $html);

        // Die Oberflaeche dahinter ist gesperrt: inert fuer Funktionsband, Inhalt und Statuszeile.
        $this->assertContains('is-locked', $html);
        $this->assertContains('inert', $html);
        $this->assertContains('app-chrome', $html);

        // Die Statusleiste nennt die fehlende Anmeldung, aber keine Person.
        $this->assertContains('Nicht angemeldet', $html);
        $this->assertNotContains('action="/logout"', $html);
    }

    public function testLockedPageKeepsTheRequestedTarget(): void
    {
        $html = $this->login()->locked(new Request('GET', '/system/users'))->body;
        $this->assertContains('name="target" value="/system/users"', $html);
    }

    public function testLockedPageNeverCarriesAnExternalTarget(): void
    {
        $html = $this->login()->locked(new Request('GET', '/login', ['target' => 'https://example.org/']))->body;
        $this->assertContains('name="target" value="/"', $html);
        $this->assertNotContains('example.org', $html);
    }

    public function testLockedPageRejectsBackslashTargets(): void
    {
        // "/\\host" wird von Browsern als "//host" gelesen und darf kein Ziel sein.
        $html = $this->login()->locked(new Request('GET', '/login', ['target' => '/\\evil.example']))->body;
        $this->assertContains('name="target" value="/"', $html);
        $this->assertNotContains('evil.example', $html);
    }

    public function testFailedLoginIsReportedWithoutRevealingTheAccount(): void
    {
        $html = $this->login()->login(new Request('POST', '/login', [], [
            'username' => 'gibtsnicht',
            'password' => 'irgendwas-langes',
        ]))->body;

        $this->assertContains('Benutzername oder Kennwort ist falsch.', $html);
        $this->assertContains('value="gibtsnicht"', $html);
        $this->assertNotContains('irgendwas-langes', $html);
    }

    public function testUserListShowsAccountsGroupsAndDefaultPasswordHint(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');

        $html = $this->users()->index(new Request('GET', '/system/users'))->body;

        $this->assertContains('Benutzerverwaltung', $html);
        $this->assertContains('admin', $html);
        $this->assertContains('Administrator', $html);
        // Die Gruppen aus der Migration sind sichtbar.
        foreach (['Admin', 'MFA', 'Arzt'] as $label) {
            $this->assertContains($label, $html);
        }
        // Die Vorgabe aus der .env wird als Warnung gemeldet.
        $this->assertContains('Vorgabewert', $html);
        $this->assertContains('/account/password', $html);
        // Kennwoerter erscheinen nie in der Oberflaeche.
        $this->assertNotContains('admin-kennwort-2026', $html);
        $this->assertNotContains('password_hash', $html);
    }

    public function testUserFormOffersGroupsAndPasswordRules(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $html = $this->users()->newForm(new Request('GET', '/system/users/new'))->body;

        $this->assertContains('name="username"', $html);
        $this->assertContains('name="display_name"', $html);
        $this->assertContains('name="groups[]"', $html);
        $this->assertContains('minlength="' . UserInput::MIN_PASSWORD_LENGTH . '"', $html);
        $this->assertContains('name="_csrf"', $html);
    }

    public function testCreatingAUserWithInvalidValuesKeepsTheInputAndReportsTheError(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $response = $this->users()->create(new Request('POST', '/system/users', [], [
            'username' => 'ab',
            'display_name' => '',
            'password' => 'kurz',
            'repeat_password' => 'kurz',
        ]));

        $this->assertSame(422, $response->status);
        $this->assertContains('Anmeldename', $response->body);
        $this->assertContains('Anzeigename', $response->body);
        $this->assertContains('value="ab"', $response->body);
        $this->assertNotContains('kurz"', $response->body);
    }

    public function testPasswordFormForExistingUserIsSeparateFromTheAccountData(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');

        $html = $this->users()->editForm(new Request('GET', '/system/users/' . $admin->id . '/edit'), ['id' => (string) $admin->id])->body;

        // Eigenes Formular fuer das Kennwort, getrennt von den Kontodaten.
        $this->assertContains('action="/system/users/' . $admin->id . '/password"', $html);
        $this->assertContains('id="new_password"', $html);
        $this->assertContains('id="new_repeat_password"', $html);
        $this->assertContains('Kennwort neu setzen', $html);
        $this->assertContains('value="admin"', $html);
        // Beim Bearbeiten gibt es keine Kennwortfelder im Kontodaten-Formular.
        $this->assertNotContains('id="password"', $html);
        $this->assertNotContains('id="repeat_password"', $html);
    }

    public function testGroupListAndPermissionMatrixShowTheWholeCatalog(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');

        $groups = $this->users()->groups(new Request('GET', '/system/users/groups'))->body;
        $this->assertContains('Gruppen und Rechte', $groups);
        $this->assertContains('name="code"', $groups);
        $this->assertContains('name="label"', $groups);

        foreach ($this->service->groups() as $group) {
            $html = $this->users()->groupForm(
                new Request('GET', '/system/users/groups/' . $group['id']),
                ['id' => (string) $group['id']],
            )->body;

            $this->assertContains('data-matrix', $html, 'Rechtematrix fehlt: ' . $group['code']);
            foreach (Permission::CATALOG as $permission => $label) {
                $this->assertContains('value="' . $permission . '"', $html, 'Recht fehlt: ' . $permission);
                $this->assertContains($label, $html, 'Beschriftung fehlt: ' . $permission);
                $this->assertContains(Permission::description($permission), $html, 'Beschreibung fehlt: ' . $permission);
            }

            // Der aktuelle Stand ist vorbelegt.
            foreach ($group['permissions'] as $permission) {
                $this->assertContains(
                    'value="' . $permission . '" checked',
                    $html,
                    'Recht nicht vorbelegt: ' . $permission,
                );
            }
        }
    }

    public function testAccountPageIsAvailableWithoutAnyPermission(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $auth = new Auth(new UserRepository($this->pdo), $this->clock, 1800);
        $auth->login($admin);

        $view = $this->viewWith($auth);
        $html = (new AccountController($this->app, $view))->form(new Request('GET', '/account/password'))->body;

        $this->assertContains('name="current_password"', $html);
        $this->assertContains('name="password"', $html);
        $this->assertContains('name="repeat_password"', $html);
        $this->assertContains('Administrator', $html);
    }

    public function testStatusBarShowsTheSignedInPersonAndTheLogoutForm(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $auth = new Auth(new UserRepository($this->pdo), $this->clock, 1800);
        $auth->login($admin);

        $html = (new AccountController($this->app, $this->viewWith($auth)))
            ->form(new Request('GET', '/account/password'))->body;

        $this->assertContains('Administrator', $html);
        $this->assertContains('Admin', $html);
        $this->assertContains('action="/logout"', $html);
        $this->assertContains('Benutzerverwaltung', $html);
        $this->assertNotContains('class="login-overlay"', $html);
    }

    public function testRibbonHidesAreasWithoutPermission(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $mfa = $this->service->create(
            UserInput::fromPost([
                'username' => 'anna',
                'display_name' => 'Anna Beispiel',
                'is_active' => '1',
                'groups' => [(string) $this->groupByCode('mfa')],
            ]),
            'anna-kennwort-2026',
        );
        $auth = new Auth(new UserRepository($this->pdo), $this->clock, 1800);
        $auth->login($this->service->user($mfa));

        $html = (new AccountController($this->app, $this->viewWith($auth)))
            ->form(new Request('GET', '/account/password'))->body;

        // Die MFA sieht ihr Tagesgeschaeft, aber weder Vorlagen noch Benutzerverwaltung.
        // (/import ist ohne gewaehlten Patienten gesperrt und erscheint deshalb ohne Verweis.)
        $this->assertContains('href="/letters"', $html);
        $this->assertContains('href="/patients"', $html);
        $this->assertContains('href="/imports"', $html);
        $this->assertNotContains('href="/system/letter-templates"', $html);
        // Kein Verweis in einen Bereich, der nicht geoeffnet werden darf – auch nicht
        // ausserhalb des Funktionsbands (Statusleiste, Seitenkopf).
        $this->assertNotContains('href="/system/users"', $html);
        $this->assertNotContains('Benutzerverwaltung', $html);
    }

    /**
     * Der Kennwortbereich steht allen offen; Verweise in Verwaltungsbereiche duerfen
     * deshalb nur erscheinen, wenn das Recht dazu vorhanden ist.
     */
    public function testAccountPageLinksIntoAdministrationOnlyWithPermission(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $admin = $this->service->authenticate('admin', 'admin-kennwort-2026');
        $auth = new Auth(new UserRepository($this->pdo), $this->clock, 1800);
        $auth->login($admin);

        $html = (new AccountController($this->app, $this->viewWith($auth)))
            ->form(new Request('GET', '/account/password'))->body;

        $this->assertContains('href="/system/users"', $html);
    }

    public function testUnknownUserIsReportedAsNotFound(): void
    {
        $this->service->seedAdmin('admin', 'admin-kennwort-2026');
        $this->assertThrows(
            \App\Http\HttpException::class,
            fn () => $this->users()->editForm(new Request('GET', '/system/users/999/edit'), ['id' => '999']),
        );
        $this->assertThrows(
            \App\Http\HttpException::class,
            fn () => $this->users()->groupForm(new Request('GET', '/system/users/groups/999'), ['id' => '999']),
        );
    }

    private function login(): LoginController
    {
        return new LoginController($this->app, $this->viewWith(new Auth(new UserRepository($this->pdo), $this->clock, 1800)));
    }

    private function users(): UserController
    {
        return new UserController($this->app, $this->viewWith(new Auth(new UserRepository($this->pdo), $this->clock, 1800)));
    }

    private function viewWith(Auth $auth): View
    {
        return new View(
            dirname(__DIR__, 2) . '/templates',
            null,
            static fn () => $auth->user(),
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
}
