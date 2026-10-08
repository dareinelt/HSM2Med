<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Letter\LetterException;
use App\Letter\LetterInput;
use App\Security\SessionManager;
use RuntimeException;

/**
 * Brief zur Schrittmacher-/ICD-Abfrage: Patienten waehlen, Bericht optional zuordnen,
 * Bausteine pruefen, bestaetigen, erzeugen und anzeigen.
 *
 * Der Assistent ist bewusst serverseitig und ohne JavaScript nutzbar (CSP-konform):
 * Schritt 1 waehlt den Patienten, Schritt 2 optional den Bericht als Befundteil. Beide
 * Auswahlen sind Links; der letzte Schritt ist das Formular mit den Bestaetigungen.
 */
final class LetterController extends Controller
{
    private const int PER_PAGE = 25;

    /** Schritte des Assistenten (Nummer => Beschriftung). */
    public const array WIZARD_STEPS = [
        1 => 'Patient wählen',
        2 => 'Bericht zuordnen',
        3 => 'Bausteine prüfen',
        4 => 'Zusammenfassung',
        5 => 'Bestätigen und erzeugen',
    ];

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['q', 'patient', 'report'] as $key) {
            $filters[$key] = mb_substr(trim($request->query($key)), 0, 200);
        }
        $page = self::page($request->query('page', '1'));
        $result = $this->app->letterService()->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('letters/index', [
            'title' => 'Briefe',
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ], 'letters'));
    }

    /**
     * Schritt 1 (Patientenauswahl) und, sobald ein Patient gewaehlt ist, die Schritte 2 bis 5.
     */
    public function newLetter(Request $request): Response
    {
        $query = mb_substr(trim($request->query('q')), 0, 200);
        $patientId = ctype_digit($request->query('patient')) ? (int) $request->query('patient') : 0;

        if ($patientId <= 0) {
            return Response::html($this->view->render('letters/new', [
                'title' => 'Brief erstellen',
                'query' => $query,
                'rows' => $this->app->letterService()->patientChoices($query),
                'steps' => self::WIZARD_STEPS,
            ], 'letters'));
        }

        $reportId = ctype_digit($request->query('report')) && (int) $request->query('report') > 0
            ? (int) $request->query('report')
            : null;

        return $this->renderWizard($patientId, $reportId, [], null);
    }

    /**
     * Erzeugt den Brief aus den bestaetigten Angaben.
     */
    public function create(Request $request): Response
    {
        try {
            $input = LetterInput::fromPost($request->post);
        } catch (LetterException $e) {
            return Response::html($this->view->render('letters/new', [
                'title' => 'Brief erstellen',
                'query' => '',
                'rows' => $this->app->letterService()->patientChoices(''),
                'steps' => self::WIZARD_STEPS,
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
            ], 'letters'), 422);
        }

        try {
            $result = $this->app->letterService()->create($input);
        } catch (LetterException $e) {
            return $this->renderWizard(
                $input->patientId,
                $input->reportId,
                $e->fieldErrors(),
                $e->getMessage(),
                $input->selection(),
            );
        }

        SessionManager::flash('success', sprintf(
            'Brief Nr. %d wurde erstellt und unveränderlich gespeichert (Anhang mit der vollständigen Abfrage).',
            $result['letter_id'],
        ));

        return Response::redirect('/letters/' . $result['letter_id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $letter = $this->loadLetter(self::id($params));
        $snapshot = (array) $letter['snapshot'];

        return Response::html($this->view->render('letters/show', [
            'title' => 'Brief Nr. ' . $letter['id'],
            'letter' => $letter,
            'snapshot' => $snapshot,
            'previous' => $this->app->letterService()->lettersForPatient((int) $letter['patient_id']),
        ], 'letters'));
    }

    /**
     * Unveraenderliches PDF des Briefes, ausschliesslich aus dem gespeicherten Inhalt.
     *
     * @param array<string, string> $params
     */
    public function pdf(Request $request, array $params): Response
    {
        $letter = $this->loadLetter(self::id($params), true);
        $content = $letter['pdf_content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw HttpException::notFound('Zu diesem Brief ist kein PDF gespeichert.');
        }

        return Response::pdf($content, (string) $letter['pdf_filename'], $request->query('download') === '1');
    }

    /**
     * Alle Briefe eines Patienten (Einstieg aus der Akte).
     *
     * @param array<string, string> $params
     */
    public function patient(Request $request, array $params): Response
    {
        $patientId = self::id($params, 'patient');
        $patient = $this->app->letterRepository()->patient($patientId);
        if ($patient === null) {
            throw HttpException::notFound('Der Patient wurde nicht gefunden.');
        }

        return Response::html($this->view->render('letters/patient', [
            'title' => 'Briefe – ' . $patient['patient_name'],
            'patient' => $patient,
            'rows' => $this->app->letterService()->lettersForPatient($patientId),
        ], 'letters'));
    }

    // -------------------------------------------------------------------- Helfer

    /**
     * @return array<string, mixed>
     */
    private function loadLetter(int $letterId, bool $withContent = false): array
    {
        return $this->app->letterService()->letter($letterId, $withContent)
            ?? throw HttpException::notFound('Der Brief wurde nicht gefunden.');
    }

    /**
     * Schritte 2 bis 5: Berichtauswahl, Pruefung der Bausteine, Zusammenfassung, Bestaetigung.
     *
     * @param array<string, string> $errors
     * @param array{patient_id: int, report_id: ?int, confirm_data: bool, confirm_letter: bool}|null $selection
     */
    private function renderWizard(
        int $patientId,
        ?int $reportId,
        array $errors,
        ?string $message,
        ?array $selection = null,
    ): Response {
        try {
            $prepared = $this->app->letterService()->prepare($patientId, $reportId);
        } catch (LetterException $e) {
            // Der gewaehlte Bericht passt nicht zum Patienten: Schritt 2 erneut anzeigen.
            return $this->renderWizard($patientId, null, $e->fieldErrors(), $e->getMessage());
        } catch (RuntimeException) {
            throw HttpException::notFound('Der Patient wurde nicht gefunden.');
        }

        return Response::html($this->view->render('letters/wizard', [
            'title' => 'Brief erstellen',
            'patient' => $prepared['patient'],
            'report' => $prepared['report'],
            'reports' => $this->app->letterService()->reportsFor($patientId),
            'records' => $prepared['records'],
            'mrt' => $prepared['mrt'],
            'appendix' => $prepared['appendix'],
            'warnings' => $prepared['warnings'],
            'nextSequence' => $prepared['next_sequence'],
            'errors' => $errors,
            'message' => $message,
            'selection' => $selection ?? [
                'patient_id' => $patientId,
                'report_id' => $reportId,
                'confirm_data' => false,
                'confirm_letter' => false,
            ],
            'steps' => self::WIZARD_STEPS,
        ], 'letters'), $errors === [] && $message === null ? 200 : 422);
    }
}
