<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Config\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardSample;
use App\PatientCard\PatientCardTemplate;
use App\PatientCard\PatientCardTemplateService;
use App\Security\Csrf;

/**
 * Vorlageneditor fuer die Patientenausweise (System-Bereich, eigener Browser-Tab).
 *
 * Die Seite ist eigenstaendig (ohne Funktionsband der Hauptanwendung) und wird vollstaendig per
 * JavaScript bedient (public/assets/js/template-editor.js, CSP-konform ohne Inline-Skripte).
 * Die Daten gelangen als JSON-Datenblock in die Seite; gespeichert wird per fetch() mit
 * CSRF-Token. Der Editor ist derselbe wie fuer die Briefvorlagen und unterscheidet die Art
 * der Vorlage ueber "kind" in der Editor-Definition.
 *
 * Jede Speicherung erzeugt eine neue, unveraenderliche Fassung. Ausweise gibt es je
 * Nachsorgezentrum nur einmal; bereits erstellte Ausweise behalten die beim Erstellen
 * gueltige Fassung (Invariante: keine rueckwirkenden Aenderungen).
 */
final class PatientCardTemplateController extends Controller
{
    public function editor(Request $request): Response
    {
        $service = $this->app->patientCardTemplateService();
        $current = $service->current();

        return Response::html($this->view->renderPartial('patient_card_templates/editor', [
            'title' => 'Ausweisvorlage bearbeiten',
            'appName' => Config::APP_NAME,
            'appVersion' => Config::APP_VERSION,
            'current' => $current,
            'definition' => PatientCardTemplate::editorDefinition(),
            'data' => json_encode(
                $this->editorData($service, $current),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
            ),
        ]));
    }

    /**
     * Gespeicherte Fassung als JSON (zum Laden einer aelteren Fassung in den Editor).
     *
     * @param array<string, string> $params
     */
    public function version(Request $request, array $params): Response
    {
        $version = $this->app->patientCardTemplateService()->version(self::id($params));
        if ($version === null) {
            throw HttpException::notFound('Die Fassung der Ausweisvorlage wurde nicht gefunden.');
        }
        return Response::json(['version' => $version]);
    }

    /**
     * Speichert eine neue Fassung (Antwort als JSON, Feldfehler mit Status 422).
     */
    public function save(Request $request): Response
    {
        $content = self::decodeContent($request);
        if ($content === null) {
            return Response::json([
                'ok' => false,
                'message' => 'Die Vorlage konnte nicht gelesen werden.',
                'errors' => ['template' => 'Ungültige Daten.'],
            ], 422);
        }
        $base = $request->post('base_version_id');
        $service = $this->app->patientCardTemplateService();
        try {
            $saved = $service->save($content, $request->post('comment'), ctype_digit($base) ? (int) $base : null);
        } catch (PatientCardException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->fieldErrors()], 422);
        }

        return Response::json([
            'ok' => true,
            'message' => sprintf(
                'Fassung %d der Ausweisvorlage wurde gespeichert (%s). Neu erstellte Ausweise verwenden ab sofort diese Fassung.',
                $saved['version_no'],
                PatientCardTemplate::LABEL,
            ),
            'current' => $saved,
            'versions' => array_map(self::versionSummary(...), $service->versions()),
        ]);
    }

    /**
     * PDF-Vorschau der (ungespeicherten) Vorlage mit Beispieldaten.
     */
    public function preview(Request $request): Response
    {
        $content = self::decodeContent($request);
        try {
            $pdf = $this->app->patientCardService()->previewPdf($content);
        } catch (PatientCardException $e) {
            return Response::html(self::errorPage($e), 422);
        }
        return Response::pdf($pdf, 'Ausweisvorlage-Vorschau.pdf', false);
    }

    /**
     * Datenblock des Editors (JSON-Attribut der Seite).
     *
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function editorData(PatientCardTemplateService $service, array $current): array
    {
        $settings = $this->app->patientCardRepository()->settings() ?? [];

        return [
            'type' => '',
            'definition' => PatientCardTemplate::editorDefinition(),
            'current' => $current,
            'versions' => array_map(self::versionSummary(...), $service->versions()),
            'csrf' => Csrf::token(),
            'urls' => [
                'save' => '/system/patient-card-templates',
                'preview' => '/system/patient-card-templates/preview',
                'version' => '/system/patient-card-templates/versions/',
                'editor' => '/system/patient-card-templates',
                'source' => '',
            ],
            'settings' => self::centerSettings($settings),
            'sample' => PatientCardSample::placeholderValues($settings),
            'labels' => [
                'document' => 'Ausweisvorlage',
                'documents' => 'Ausweise',
                'count_key' => 'card_count',
                'show_in' => 'Im Ausweis anzeigen',
                'zone_badge' => 'Fester Bereich · auf beiden Seiten',
                'placeholder_hint' => 'In ein Textfeld klicken, dann Platzhalter wählen. Er wird beim Erstellen des Ausweises durch die Daten ersetzt.',
            ],
        ];
    }

    /**
     * Stammdaten des Zentrums fuer die Live-Vorschau im Editor.
     *
     * @param array<string, mixed> $settings
     * @return array{center_name: string, center_address: string, has_logo: bool}
     */
    private static function centerSettings(array $settings): array
    {
        return [
            'center_name' => (string) ($settings['center_name'] ?? ''),
            'center_address' => (string) ($settings['center_address'] ?? ''),
            'has_logo' => ($settings['logo_id'] ?? null) !== null,
        ];
    }

    private static function decodeContent(Request $request): mixed
    {
        $raw = $request->post('content');
        if ($raw === '' || strlen($raw) > 512 * 1024) {
            return null;
        }
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, card_count: int}
     */
    private static function versionSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'version_no' => (int) $row['version_no'],
            'name' => (string) $row['name'],
            'comment' => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'card_count' => (int) ($row['card_count'] ?? 0),
        ];
    }

    /**
     * Feldfehler als einfache HTML-Seite (Ziel der PDF-Vorschau in einem neuen Tab).
     */
    private static function errorPage(PatientCardException $e): string
    {
        $lines = [];
        foreach ($e->fieldErrors() as $field => $message) {
            $lines[] = $field . ': ' . $message;
        }
        $escaped = array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $lines === [] ? [$e->getMessage()] : $lines,
        );

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Vorschau nicht möglich</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="container">'
            . '<div class="alert alert-error"><strong>Die Vorschau ist nicht möglich.</strong> Die Vorlage enthält Fehler:'
            . '<ul><li>' . implode('</li><li>', $escaped) . '</li></ul></div>'
            . '</main></body></html>';
    }
}
