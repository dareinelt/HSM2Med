<?php

declare(strict_types=1);

namespace App\Http;

use App\Application;
use App\Http\Controller\AccountController;
use App\Http\Controller\DashboardController;
use App\Http\Controller\ImportController;
use App\Http\Controller\ImportLogController;
use App\Http\Controller\LetterController;
use App\Http\Controller\LetterTemplateController;
use App\Http\Controller\LoginController;
use App\Http\Controller\PatientCardController;
use App\Http\Controller\PatientCardSettingsController;
use App\Http\Controller\PatientCardTemplateController;
use App\Http\Controller\PatientController;
use App\Http\Controller\ReportController;
use App\Http\Controller\SystemController;
use App\Http\Controller\SystemSettingsController;
use App\Http\Controller\UserController;
use App\Security\Csrf;
use App\Security\SessionManager;
use App\User\Permission;
use Throwable;

/**
 * Verarbeitet eine HTTP-Anfrage: Session, CSRF-Pruefung, Routing und Fehlerbehandlung.
 * Technische Fehler werden protokolliert; Benutzer sehen nur eine neutrale Meldung mit Referenz.
 */
final class Kernel
{
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        // Der aktive Patient wird erst beim Rendern ermittelt (die Sitzung startet spaeter).
        $this->view = new View(
            $app->rootDir . '/templates',
            static function () use ($app): ?array {
                try {
                    // Security fix: ohne gueltige Anmeldung keine Patientendaten im Rahmen
                    // (Anmeldefenster, Fehlerseiten), auch nicht im Quelltext der Seite.
                    return $app->auth()->check() ? $app->activePatientSummary() : null;
                } catch (Throwable) {
                    return null;
                }
            },
            static function () use ($app): ?\App\User\User {
                try {
                    return $app->auth()->user();
                } catch (Throwable) {
                    return null;
                }
            },
        );
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->path === '/health') {
                return (new SystemController($this->app, $this->view))->health();
            }
            SessionManager::start($this->app->config->dataDir . '/sessions', $this->app->config->sessionSecureCookie);
            if ($request->method === 'POST' && !Csrf::isValid($request->post['_csrf'] ?? null)) {
                throw new HttpException(400, 'Die Sitzung ist abgelaufen oder die Anfrage ist ungültig. Bitte die Seite neu laden und erneut versuchen.');
            }

            // An- und Abmeldung sind ohne Anmeldung erreichbar; alles andere ist gesperrt.
            if ($request->path === '/login' || $request->path === '/logout') {
                return $this->router()->dispatch($request);
            }
            $denied = $this->accessDenied($request);
            if ($denied !== null) {
                return $denied;
            }
            if ($this->requiresPatientSelection($request)) {
                SessionManager::flash('info', 'Der Patientenvorgang ist führend: Bitte zuerst einen Patienten auswählen oder neu anlegen.');
                return Response::redirect('/patients');
            }
            return $this->router()->dispatch($request);
        } catch (HttpException $e) {
            return $this->error($e->status, $e->getMessage(), null);
        } catch (Throwable $e) {
            $reference = $this->app->logger()->error('Unbehandelter Fehler', ['path' => $request->path], $e);
            $details = $this->app->config->isProduction() ? null : $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
            return $this->error(500, 'Es ist ein technischer Fehler aufgetreten. Referenz: ' . $reference, $details, $reference);
        }
    }

    /**
     * Zugangsschutz: Ohne Anmeldung wird die Oberflaeche geladen und das Anmeldefenster
     * darueber gelegt; mit Anmeldung entscheidet die Rechtematrix der Gruppen ueber den Zugriff.
     *
     * Geprueft wird ueber Permission::forPath() – dieselbe Zuordnung, mit der die Oberflaeche
     * nicht erlaubte Ziele ausblendet. Anzeige und Sperre koennen damit nicht auseinanderlaufen.
     */
    private function accessDenied(Request $request): ?Response
    {
        $auth = $this->app->auth();
        if (!$auth->check()) {
            if ($request->method !== 'GET' && $request->method !== 'HEAD') {
                SessionManager::flash('info', 'Bitte zuerst anmelden.');
                return Response::redirect('/login');
            }
            return (new LoginController($this->app, $this->view))->locked($request);
        }

        $permission = Permission::forPath($request->path);
        if (!$auth->can($permission)) {
            SessionManager::flash(
                'error',
                sprintf(
                    'Für den Bereich "%s" fehlt die Berechtigung. Bitte an eine Person mit entsprechender Gruppe wenden.',
                    Permission::label((string) $permission),
                ),
            );
            return Response::redirect('/');
        }
        return null;
    }

    /**
     * Der Patientenvorgang ist fuehrend: patientenbezogene Vorgaenge (Import, Patientenausweis,
     * Brief) sind ohne ausgewaehlten Patienten gesperrt. Listen und Berichte bleiben erreichbar.
     */
    public function requiresPatientSelection(Request $request): bool
    {
        return $this->router()->requiresPatient($request) && !$this->app->activePatient()->isSelected();
    }

    private function router(): Router
    {
        $dashboard = new DashboardController($this->app, $this->view);
        $import = new ImportController($this->app, $this->view);
        $reports = new ReportController($this->app, $this->view);
        $imports = new ImportLogController($this->app, $this->view);
        $system = new SystemController($this->app, $this->view);
        $systemSettings = new SystemSettingsController($this->app, $this->view);
        $cards = new PatientCardController($this->app, $this->view);
        $cardSettings = new PatientCardSettingsController($this->app, $this->view);
        $patients = new PatientController($this->app, $this->view);
        $letters = new LetterController($this->app, $this->view);
        $letterTemplates = new LetterTemplateController($this->app, $this->view);
        $cardTemplates = new PatientCardTemplateController($this->app, $this->view);
        $login = new LoginController($this->app, $this->view);
        $account = new AccountController($this->app, $this->view);
        $users = new UserController($this->app, $this->view);

        $router = new Router();
        // Anmeldung: einzige Routen ohne Recht. Die Anmeldung selbst prueft Kennwort und Sperre.
        $router->get('/login', $login->form(...));
        $router->post('/login', $login->login(...));
        $router->post('/logout', $login->logout(...));
        // Eigenes Kennwort: bewusst ohne Recht – jede angemeldete Person darf es aendern.
        $router->get('/account/password', $account->form(...));
        $router->post('/account/password', $account->change(...));
        $router->get('/', $dashboard->index(...), Permission::DASHBOARD);
        // Patientenvorgang fuehrend: die patientenbezogenen Vorgaenge setzen eine
        // Patientenauswahl voraus (Import, Ausweis erstellen, Brief erstellen).
        $router->get('/import', $import->form(...), Permission::IMPORT, true);
        $router->post('/import', $import->upload(...), Permission::IMPORT, true);
        $router->get('/import/{token}', $import->preview(...), Permission::IMPORT);
        $router->post('/import/{token}/commit', $import->commit(...), Permission::IMPORT);
        $router->post('/import/{token}/cancel', $import->cancel(...), Permission::IMPORT);
        $router->get('/reports', $reports->index(...), Permission::REPORTS);
        $router->get('/reports/{id}', $reports->show(...), Permission::REPORTS);
        $router->get('/reports/{id}/pdf', $reports->pdf(...), Permission::REPORTS);
        $router->get('/imports', $imports->index(...), Permission::IMPORTS);
        $router->get('/imports/{id}', $imports->show(...), Permission::IMPORTS);
        $router->get('/patients', $patients->index(...), Permission::PATIENTS);
        $router->get('/patients/new', $patients->newForm(...), Permission::PATIENTS);
        $router->post('/patients', $patients->create(...), Permission::PATIENTS);
        $router->post('/patients/select/clear', $patients->clearActive(...), Permission::PATIENTS);
        $router->post('/patients/{id}/select', $patients->select(...), Permission::PATIENTS);
        $router->get('/patients/{id}/edit', $patients->editForm(...), Permission::PATIENTS);
        $router->post('/patients/{id}', $patients->update(...), Permission::PATIENTS);
        $router->get('/patients/{id}/records/{slug}', $patients->recordForm(...), Permission::PATIENTS);
        $router->post('/patients/{id}/records/{slug}', $patients->saveRecord(...), Permission::PATIENTS);
        $router->post('/patients/{id}/records/{slug}/prefill', $patients->prefillRecord(...), Permission::PATIENTS);
        $router->get('/patients/{id}', $patients->show(...), Permission::PATIENTS);
        $router->get('/patient-cards', $cards->index(...), Permission::PATIENT_CARDS);
        $router->get('/patient-cards/new', $cards->selectReport(...), Permission::PATIENT_CARDS, true);
        $router->get('/patient-cards/settings', $cardSettings->index(...), Permission::PATIENT_CARD_SETTINGS);
        $router->get('/patient-cards/settings/logo', $cardSettings->logo(...), Permission::PATIENT_CARD_SETTINGS);
        $router->post('/patient-cards/settings', $cardSettings->save(...), Permission::PATIENT_CARD_SETTINGS);
        $router->get('/patient-cards/patients/{patient}', $cards->patient(...), Permission::PATIENT_CARDS, true);
        $router->get('/patient-cards/reports/{id}', $cards->wizard(...), Permission::PATIENT_CARDS, true);
        $router->post('/patient-cards/reports/{id}', $cards->generate(...), Permission::PATIENT_CARDS, true);
        $router->get('/patient-cards/{id}', $cards->show(...), Permission::PATIENT_CARDS);
        $router->get('/patient-cards/{id}/pdf', $cards->pdf(...), Permission::PATIENT_CARDS);
        $router->get('/system/logs', $system->logs(...), Permission::LOGS);
        $router->get('/system/settings', $systemSettings->index(...), Permission::SYSTEM_SETTINGS);
        $router->get('/system/settings/logo', $systemSettings->logo(...), Permission::SYSTEM_SETTINGS);
        $router->post('/system/settings', $systemSettings->save(...), Permission::SYSTEM_SETTINGS);
        $router->get('/system/letter-templates', $letterTemplates->editor(...), Permission::LETTER_TEMPLATES);
        $router->post('/system/letter-templates', $letterTemplates->save(...), Permission::LETTER_TEMPLATES);
        $router->post('/system/letter-templates/preview', $letterTemplates->preview(...), Permission::LETTER_TEMPLATES);
        $router->get('/system/letter-templates/source', $letterTemplates->source(...), Permission::LETTER_TEMPLATES);
        $router->get('/system/letter-templates/versions/{id}', $letterTemplates->version(...), Permission::LETTER_TEMPLATES);
        // Ausweisvorlagen: derselbe Vorlageneditor wie fuer die Briefe.
        $router->get('/system/patient-card-templates', $cardTemplates->editor(...), Permission::PATIENT_CARD_TEMPLATES);
        $router->post('/system/patient-card-templates', $cardTemplates->save(...), Permission::PATIENT_CARD_TEMPLATES);
        $router->post('/system/patient-card-templates/preview', $cardTemplates->preview(...), Permission::PATIENT_CARD_TEMPLATES);
        $router->get('/system/patient-card-templates/versions/{id}', $cardTemplates->version(...), Permission::PATIENT_CARD_TEMPLATES);
        // Benutzerverwaltung: Benutzer, Gruppen und die Rechtematrix.
        $router->get('/system/users', $users->index(...), Permission::USERS);
        $router->get('/system/users/new', $users->newForm(...), Permission::USERS);
        $router->post('/system/users', $users->create(...), Permission::USERS);
        $router->get('/system/users/groups', $users->groups(...), Permission::USERS);
        $router->post('/system/users/groups', $users->createGroup(...), Permission::USERS);
        $router->get('/system/users/groups/{id}', $users->groupForm(...), Permission::USERS);
        $router->post('/system/users/groups/{id}', $users->saveGroup(...), Permission::USERS);
        $router->post('/system/users/groups/{id}/delete', $users->deleteGroup(...), Permission::USERS);
        $router->get('/system/users/{id}/edit', $users->editForm(...), Permission::USERS);
        $router->post('/system/users/{id}/password', $users->setPassword(...), Permission::USERS);
        $router->post('/system/users/{id}', $users->update(...), Permission::USERS);
        $router->get('/letters', $letters->index(...), Permission::LETTERS);
        $router->get('/letters/new', $letters->newLetter(...), Permission::LETTERS, true);
        $router->post('/letters', $letters->create(...), Permission::LETTERS, true);
        $router->get('/letters/patients/{patient}', $letters->patient(...), Permission::LETTERS, true);
        $router->get('/letters/{id}', $letters->show(...), Permission::LETTERS);
        $router->get('/letters/{id}/pdf', $letters->pdf(...), Permission::LETTERS);
        $router->get('/letters/{id}/reproduce', $letters->reproduce(...), Permission::LETTERS);
        $router->post('/letters/{id}/regenerate', $letters->regenerate(...), Permission::LETTERS);
        $router->get('/system', $system->index(...), Permission::SYSTEM);
        return $router;
    }

    private function error(int $status, string $message, ?string $details, ?string $reference = null): Response
    {
        try {
            $title = match ($status) {
                400 => 'Ungültige Anfrage',
                404 => 'Nicht gefunden',
                405 => 'Methode nicht erlaubt',
                default => 'Fehler',
            };
            return Response::html($this->view->render('error', [
                'title' => $title,
                'status' => $status,
                'message' => $message,
                'details' => $details,
                'reference' => $reference,
            ]), $status);
        } catch (Throwable) {
            return new Response('Fehler ' . $status, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }
}
