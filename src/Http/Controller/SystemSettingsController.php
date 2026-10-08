<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardSettingsService;
use App\Security\SessionManager;

/**
 * Praxis-Informationen und Ruecksendeangaben im Bereich "System".
 *
 * Die Angaben sind eine gemeinsame Datenquelle: Praxisname, Anschrift und Logo erscheinen im
 * Briefkopf der Briefe und auf den Patientenausweisen, die Kontaktangaben und die
 * Ruecksendeangabe werden in die Briefe uebernommen. Jede Aenderung erzeugt eine neue,
 * unveraenderliche Fassung; bereits erzeugte Briefe und Ausweise bleiben unveraendert.
 */
final class SystemSettingsController extends Controller
{
    /**
     * Eingabefelder: Name => [Beschriftung, Maximallaenge, mehrzeilig].
     */
    public const array TEXT_INPUTS = [
        'center_name' => ['Name der Praxis', PatientCardSettingsService::MAX_CENTER_NAME, false],
        'center_address' => ['Anschrift der Praxis', PatientCardSettingsService::MAX_CENTER_ADDRESS, true],
        'practice_phone' => ['Telefon', PatientCardSettingsService::MAX_PHONE, false],
        'practice_fax' => ['Fax', PatientCardSettingsService::MAX_PHONE, false],
        'practice_email' => ['E-Mail', PatientCardSettingsService::MAX_EMAIL, false],
        'practice_website' => ['Internetseite', PatientCardSettingsService::MAX_WEBSITE, false],
        'return_name' => ['Name', PatientCardSettingsService::MAX_RETURN_NAME, false],
        'return_street' => ['Straße', PatientCardSettingsService::MAX_RETURN_STREET, false],
        'return_postal_code' => ['Postleitzahl', PatientCardSettingsService::MAX_RETURN_POSTAL_CODE, false],
        'return_city' => ['Ort', PatientCardSettingsService::MAX_RETURN_CITY, false],
    ];

    /** Abschnitte des Formulars: Ueberschrift => Felder. */
    private const array SECTIONS = [
        'Praxis-Informationen' => ['center_name', 'center_address', 'practice_phone', 'practice_fax', 'practice_email', 'practice_website'],
        'Rücksendeangaben' => ['return_name', 'return_street', 'return_postal_code', 'return_city'],
    ];

    public function index(Request $request): Response
    {
        $data = $this->app->patientCardSettingsService()->load();

        return Response::html($this->view->render('system_settings/index', [
            'title' => 'Praxis-Informationen',
            'logo' => $data['logo'],
            'versions' => $data['versions'],
            'limits' => $data['limits'],
            'values' => $this->values($data['settings']),
            'fields' => self::TEXT_INPUTS,
            'sections' => self::SECTIONS,
            'errors' => [],
            'message' => null,
        ], 'system_settings'));
    }

    public function save(Request $request): Response
    {
        $service = $this->app->patientCardSettingsService();
        $data = $service->load();

        try {
            $result = $service->save($this->withNotices($request->post, $data['settings']), $request->file('logo'), $request->post('remove_logo') === '1');
        } catch (PatientCardException $e) {
            return Response::html($this->view->render('system_settings/index', [
                'title' => 'Praxis-Informationen',
                'logo' => $data['logo'],
                'versions' => $data['versions'],
                'limits' => $data['limits'],
                'values' => $this->values($request->post),
                'fields' => self::TEXT_INPUTS,
                'sections' => self::SECTIONS,
                'errors' => $e->fieldErrors(),
                'message' => $e->getMessage(),
            ], 'system_settings'), 422);
        }

        SessionManager::flash('success', sprintf(
            'Die Praxis-Informationen wurden als neue Fassung Nr. %d gespeichert. Bereits erstellte Briefe und Ausweise bleiben unverändert.',
            $result['version_id'],
        ));

        return Response::redirect('/system/settings');
    }

    /**
     * Aktuell hinterlegtes Logo (fuer die Vorschau im Formular).
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
     * Uebernimmt die Hinweistexte des Patientenausweises aus den gespeicherten Stammdaten, da sie
     * nur auf der Ausweisseite gepflegt werden und beim Speichern hier erhalten bleiben muessen.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function withNotices(array $post, array $settings): array
    {
        foreach (PatientCardSettingsService::CARD_NOTICE_FIELDS as $field) {
            if (!array_key_exists($field, $post)) {
                $post[$field] = $settings[$field] ?? '';
            }
        }
        return $post;
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
