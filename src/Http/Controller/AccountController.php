<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\SessionManager;
use App\Security\UploadException;
use App\User\User;
use App\User\UserException;
use App\User\UserInput;

/**
 * Eigenes Konto: Kennwort aendern und Unterschrift hinterlegen.
 *
 * Bewusst ohne Recht: jede angemeldete Person darf ihr eigenes Kennwort aendern und ihre eigene
 * Unterschrift pflegen, unabhaengig von ihrer Gruppe. Das bisherige Kennwort ist nachzuweisen;
 * das neue Kennwort wird nie im Klartext gespeichert oder protokolliert.
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

    /**
     * Eigene Unterschrift verwalten (Bild hinterlegen oder entfernen).
     *
     * Bewusst ohne Recht: jede angemeldete Person pflegt ihre eigene Unterschrift. Sie erscheint
     * im Brief unter der Grussformel, darunter immer Titel, Vorname und Name.
     */
    public function signatureForm(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('account/signature', $this->signatureVars($user)));
    }

    public function saveSignature(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $service = $this->app->userService();

        try {
            if ($request->post('remove_signature') === '1') {
                $service->removeSignature($user->id);
                $this->app->logger()->info('Eigene Unterschrift entfernt', ['benutzer' => $user->username]);
                SessionManager::flash('success', 'Die Unterschrift wurde entfernt. Bereits erstellte Briefe behalten ihre Unterschrift.');
                return Response::redirect('/account/signature');
            }
            $service->setSignature($user->id, $request->file('signature'));
        } catch (UploadException $e) {
            $current = $service->user($user->id) ?? $user;
            return Response::html($this->view->render('account/signature', $this->signatureVars(
                $current,
                ['signature' => $e->getMessage()],
            )), 422);
        }

        $this->app->logger()->info('Eigene Unterschrift hinterlegt', ['benutzer' => $user->username]);
        SessionManager::flash('success', 'Die Unterschrift wurde gespeichert. Sie erscheint in neuen Briefen unter der Grußformel.');
        return Response::redirect('/account/signature');
    }

    /**
     * Eigene Unterschrift als Bild (fuer die Vorschau im Formular und im Vorlageneditor).
     */
    public function signatureImage(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw HttpException::notFound('Es ist keine Unterschrift hinterlegt.');
        }
        $signature = $user->signatureId === null
            ? null
            : $this->app->userService()->signatureFile($user->signatureId);
        if ($signature === null) {
            throw HttpException::notFound('Es ist keine Unterschrift hinterlegt.');
        }
        $mime = $signature['mime_type'];
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/bmp'], true)) {
            throw HttpException::notFound('Die hinterlegte Unterschrift kann nicht angezeigt werden.');
        }

        return Response::image($signature['content'], $mime);
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function signatureVars(User $user, array $errors = []): array
    {
        return [
            'title' => 'Unterschrift',
            'user' => $user,
            'signature' => $user->signatureId === null
                ? null
                : $this->app->userService()->signature($user->signatureId),
            'signerName' => $user->signerName(),
            'errors' => $errors,
            'limits' => [
                'bytes' => \App\Security\ImageUploadValidator::MAX_BYTES,
                'dimension' => \App\Security\ImageUploadValidator::MAX_DIMENSION,
            ],
        ];
    }

    /** Hinweis fuer die Anzeige: Mindestlaenge des Kennworts. */
    public static function minimumLength(): int
    {
        return UserInput::MIN_PASSWORD_LENGTH;
    }
}
