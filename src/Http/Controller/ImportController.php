<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Import\DuplicateImportException;
use App\Import\ImportFailedException;
use App\Report\ReportSummaryBuilder;
use App\Security\SessionManager;
use App\Security\UploadException;
use App\Security\UploadValidator;

/**
 * Upload → Importuebersicht (Vorschau) → endgueltiges Speichern bzw. Verwerfen.
 */
final class ImportController extends Controller
{
    public function form(Request $request): Response
    {
        return Response::html($this->view->render('import/form', [
            'title' => 'Import',
            'maxBytes' => $this->app->config->uploadMaxBytes,
            'error' => null,
        ], 'import'));
    }

    public function upload(Request $request): Response
    {
        $validator = new UploadValidator($this->app->config->uploadMaxBytes);
        try {
            $upload = $validator->validateUpload($request->file('file'));
        } catch (UploadException $e) {
            return Response::html($this->view->render('import/form', [
                'title' => 'Import',
                'maxBytes' => $this->app->config->uploadMaxBytes,
                'error' => $e->getMessage(),
            ], 'import'), 422);
        }
        $token = $this->app->pendingUploads()->store($upload['bytes'], $upload['filename']);
        $_SESSION['pending_uploads'][$token] = true;
        return Response::redirect('/import/' . $token);
    }

    /**
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $token = $params['token'];
        $pending = $this->loadPending($token);
        $analysis = $this->app->importService()->analyze($pending['bytes'], $pending['filename']);
        $builder = new ReportSummaryBuilder($this->app->mapping());

        return Response::html($this->view->render('import/preview', [
            'title' => 'Importübersicht',
            'token' => $token,
            'analysis' => $analysis,
            'snapshot' => $builder->toSnapshot($analysis->summary),
        ], 'import'));
    }

    /**
     * @param array<string, string> $params
     */
    public function commit(Request $request, array $params): Response
    {
        $token = $params['token'];
        $pending = $this->loadPending($token);
        $service = $this->app->importService();
        $analysis = $service->analyze($pending['bytes'], $pending['filename']);
        $allowDuplicate = $request->post('allow_duplicate') === '1';

        try {
            $outcome = $service->import($analysis, $pending['bytes'], $allowDuplicate);
        } catch (DuplicateImportException) {
            SessionManager::flash('warning', 'Diese Datei wurde bereits importiert. Bitte bestätigen Sie den erneuten Import ausdrücklich.');
            return Response::redirect('/import/' . $token);
        } catch (ImportFailedException $e) {
            $this->discard($token);
            SessionManager::flash('error', $e->getMessage());
            return Response::redirect($e->failedImportId !== null ? '/imports/' . $e->failedImportId : '/import');
        }

        $this->discard($token);
        SessionManager::flash('success', sprintf(
            'Import erfolgreich: Bericht Nr. %d mit %d Parametern gespeichert.',
            $outcome->reportId,
            $outcome->parameterCount,
        ));
        return Response::redirect('/reports/' . $outcome->reportId);
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): Response
    {
        // Security fix: nur eigene Uploads verwerfen (Token muss zur Sitzung gehoeren).
        if (isset($_SESSION['pending_uploads'][$params['token']])) {
            $this->discard($params['token']);
        }
        SessionManager::flash('info', 'Der Import wurde verworfen. Es wurden keine Daten gespeichert.');
        return Response::redirect('/import');
    }

    /**
     * @return array{bytes: string, filename: string, hash: string, size: int, created: int}
     */
    private function loadPending(string $token): array
    {
        // Token muss zur eigenen Session gehoeren
        if (!isset($_SESSION['pending_uploads'][$token])) {
            throw HttpException::notFound('Der Upload wurde nicht gefunden oder ist abgelaufen. Bitte die Datei erneut hochladen.');
        }
        $pending = $this->app->pendingUploads()->load($token);
        if ($pending === null) {
            unset($_SESSION['pending_uploads'][$token]);
            throw HttpException::notFound('Der Upload wurde nicht gefunden oder ist abgelaufen. Bitte die Datei erneut hochladen.');
        }
        return $pending;
    }

    private function discard(string $token): void
    {
        $this->app->pendingUploads()->delete($token);
        unset($_SESSION['pending_uploads'][$token]);
    }
}
