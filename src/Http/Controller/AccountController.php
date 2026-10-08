<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Security\SessionManager;
use App\User\UserException;
use App\User\UserInput;

/**
 * Eigenes Kennwort aendern.
 *
 * Bewusst ohne Recht: jede angemeldete Person darf ihr eigenes Kennwort aendern, unabhaengig
 * von ihrer Gruppe. Das bisherige Kennwort ist nachzuweisen; das neue Kennwort wird nie im
 * Klartext gespeichert oder protokolliert.
 */
final class AccountController extends Controller
{
    public function form(Request $request): Response
    {
        return Response::html($this->view->render('account/password', [
            'title' => 'Kennwort ändern',
            'errors' => [],
            'message' => null,
        ]));
    }

    public function change(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            $this->app->userService()->changeOwnPassword(
                $user,
                $request->post('current_password'),
                $request->post('password'),
                $request->post('repeat_password'),
            );
        } catch (UserException $e) {
            return Response::html($this->view->render('account/password', [
                'title' => 'Kennwort ändern',
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
            ]), 422);
        }

        // Security fix: neue Sitzungskennung; andere Sitzungen dieses Kontos enden.
        $this->app->auth()->refreshCredential();
        $this->app->logger()->info('Kennwort geändert', ['benutzer' => $user->username]);
        SessionManager::flash('success', 'Das Kennwort wurde geändert.');
        return Response::redirect('/');
    }

    /** Hinweis fuer die Anzeige: Mindestlaenge des Kennworts. */
    public static function minimumLength(): int
    {
        return UserInput::MIN_PASSWORD_LENGTH;
    }
}
