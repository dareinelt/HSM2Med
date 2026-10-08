<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Config\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Letter\LetterException;
use App\Letter\LetterTemplate;
use App\Security\Csrf;

/**
 * Vorlageneditor fuer die Briefe (System-Bereich, eigener Browser-Tab).
 *
 * Die Seite ist eigenstaendig (ohne Funktionsband) und wird vollstaendig per JavaScript
 * bedient (public/assets/js/template-editor.js, CSP-konform ohne Inline-Skripte). Die Daten
 * gelangen als JSON-Datenblock in die Seite; gespeichert wird per fetch() mit CSRF-Token.
 *
 * Jede Speicherung erzeugt eine neue, unveraenderliche Fassung. Bereits erstellte Briefe
 * behalten ihre eingefrorene Vorlage.
 */
final class LetterTemplateController extends Controller
{
    public function editor(Request $request): Response
    {
        $service = $this->app->letterTemplateService();
        $current = $service->current();
        $versions = $service->versions();

        $data = [
            'definition' => LetterTemplate::editorDefinition(),
            'current' => $current,
            'versions' => array_map(self::versionSummary(...), $versions),
            'csrf' => Csrf::token(),
            'urls' => [
                'save' => '/system/letter-templates',
                'preview' => '/system/letter-templates/preview',
                'version' => '/system/letter-templates/versions/',
            ],
            'settings' => $this->centerSettings(),
        ];

        return Response::html($this->view->renderPartial('letter_templates/editor', [
            'title' => 'Briefvorlage bearbeiten',
            'appName' => Config::APP_NAME,
            'appVersion' => Config::APP_VERSION,
            'current' => $current,
            'data' => json_encode(
                $data,
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
        $version = $this->app->letterTemplateService()->version(self::id($params));
        if ($version === null) {
            throw HttpException::notFound('Die Fassung der Briefvorlage wurde nicht gefunden.');
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
            return Response::json(['ok' => false, 'message' => 'Die Vorlage konnte nicht gelesen werden.', 'errors' => ['template' => 'Ungültige Daten.']], 422);
        }
        $base = (string) ($request->post['base_version_id'] ?? '');
        $service = $this->app->letterTemplateService();
        try {
            $saved = $service->save(
                $content,
                (string) ($request->post['comment'] ?? ''),
                ctype_digit($base) ? (int) $base : null,
            );
        } catch (LetterException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->fieldErrors()], 422);
        }

        return Response::json([
            'ok' => true,
            'message' => sprintf('Fassung %d der Briefvorlage wurde gespeichert. Neue Briefe verwenden ab sofort diese Fassung.', $saved['version_no']),
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
            $pdf = $this->app->letterService()->previewPdf($content);
        } catch (LetterException $e) {
            $lines = [];
            foreach ($e->fieldErrors() as $field => $message) {
                $lines[] = $field . ': ' . $message;
            }
            return Response::html(
                '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Vorschau nicht möglich</title>'
                . '<link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="container">'
                . '<div class="alert alert-error"><strong>Die Vorschau ist nicht möglich.</strong> Die Vorlage enthält Fehler:'
                . '<ul><li>' . implode('</li><li>', array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $lines ?: [$e->getMessage()])) . '</li></ul></div>'
                . '</main></body></html>',
                422,
            );
        }
        return Response::pdf($pdf, 'Briefvorlage-Vorschau.pdf', false);
    }

    private static function decodeContent(Request $request): mixed
    {
        $raw = (string) ($request->post['content'] ?? '');
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
     * @return array{id: int, version_no: int, name: string, comment: string, created_at: string, letter_count: int}
     */
    private static function versionSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'version_no' => (int) $row['version_no'],
            'name' => (string) $row['name'],
            'comment' => (string) ($row['comment'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'letter_count' => (int) ($row['letter_count'] ?? 0),
        ];
    }

    /**
     * Stammdaten des Zentrums fuer die Live-Vorschau im Editor.
     *
     * @return array{center_name: string, center_address: string, has_logo: bool}
     */
    private function centerSettings(): array
    {
        $settings = $this->app->patientCardRepository()->settings() ?? [];
        return [
            'center_name' => (string) ($settings['center_name'] ?? ''),
            'center_address' => (string) ($settings['center_address'] ?? ''),
            'has_logo' => ($settings['logo_id'] ?? null) !== null,
        ];
    }
}
