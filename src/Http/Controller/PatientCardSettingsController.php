<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardPdfGenerator;
use App\PatientCard\PatientCardSettingsService;
use App\Security\SessionManager;

/**
 * Globale Stammdaten des Patientenausweises: Logo, Nachsorgezentrum und die drei Hinweistexte.
 *
 * Aenderungen wirken nur auf kuenftig erzeugte Ausweise: jeder Ausweis haelt die beim Erstellen
 * gueltige Stammdaten-Fassung (patient_card_settings_versions) fest.
 */
final class PatientCardSettingsController extends Controller
{
    /**
     * Eingabefelder: Name => [Beschriftung, Maximallaenge, mehrzeilig].
     */
    private const array TEXT_INPUTS = [
        'center_name' => ['Nachsorgezentrum', PatientCardSettingsService::MAX_CENTER_NAME, false],
        'center_address' => ['Anschrift des Nachsorgezentrums', PatientCardSettingsService::MAX_CENTER_ADDRESS, true],
        'notice_text' => ['Hinweise auf dem Ausweis', PatientCardPdfGenerator::MAX_NOTICE_CHARS, true],
        'flight_notice_de' => ['Achtung Flugsicherheit (deutsch)', PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS, true],
        'flight_notice_en' => ['Attention Airline Security (englisch)', PatientCardPdfGenerator::MAX_FLIGHT_NOTICE_CHARS, true],
    ];

    public function index(Request $request): Response
    {
        $service = $this->app->patientCardSettingsService();
        $data = $service->load();

        return Response::html($this->view->render('patient_card_settings/index', [
            'title' => 'Patientenausweis: Stammdaten',
            'settings' => $data['settings'],
            'logo' => $data['logo'],
            'versions' => $data['versions'],
            'limits' => $data['limits'],
            'values' => $this->values($data['settings']),
            'fields' => self::TEXT_INPUTS,
            'errors' => [],
            'message' => null,
        ], 'patient_card_settings'));
    }

    public function save(Request $request): Response
    {
        $service = $this->app->patientCardSettingsService();
        $data = $service->load();

        try {
            $result = $service->save($request->post, $request->file('logo'), $request->post('remove_logo') === '1');
        } catch (PatientCardException $e) {
            return Response::html($this->view->render('patient_card_settings/index', [
                'title' => 'Patientenausweis: Stammdaten',
                'settings' => $data['settings'],
                'logo' => $data['logo'],
                'versions' => $data['versions'],
                'limits' => $data['limits'],
                'values' => $this->values($request->post),
                'fields' => self::TEXT_INPUTS,
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
            ], 'patient_card_settings'), 422);
        }

        SessionManager::flash('success', sprintf(
            'Die Stammdaten wurden als neue Fassung Nr. %d gespeichert. Bereits erstellte Ausweise bleiben unverändert.',
            $result['version_id'],
        ));

        return Response::redirect('/patient-cards/settings');
    }

    /**
     * Aktuell hinterlegtes Logo (fuer die Vorschau in den Stammdaten).
     */
    public function logo(Request $request): Response
    {
        $service = $this->app->patientCardSettingsService();
        $settings = $service->load()['settings'];
        $logoId = $settings['logo_id'] ?? null;
        $logo = $logoId === null ? null : $service->logo((int) $logoId);
        if ($logo === null || !is_string($logo['content'] ?? null)) {
            throw HttpException::notFound('Es ist kein Logo hinterlegt.');
        }
        $mime = (string) $logo['mime_type'];
        if ($mime !== 'image/png' && $mime !== 'image/jpeg') {
            throw HttpException::notFound('Das hinterlegte Logo kann nicht angezeigt werden.');
        }

        return Response::image((string) $logo['content'], $mime);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private function values(array $source): array
    {
        $values = [];
        foreach (array_keys(self::TEXT_INPUTS) as $field) {
            $value = $source[$field] ?? '';
            $values[$field] = is_string($value) ? $value : '';
        }
        return $values;
    }
}
