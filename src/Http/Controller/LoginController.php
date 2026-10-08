<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Security\SessionManager;
use App\User\AuthException;
use App\User\Permission;
use App\User\UserInput;

/**
 * Anmeldung und Abmeldung.
 *
 * Die Anmeldung ist die einzige Stelle ohne Recht. Solange niemand angemeldet ist, laedt der
 * Kernel die Oberflaeche und legt das Anmeldefenster darueber; dieses Fenster ist das einzige
 * bedienbare Element. Fehlermeldungen sind bewusst allgemein gehalten: sie verraten nicht, ob
 * ein Anmeldename existiert.
 */
final class LoginController extends Controller
{
    /**
     * Anmeldefenster hinter der nicht angemeldeten Oberflaeche.
     *
     * @param ?string $error Meldung im Fenster (null = keine)
     */
    public function locked(Request $request, ?string $error = null, string $username = '', ?string $target = null): Response
    {
        return Response::html($this->view->render('login/background', [
            'title' => 'Anmeldung',
        ], Permission::DASHBOARD, [
            'error' => $error,
            'username' => $username,
            'target' => $target ?? $this->safeTarget($request->target()),
        ]));
    }

    public function form(Request $request): Response
    {
        if ($this->app->auth()->check()) {
            return Response::redirect('/');
        }
        return $this->locked($request);
    }

    public function login(Request $request): Response
    {
        $username = $request->post('username');
        $target = $this->safeTarget($request->post('target'));
        $auth = $this->app->auth();

        try {
            $user = $this->app->userService()->authenticate($username, $request->post('password'));
        } catch (AuthException $e) {
            $this->app->logger()->warning('Anmeldung fehlgeschlagen', [
                'benutzer' => UserInput::normalizeUsername($username),
                'grund' => $e->getMessage(),
            ]);
            SessionManager::flash('error', $e->getMessage());
            return $this->locked($request, $e->getMessage(), $username, $target);
        }

        $auth->login($user);
        $this->app->logger()->info('Anmeldung erfolgreich', ['benutzer' => $user->username]);
        SessionManager::flash('success', sprintf('Willkommen, %s. Angemeldet als %s.', $user->displayName, $user->groupText() !== '' ? $user->groupText() : 'ohne Gruppe'));

        // Ziele, die die angemeldete Person nicht erreichen darf, werden nicht angesteuert.
        if ($auth->can(Permission::forPath($target))) {
            return Response::redirect($target);
        }
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $user = $this->app->auth()->user();
        $this->app->auth()->logout();
        if ($user !== null) {
            $this->app->logger()->info('Abmeldung', ['benutzer' => $user->username]);
        }
        SessionManager::flash('info', 'Die Anmeldung wurde beendet.');
        return Response::redirect('/login');
    }

    /**
     * Nur anwendungsinterne Ziele zulassen (kein Open Redirect, keine Anmeldeschleife).
     */
    private function safeTarget(string $target): string
    {
        $target = trim($target);
        // Security fix: auch Backslash- und Steuerzeichen-Varianten ("/\host") abweisen.
        if ($target === '' || !Response::isLocalPath($target)) {
            return '/';
        }
        // Pfad ohne Abfrage pruefen: /login und /logout waeren eine Schleife.
        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/login' || $path === '/logout') {
            return '/';
        }
        return $target;
    }
}
