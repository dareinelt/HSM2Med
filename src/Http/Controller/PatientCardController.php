<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardService;
use App\Report\ReportData;
use App\Security\SessionManager;
use RuntimeException;

/**
 * Patientenausweis: Bericht auswaehlen, Assistent ausfuellen, Ausweis erzeugen und anzeigen.
 *
 * Der Assistent ist eine Formularseite mit sechs Schritten (clientseitig umschaltbar, CSP-konform).
 * Konflikte zwischen bereits bestaetigten Angaben und den Eingaben werden serverseitig erkannt und
 * muessen je Feld entschieden werden, bevor ein Ausweis erzeugt wird.
 */
final class PatientCardController extends Controller
{
    private const int PER_PAGE = 25;

    /** Kennung des letzten Assistentenschritts (Zusammenfassung mit Bestaetigungen). */
    private const int LAST_STEP = 6;

    private const array WIZARD_FILTERS = ['q', 'patient', 'patient_id', 'serial', 'model', 'filename'];

    /** Schritte des Assistenten (Nummer => Beschriftung). */
    public const array WIZARD_STEPS = [
        1 => 'Patient identifizieren',
        2 => 'Patientendaten ergänzen',
        3 => 'Notfallkontakt',
        4 => 'Hausarzt',
        5 => 'Nachsorge und Kontrolle',
        6 => 'Zusammenfassung',
    ];

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['q', 'patient', 'serial'] as $key) {
            $filters[$key] = mb_substr(trim($request->query($key)), 0, 200);
        }
        $page = self::page($request->query('page', '1'));
        $result = $this->app->patientCardService()->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('patient_cards/index', [
            'title' => 'Patientenausweise',
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ], 'patient_cards'));
    }

    /**
     * Auswahl des Quellberichts. Ohne Bericht kann kein Ausweis entstehen.
     */
    public function selectReport(Request $request): Response
    {
        $filters = [];
        foreach (self::WIZARD_FILTERS as $key) {
            $filters[$key] = mb_substr(trim($request->query($key)), 0, 200);
        }
        $page = self::page($request->query('page', '1'));
        $result = $this->app->reportRepository()->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $cards = $this->app->patientCardRepository()->latestCardsForReports(
            array_map(static fn (array $row): int => (int) $row['id'], $result['rows']),
        );

        return Response::html($this->view->render('patient_cards/select_report', [
            'title' => 'Patientenausweis erstellen',
            'filters' => $filters,
            'rows' => $result['rows'],
            'cards' => $cards,
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ], 'patient_cards'));
    }

    /**
     * @param array<string, string> $params
     */
    public function wizard(Request $request, array $params): Response
    {
        $report = $this->loadReport(self::id($params));
        $step = min(self::LAST_STEP, max(1, (int) $request->query('step', '1')));
        $values = $this->app->patientCardService()->prefill($report, $this->masterData($report->id()));

        return $this->renderWizard($request, $report, $values, [], [], [], $step, null);
    }

    /**
     * Prueft die Assistenteneingaben, klaert Konflikte und erzeugt den Ausweis.
     *
     * @param array<string, string> $params
     */
    public function generate(Request $request, array $params): Response
    {
        $report = $this->loadReport(self::id($params));
        $service = $this->app->patientCardService();
        $values = $this->postedValues($request);

        try {
            $input = PatientCardInput::fromPost($request->post);
        } catch (PatientCardException $e) {
            return $this->renderWizard(
                $request,
                $report,
                $values,
                $e->fieldErrors(),
                [],
                [],
                $this->stepForErrors($e->fieldErrors()),
                $e->getMessage(),
            );
        }

        $masterData = $this->masterData($report->id());
        $candidates = $service->candidates($input);
        $conflicts = $service->conflicts($input, $masterData);
        $unresolved = array_values(array_filter(
            $conflicts,
            static fn (array $conflict): bool => !isset($input->conflictChoices[$conflict['field']]),
        ));

        $errors = [];
        if ($unresolved !== []) {
            $errors['conflicts'] = 'Bitte für jedes Feld entscheiden, welcher Wert übernommen wird.';
        }
        if (count($candidates) > 1 && $input->selectedPatientId === null) {
            $errors['patient_id'] = 'Zu diesem Namen und Geburtsdatum existieren mehrere Patienten. Bitte den richtigen Patienten auswählen.';
        }
        if ($errors !== []) {
            return $this->renderWizard(
                $request,
                $report,
                $values,
                $errors,
                $conflicts,
                $candidates,
                self::LAST_STEP,
                null,
                $input->conflictChoices,
            );
        }

        try {
            $result = $service->create($input, $report, $masterData, $input->selectedPatientId);
        } catch (PatientCardException $e) {
            return $this->renderWizard(
                $request,
                $report,
                $values,
                $e->fieldErrors(),
                $conflicts,
                $candidates,
                $this->stepForErrors($e->fieldErrors()),
                $e->getMessage(),
                $input->conflictChoices,
            );
        }

        SessionManager::flash('success', sprintf(
            'Patientenausweis Nr. %d wurde erstellt und unveränderlich gespeichert.',
            $result['card_id'],
        ));

        return Response::redirect('/patient-cards/' . $result['card_id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $card = $this->loadCard(self::id($params));
        $patientId = (int) $card['patient_id'];

        return Response::html($this->view->render('patient_cards/show', [
            'title' => 'Patientenausweis Nr. ' . $card['id'],
            'card' => $card,
            'snapshot' => $this->snapshot($card),
            'patient' => $this->app->patientCardRepository()->patient($patientId),
            'previous' => $this->app->patientCardRepository()->previousCards($patientId, (int) $card['id']),
        ], 'patient_cards'));
    }

    /**
     * Unveraenderliches PDF des Ausweises, ausschliesslich aus dem gespeicherten Snapshot erzeugt.
     *
     * @param array<string, string> $params
     */
    public function pdf(Request $request, array $params): Response
    {
        $card = $this->loadCard(self::id($params), true);
        $content = $card['pdf_content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw HttpException::notFound('Zu diesem Ausweis ist kein PDF gespeichert.');
        }

        return Response::pdf($content, (string) $card['pdf_filename'], $request->query('download') === '1');
    }

    /**
     * Alle Ausweise und Nachsorgeuntersuchungen eines Patienten.
     *
     * @param array<string, string> $params
     */
    public function patient(Request $request, array $params): Response
    {
        $patientId = self::id($params, 'patient');
        $repository = $this->app->patientCardRepository();
        $patient = $repository->patient($patientId);
        if ($patient === null) {
            throw HttpException::notFound('Der Patient wurde nicht gefunden.');
        }
        $cards = $this->app->patientCardService()->cardsForPatient($patientId);
        $past = $repository->pastReports($patientId, 0, PatientCardService::HISTORY_LIMIT);

        return Response::html($this->view->render('patient_cards/patient', [
            'title' => 'Patient ' . $patient['patient_name'],
            'patient' => $patient,
            'cards' => $cards,
            'past' => $past,
            'physicians' => $repository->followUpPhysicians(
                array_map(static fn (array $row): int => (int) $row['id'], $past),
            ),
        ], 'patient_cards'));
    }

    // -------------------------------------------------------------------- Helfer

    private function loadReport(int $reportId): ReportData
    {
        try {
            return $this->app->patientCardService()->loadReport($reportId);
        } catch (RuntimeException) {
            throw HttpException::notFound('Der Bericht wurde nicht gefunden.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCard(int $cardId, bool $withContent = false): array
    {
        return $this->app->patientCardService()->card($cardId, $withContent)
            ?? throw HttpException::notFound('Der Patientenausweis wurde nicht gefunden.');
    }

    /**
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function snapshot(array $card): array
    {
        $snapshot = json_decode((string) $card['snapshot'], true);
        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function masterData(int $reportId): ?array
    {
        $patientId = $this->app->patientCardRepository()->reportPatientId($reportId);
        return $patientId === null ? null : $this->app->patientCardRepository()->masterData($patientId);
    }

    /**
     * Rohwerte aus dem Formular (fuer die erneute Anzeige nach einem Fehler).
     *
     * @return array<string, string>
     */
    private function postedValues(Request $request): array
    {
        $fields = array_merge(
            ['last_name', 'first_name', 'date_of_birth', 'next_control_date'],
            array_keys(PatientCardInput::TEXT_FIELDS),
        );
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = mb_substr($request->post($field), 0, 2100);
        }
        return $values;
    }

    /**
     * Schritt, in dem der erste Feldfehler angezeigt wird.
     *
     * @param array<string, string> $errors
     */
    private function stepForErrors(array $errors): int
    {
        foreach (array_keys($errors) as $field) {
            return match ($field) {
                'last_name', 'first_name', 'date_of_birth', 'patient_id' => 1,
                'street', 'postal_code', 'city', 'phone', 'indication', 'device_implant_location' => 2,
                'emergency_contact_name', 'emergency_contact_phone' => 3,
                'physician_name', 'physician_practice', 'physician_postal_code', 'physician_city', 'physician_phone' => 4,
                'control_physician', 'next_control_date' => 5,
                default => self::LAST_STEP,
            };
        }
        return self::LAST_STEP;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param list<array{field: string, label: string, stored: string, new: string}> $conflicts
     * @param list<array<string, mixed>> $candidates
     */
    private function renderWizard(
        Request $request,
        ReportData $report,
        array $values,
        array $errors,
        array $conflicts,
        array $candidates,
        int $step,
        ?string $message,
        array $choices = [],
    ): Response {
        return Response::html($this->view->render('patient_cards/wizard', [
            'title' => 'Patientenausweis erstellen',
            'report' => $report,
            'values' => $values,
            'errors' => $errors,
            'conflicts' => $conflicts,
            'candidates' => $candidates,
            'choices' => $choices,
            'selection' => $this->selection($request),
            'masterData' => $this->masterData($report->id()),
            'existingCard' => $this->app->patientCardService()->latestCardForReport($report->id()),
            'labels' => PatientCardService::fieldLabels(),
            'step' => $step,
            'message' => $message,
            'settings' => $this->app->patientCardSettingsService()->load()['settings'],
            'steps' => self::WIZARD_STEPS,
        ], 'patient_cards'), $errors === [] && $message === null ? 200 : 422);
    }

    /**
     * Auswahlzustand des Formulars (Patientenauswahl, Bestaetigungen) fuer die erneute Anzeige.
     *
     * @return array{patient_id: string, confirm_patient: bool, confirm_merge: bool}
     */
    private function selection(Request $request): array
    {
        return [
            'patient_id' => $request->post('patient_id'),
            'confirm_patient' => $request->post('confirm_patient') === '1',
            'confirm_merge' => $request->post('confirm_merge') === '1',
        ];
    }
}
