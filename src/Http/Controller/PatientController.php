<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
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
            $result = $this->app->patientRecordService()->save($patientId, $type, $request->post);
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
        ], 'patients'), $errors === [] ? 200 : 422);
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
            return self::emptyRecordValues();
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
        return [
            'text' => is_string($text) ? $text : '',
            'author_name' => is_string($author) ? $author : '',
            'medication' => $entries,
        ];
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
        return ['text' => '', 'author_name' => '', 'medication' => []];
    }
}
