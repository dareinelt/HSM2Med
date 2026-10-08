<?php

declare(strict_types=1);

namespace App\Http;

use App\Application;
use App\Http\Controller\DashboardController;
use App\Http\Controller\ImportController;
use App\Http\Controller\ImportLogController;
use App\Http\Controller\LetterController;
use App\Http\Controller\LetterTemplateController;
use App\Http\Controller\PatientCardController;
use App\Http\Controller\PatientCardSettingsController;
use App\Http\Controller\PatientController;
use App\Http\Controller\ReportController;
use App\Http\Controller\SystemController;
use App\Security\Csrf;
use App\Security\SessionManager;
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
        $this->view = new View($app->rootDir . '/templates', static function () use ($app): ?array {
            try {
                return $app->activePatientSummary();
            } catch (Throwable) {
                return null;
            }
        });
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
        $cards = new PatientCardController($this->app, $this->view);
        $cardSettings = new PatientCardSettingsController($this->app, $this->view);
        $patients = new PatientController($this->app, $this->view);
        $letters = new LetterController($this->app, $this->view);
        $letterTemplates = new LetterTemplateController($this->app, $this->view);

        $router = new Router();
        $router->get('/', $dashboard->index(...));
        // Patientenvorgang fuehrend: die patientenbezogenen Vorgaenge setzen eine
        // Patientenauswahl voraus (Import, Ausweis erstellen, Brief erstellen).
        $router->get('/import', $import->form(...), true);
        $router->post('/import', $import->upload(...), true);
        $router->get('/import/{token}', $import->preview(...));
        $router->post('/import/{token}/commit', $import->commit(...));
        $router->post('/import/{token}/cancel', $import->cancel(...));
        $router->get('/reports', $reports->index(...));
        $router->get('/reports/{id}', $reports->show(...));
        $router->get('/reports/{id}/pdf', $reports->pdf(...));
        $router->get('/imports', $imports->index(...));
        $router->get('/imports/{id}', $imports->show(...));
        $router->get('/patients', $patients->index(...));
        $router->get('/patients/new', $patients->newForm(...));
        $router->post('/patients', $patients->create(...));
        $router->post('/patients/select/clear', $patients->clearActive(...));
        $router->post('/patients/{id}/select', $patients->select(...));
        $router->get('/patients/{id}/edit', $patients->editForm(...));
        $router->post('/patients/{id}', $patients->update(...));
        $router->get('/patients/{id}/records/{slug}', $patients->recordForm(...));
        $router->post('/patients/{id}/records/{slug}', $patients->saveRecord(...));
        $router->post('/patients/{id}/records/{slug}/prefill', $patients->prefillRecord(...));
        $router->get('/patients/{id}', $patients->show(...));
        $router->get('/patient-cards', $cards->index(...));
        $router->get('/patient-cards/new', $cards->selectReport(...), true);
        $router->get('/patient-cards/settings', $cardSettings->index(...));
        $router->get('/patient-cards/settings/logo', $cardSettings->logo(...));
        $router->post('/patient-cards/settings', $cardSettings->save(...));
        $router->get('/patient-cards/patients/{patient}', $cards->patient(...), true);
        $router->get('/patient-cards/reports/{id}', $cards->wizard(...), true);
        $router->post('/patient-cards/reports/{id}', $cards->generate(...), true);
        $router->get('/patient-cards/{id}', $cards->show(...));
        $router->get('/patient-cards/{id}/pdf', $cards->pdf(...));
        $router->get('/system/logs', $system->logs(...));
        $router->get('/system/letter-templates', $letterTemplates->editor(...));
        $router->post('/system/letter-templates', $letterTemplates->save(...));
        $router->post('/system/letter-templates/preview', $letterTemplates->preview(...));
        $router->get('/system/letter-templates/source', $letterTemplates->source(...));
        $router->get('/system/letter-templates/versions/{id}', $letterTemplates->version(...));
        $router->get('/letters', $letters->index(...));
        $router->get('/letters/new', $letters->newLetter(...), true);
        $router->post('/letters', $letters->create(...), true);
        $router->get('/letters/patients/{patient}', $letters->patient(...), true);
        $router->get('/letters/{id}', $letters->show(...));
        $router->get('/letters/{id}/pdf', $letters->pdf(...));
        $router->get('/letters/{id}/reproduce', $letters->reproduce(...));
        $router->post('/letters/{id}/regenerate', $letters->regenerate(...));
        $router->get('/system', $system->index(...));
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
