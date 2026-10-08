<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Patient\DeviceCheckInput;
use App\Patient\PatientException;
use App\Patient\PatientInput;
use App\Patient\PatientRecordInput;
use App\Patient\PatientRecordType;
use App\Security\SessionManager;
use App\Support\DateInput;

/**
 * Patientenakte: Patienten vor dem Import anlegen und versionierte Bausteine pflegen.
 *
 * Ein Patient kann ohne Bericht und ohne Import angelegt werden. Anamnese, Vormedikation,
 * Epikrise und Notiz werden als eigene Bausteine am Patienten gefuehrt. Jede Speicherung
 * erzeugt eine neue, unveraenderliche Fassung; fruehere Fassungen bleiben einsehbar.
 */
final class PatientController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['q', 'identifier'] as $key) {
            $filters[$key] = mb_substr(trim($request->query($key)), 0, 200);
        }
        $filters['dob'] = '';
        $birth = mb_substr(trim($request->query('birth')), 0, 32);
        $filterError = null;
        if ($birth !== '') {
            $iso = DateInput::parse($birth);
            if ($iso === null) {
                $filterError = 'Das Geburtsdatum im Filter ist ungültig (erwartet TT.MM.JJJJ).';
            } else {
                $filters['dob'] = $iso;
            }
        }

        $page = self::page($request->query('page', '1'));
        $result = $this->app->patientService()->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('patients/index', [
            'title' => 'Patienten',
            'filters' => $filters,
            'birth' => $birth,
            'filterError' => $filterError,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'query' => static fn (array $extra): string => http_build_query(array_filter(
                $extra + ['q' => $filters['q'], 'identifier' => $filters['identifier'], 'birth' => $birth],
                static fn ($v): bool => $v !== '' && $v !== null,
            )),
        ], 'patients'));
    }

    public function newForm(Request $request): Response
    {
        return $this->renderForm(null, [], [], []);
    }

    public function create(Request $request): Response
    {
        try {
            $input = PatientInput::fromPost($request->post);
        } catch (PatientException $e) {
            return $this->renderForm(null, self::formValues($request->post), $e->fieldErrors(), [], $e->getMessage());
        }

        $service = $this->app->patientService();
        $duplicates = $service->duplicates($input);
        try {
            $result = $service->create($input);
        } catch (PatientException $e) {
            return $this->renderForm(null, $input->allValues(), $e->fieldErrors(), $duplicates, $e->getMessage());
        }

        SessionManager::flash('success', sprintf(
            'Patient Nr. %d wurde angelegt. Anamnese, Vormedikation und Epikrise können jetzt vor dem Import erfasst werden.',
            (int) $result['patient_id'],
        ));

        return Response::redirect('/patients/' . $result['patient_id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $patient = $this->loadPatient($patientId);

        return Response::html($this->view->render('patients/show', [
            'title' => 'Patient ' . $patient['patient_name'],
            'patient' => $patient,
            'master' => $this->app->patientService()->masterData($patientId),
            'records' => $this->app->patientRecordService()->overview($patientId),
            'types' => PatientRecordType::all(),
            'reports' => $this->app->patientService()->reports($patientId),
        ], 'patients'));
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $patient = $this->loadPatient($patientId);

        return $this->renderForm($patientId, $this->editValues($patientId, $patient), [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $this->loadPatient($patientId);

        try {
            $input = PatientInput::fromPost($request->post);
        } catch (PatientException $e) {
            return $this->renderForm($patientId, self::formValues($request->post), $e->fieldErrors(), [], $e->getMessage());
        }

        $service = $this->app->patientService();
        $duplicates = $service->duplicates($input, $patientId);
        try {
            $service->update($patientId, $input);
        } catch (PatientException $e) {
            return $this->renderForm($patientId, $input->allValues(), $e->fieldErrors(), $duplicates, $e->getMessage());
        }

        SessionManager::flash('success', 'Die Stammdaten wurden aktualisiert.');
        return Response::redirect('/patients/' . $patientId);
    }

    /**
     * Formular und Fassungshistorie eines Bausteins.
     *
     * @param array<string, string> $params
     */
    public function recordForm(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $patient = $this->loadPatient($patientId);
        $type = self::type($params);

        return $this->renderRecord($patientId, $type, $patient, $this->recordValues($patientId, $type), [], null);
    }

    /**
     * Uebernimmt die aus dem letzten Bericht ableitbaren Werte in das Formular der Abfrage.
     *
     * Vorhandene Eingaben werden nicht ueberschrieben; nur leere Felder werden ergaenzt. Die
     * Angaben zur MRT-Tauglichkeit stammen aus dem neuesten Ausweis.
     *
     * @param array<string, string> $params
     */
    public function prefillRecord(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $patient = $this->loadPatient($patientId);
        $type = self::type($params);
        if (!$type->isDeviceCheck()) {
            throw HttpException::notFound('Unbekannter Baustein der Patientenakte.');
        }

        $template = $this->app->patientRecordService()->deviceCheckTemplate();
        $values = self::recordFormValues($request->post);
        $deviceType = is_string($values['device_type'] ?? null) ? $values['device_type'] : '';
        if (!$template->hasDeviceType($deviceType)) {
            $deviceType = (string) ($this->recordValues($patientId, $type)['device_type'] ?? '');
        }
        if (!$template->hasDeviceType($deviceType)) {
            $deviceType = array_key_first($template->deviceTypes());
        }

        $prefill = $this->app->deviceCheckPrefill()->fromLastReport($patientId, (string) $deviceType, $template);
        $values['device_type'] = $deviceType;
        [$values, $filled] = self::mergeRecordValues($values, $prefill['values'], $prefill['leads']);
        $values = $this->withCardMrt($patientId, $values);

        $message = $prefill['report_id'] === null
            ? 'Es liegt kein Bericht vor – es konnten keine Werte übernommen werden. Bitte die Angaben von Hand erfassen.'
            : sprintf(
                'Werte aus %s übernommen: %d leere Felder ergänzt. Vorhandene Eingaben wurden nicht überschrieben.',
                $prefill['report_label'],
                $filled,
            );

        return $this->renderRecord($patientId, $type, $patient, $values, [], $message);
    }

    /**
     * Speichert eine neue Fassung des Bausteins.
     *
     * @param array<string, string> $params
     */
    public function saveRecord(Request $request, array $params): Response
    {
        $patientId = self::id($params);
        $patient = $this->loadPatient($patientId);
        $type = self::type($params);

        try {
            $post = $request->post;
            if ($type->isDeviceCheck()) {
                // Der Patientenausweis ist die fuehrende Quelle: eine abweichende Angabe zur
                // MRT-Tauglichkeit wird nicht gespeichert.
                $post['values'] = $this->withCardMrt($patientId, self::recordFormValues($post))['values'];
            }
            $result = $this->app->patientRecordService()->save($patientId, $type, $post);
        } catch (PatientException $e) {
            return $this->renderRecord(
                $patientId,
                $type,
                $patient,
                self::recordFormValues($request->post),
                $e->fieldErrors(),
                $e->getMessage(),
            );
        }

        SessionManager::flash(
            'success',
            $result['unchanged']
                ? sprintf('%s ist unverändert – es wurde keine neue Fassung angelegt.', $type->label())
                : sprintf('%s wurde als Fassung %d gespeichert.', $type->label(), $result['version']),
        );

        return Response::redirect('/patients/' . $patientId . '/records/' . $type->value);
    }

    // -------------------------------------------------------------------- Helfer

    /**
     * @return array<string, mixed>
     */
    private function loadPatient(int $patientId): array
    {
        return $this->app->patientService()->patient($patientId)
            ?? throw HttpException::notFound('Der Patient wurde nicht gefunden.');
    }

    /**
     * @param array<string, string> $params
     */
    private static function type(array $params): PatientRecordType
    {
        return PatientRecordType::fromValue($params['slug'] ?? null)
            ?? throw HttpException::notFound('Unbekannter Baustein der Patientenakte.');
    }

    /**
     * Formularseite der Stammdaten (Neuanlage und Bearbeitung).
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param list<array<string, mixed>> $duplicates
     */
    private function renderForm(
        ?int $patientId,
        array $values,
        array $errors,
        array $duplicates,
        ?string $message = null,
    ): Response {
        return Response::html($this->view->render('patients/form', [
            'title' => $patientId === null ? 'Patient anlegen' : 'Patient bearbeiten',
            'patientId' => $patientId,
            'values' => $values + self::emptyValues(),
            'errors' => $errors,
            'duplicates' => $duplicates,
            'message' => $message,
        ], 'patients'), $errors === [] ? 200 : 422);
    }

    /**
     * @param array<string, mixed> $patient
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function renderRecord(
        int $patientId,
        PatientRecordType $type,
        array $patient,
        array $values,
        array $errors,
        ?string $message,
    ): Response {
        if ($type->isDeviceCheck() && !array_key_exists('mrt_locked', $values)) {
            $values = $this->withCardMrt($patientId, $values);
        }
        return Response::html($this->view->render('patients/record', [
            'title' => $type->label() . ' – ' . $patient['patient_name'],
            'patient' => $patient,
            'type' => $type,
            'values' => $values + self::emptyRecordValues(),
            'errors' => $errors,
            'message' => $message,
            'current' => $this->app->patientRecordService()->current($patientId, $type),
            'history' => $this->app->patientRecordService()->history($patientId, $type),
            'types' => PatientRecordType::all(),
            'maxEntries' => PatientRecordInput::MAX_ENTRIES,
            'deviceCheck' => $type->isDeviceCheck() ? self::deviceCheckForm($this->app) : null,
        ], 'patients'), $errors === [] ? 200 : 422);
    }

    /**
     * Aufbau des Formulars der Schrittmacher-/ICD-Abfrage (Vorlage, Abschnitte, Grenzen).
     *
     * @return array<string, mixed>
     */
    private static function deviceCheckForm(Application $app): array
    {
        $template = $app->patientRecordService()->deviceCheckTemplate();
        return [
            'version' => $template->version(),
            'deviceTypes' => $template->deviceTypes(),
            'sections' => $template->formSections(),
            'leadFields' => $template->allLeadFields(),
            'maxLeads' => $template->maxLeads(),
            'maxNotes' => $template->maxNotes(),
        ];
    }

    /**
     * Werte fuer die Bearbeitung der Stammdaten.
     *
     * @param array<string, mixed> $patient
     * @return array<string, string>
     */
    private function editValues(int $patientId, array $patient): array
    {
        $master = $this->app->patientService()->masterData($patientId) ?? [];
        $dob = (string) ($patient['date_of_birth'] ?? '');
        $values = [
            'last_name' => (string) ($patient['last_name'] ?? ''),
            'first_name' => (string) ($patient['first_name'] ?? ''),
            'patient_identifier' => (string) ($patient['patient_identifier'] ?? ''),
            'date_of_birth' => $dob === '' ? '' : DateInput::format($dob),
            'confirm_duplicate' => '',
        ];
        foreach (PatientInput::TEXT_FIELDS as $field => $_) {
            $values[$field] = (string) ($master[$field] ?? '');
        }
        return $values;
    }

    /**
     * Werte eines Bausteins aus der aktuellen Fassung (Formular vorbelegen).
     *
     * @return array<string, mixed>
     */
    private function recordValues(int $patientId, PatientRecordType $type): array
    {
        $current = $this->app->patientRecordService()->current($patientId, $type);
        if ($current === null) {
            $empty = self::emptyRecordValues();
            // Auch ohne Fassung fuehrt der Ausweis die MRT-Tauglichkeit.
            return $type->isDeviceCheck() ? $this->withCardMrt($patientId, $empty) : $empty;
        }
        if ($type->isDeviceCheck()) {
            $check = is_array($current['device_check'] ?? null) ? $current['device_check'] : [];
            $described = DeviceCheckInput::describe($check, $this->app->patientRecordService()->deviceCheckTemplate());
            return $this->withCardMrt($patientId, [
                'device_type' => $described['device_type'],
                'values' => $described['values'],
                'leads' => $described['leads'],
                'notes' => $described['notes'],
                'author_name' => (string) ($current['author_name'] ?? ''),
            ]);
        }
        $entries = [];
        foreach ($current['entries'] as $entry) {
            foreach (['from', 'to'] as $field) {
                if (($entry[$field] ?? '') !== '') {
                    $entry[$field] = DateInput::format($entry[$field]);
                }
            }
            $entries[] = $entry;
        }
        return [
            'text' => (string) $current['text'],
            'author_name' => (string) ($current['author_name'] ?? ''),
            'medication' => $entries,
        ];
    }

    /**
     * Uebernimmt die Angaben zur MRT-Tauglichkeit aus dem neuesten Ausweis.
     *
     * Der Ausweis ist die fuehrende Quelle: liegt eine Angabe vor, wird sie im Formular nur
     * lesend angezeigt. Gibt es noch keinen Ausweis, bleibt das Feld erfassbar.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withCardMrt(int $patientId, array $values): array
    {
        $template = $this->app->patientRecordService()->deviceCheckTemplate();
        $card = $this->app->deviceCheckPrefill()->cardMrt($patientId, $template);
        $values['mrt_locked'] = $card['locked'];
        if (!$card['locked']) {
            return $values;
        }
        $inner = is_array($values['values'] ?? null) ? $values['values'] : [];
        foreach ($card['values'] as $key => $value) {
            $inner[$key] = $value;
        }
        $values['values'] = $inner;
        return $values;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function recordFormValues(array $post): array
    {
        $text = $post['text'] ?? '';
        $author = $post['author_name'] ?? '';
        $rows = $post['medication'] ?? null;
        $entries = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entry = [];
            foreach (array_keys(PatientRecordInput::ENTRY_FIELDS) as $field) {
                $value = $row[$field] ?? '';
                $entry[$field] = is_string($value) ? $value : '';
            }
            $entries[] = $entry;
        }

        $deviceType = $post['device_type'] ?? '';
        $fields = [];
        foreach (is_array($post['values'] ?? null) ? $post['values'] : [] as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $fields[$key] = $value;
            }
        }
        $leads = [];
        foreach (is_array($post['leads'] ?? null) ? $post['leads'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lead = [];
            foreach ($row as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $lead[$name] = $value;
                }
            }
            $leads[] = $lead;
        }
        $notes = $post['notes'] ?? '';

        return [
            'text' => is_string($text) ? $text : '',
            'author_name' => is_string($author) ? $author : '',
            'medication' => $entries,
            'device_type' => is_string($deviceType) ? $deviceType : '',
            'values' => $fields,
            'leads' => $leads,
            'notes' => is_string($notes) ? $notes : '',
        ];
    }

    /**
     * Ergaenzt leere Formularfelder um die vorbelegten Werte; vorhandene Eingaben bleiben.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $prefill
     * @param list<array<string, string>> $leads
     * @return array{0: array<string, mixed>, 1: int} Werte und Anzahl tatsaechlich ergaenzter Felder
     */
    private static function mergeRecordValues(array $values, array $prefill, array $leads): array
    {
        $filled = 0;
        $fields = is_array($values['values'] ?? null) ? $values['values'] : [];
        foreach ($prefill as $key => $value) {
            if (($fields[$key] ?? '') === '' && $value !== '') {
                $fields[$key] = $value;
                $filled++;
            }
        }
        $values['values'] = $fields;

        $rows = is_array($values['leads'] ?? null) ? array_values($values['leads']) : [];
        foreach ($leads as $index => $lead) {
            $row = isset($rows[$index]) && is_array($rows[$index]) ? $rows[$index] : [];
            foreach ($lead as $name => $value) {
                if (($row[$name] ?? '') === '' && $value !== '') {
                    $row[$name] = $value;
                    $filled++;
                }
            }
            $rows[$index] = $row;
        }
        $values['leads'] = $rows;

        return [$values, $filled];
    }

    /**
     * Eingaben eines abgelehnten Formulars unveraendert zurueckgeben.
     *
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private static function formValues(array $post): array
    {
        $values = [];
        foreach (array_keys(self::emptyValues()) as $field) {
            $value = $post[$field] ?? '';
            $values[$field] = is_string($value) ? $value : '';
        }
        $values['confirm_duplicate'] = ($post['confirm_duplicate'] ?? '') === '1' ? '1' : '';
        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function emptyValues(): array
    {
        $values = [
            'last_name' => '',
            'first_name' => '',
            'date_of_birth' => '',
            'patient_identifier' => '',
            'confirm_duplicate' => '',
        ];
        foreach (PatientInput::TEXT_FIELDS as $field => $_) {
            $values[$field] = '';
        }
        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyRecordValues(): array
    {
        return [
            'text' => '',
            'author_name' => '',
            'medication' => [],
            'device_type' => '',
            'values' => [],
            'leads' => [],
            'notes' => '',
            'mrt_locked' => false,
        ];
    }
}
