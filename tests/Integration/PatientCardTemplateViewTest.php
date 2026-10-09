<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controller\PatientCardTemplateController;
use App\Http\Controller\SystemController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\View;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardTemplate;
use App\PatientCard\PatientCardTemplateRepository;
use App\PatientCard\PatientCardTemplateService;
use Tests\Support\PdfText;

/**
 * Vorlageneditor fuer Patientenausweise: Seite, Speichern (Fassungen, Konflikte), Fassungen,
 * PDF-Vorschau und die Einbettung der gueltigen Fassung in den Ausweis-Snapshot.
 */
final class PatientCardTemplateViewTest extends PatientCardTestCase
{
    private function templates(): PatientCardTemplateController
    {
        return new PatientCardTemplateController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    private function templateService(): PatientCardTemplateService
    {
        return new PatientCardTemplateService(new PatientCardTemplateRepository($this->pdo), $this->clock);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorData(string $body): array
    {
        preg_match('#<script type="application/json" id="template-editor-data">(.*?)</script>#s', $body, $match);
        return (array) json_decode($match[1] ?? '', true);
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, string> $extra
     */
    private function save(array $content, int $baseVersionId, array $extra = []): Response
    {
        return $this->templates()->save(new Request('POST', '/system/patient-card-templates', [], $extra + [
            'content' => json_encode($content),
            'base_version_id' => (string) $baseVersionId,
        ]));
    }

    /** Die Editorseite rendert eigenstaendig, CSP-konform und mit vollstaendigem JSON-Datenblock. */
    public function testEditorRendersStandalonePage(): void
    {
        $page = $this->templates()->editor(new Request('GET', '/system/patient-card-templates'));
        $this->assertSame(200, $page->status);
        $this->assertContains('/assets/js/template-editor.js', $page->body);
        $this->assertContains('/assets/css/template-editor.css', $page->body);
        $this->assertContains('id="template-editor-data"', $page->body);
        $this->assertContains('data-te-blocks', $page->body);
        $this->assertContains('data-te-preview-form', $page->body);
        $this->assertNotContains('<script>', $page->body);
        $this->assertNotContains('style="', $page->body);

        $data = $this->editorData($page->body);
        $this->assertSame('', $data['type']);
        $this->assertSame('patient_card', $data['definition']['kind']);
        $this->assertSame([], $data['definition']['types']);
        $this->assertSame(PatientCardTemplate::AREAS, $data['definition']['areas']);
        $this->assertSame(1, $data['current']['version_no']);
        $this->assertSame('Standardvorlage', $data['current']['name']);
        $this->assertSame('/system/patient-card-templates', $data['urls']['save']);
        $this->assertSame('/system/patient-card-templates/preview', $data['urls']['preview']);
        $this->assertSame('', $data['urls']['source']);
        $this->assertSame('Ausweisvorlage', $data['labels']['document']);
        $this->assertSame('card_count', $data['labels']['count_key']);
        $this->assertTrue(isset($data['sample']['patient_name'], $data['sample']['page'], $data['settings']['has_logo']));
        $this->assertCount(1, $data['versions']);
        $this->assertSame(0, $data['versions'][0]['card_count']);
        $this->assertNotSame('', (string) $data['csrf']);
        $this->assertSame(['header', 'footer', 'general'], array_keys($data['current']['content']['zones']));
    }

    /** Speichern erzeugt eine neue Fassung; Fehler, Konflikte und unveraenderte Inhalte werden abgelehnt. */
    public function testSaveCreatesVersionsAndReportsErrors(): void
    {
        $controller = $this->templates();
        $data = $this->editorData($controller->editor(new Request('GET', '/system/patient-card-templates'))->body);
        $base = (int) $data['current']['id'];
        $content = $data['current']['content'];

        // Unbekannter Platzhalter im freien Textbaustein wird als Feldfehler gemeldet.
        $content['blocks'] = array_reverse($content['blocks']);
        $content['blocks'][] = [
            'id' => 'text',
            'type' => 'text',
            'enabled' => true,
            'options' => [],
            'texts' => ['heading' => 'Hinweis', 'text' => 'Bitte {unbekannt} beachten.'],
        ];
        $invalid = $this->save($content, $base);
        $this->assertSame(422, $invalid->status);
        $errors = json_decode($invalid->body, true)['errors'];
        $this->assertTrue(isset($errors['blocks.' . (count($content['blocks']) - 1) . '.texts.text']));
        $this->assertSame(1, $this->templateService()->current()['version_no'], 'Ungueltige Vorlagen werden nicht gespeichert.');

        // Gueltige Aenderung: neue Fassung mit umgekehrter Reihenfolge und eigener Ueberschrift.
        $content['blocks'][count($content['blocks']) - 1]['texts']['text'] = 'Bitte an {center_name} wenden.';
        $content['zones']['header']['texts']['title'] = 'Ausweis für {patient_name}';
        $saved = $this->save($content, $base, ['comment' => 'Reihenfolge umgekehrt']);
        $this->assertSame(200, $saved->status, $saved->body);
        $payload = json_decode($saved->body, true);
        $this->assertTrue($payload['ok']);
        $this->assertSame(2, $payload['current']['version_no']);
        $this->assertSame('measurements', $payload['current']['content']['blocks'][0]['type']);
        $this->assertSame('Ausweis für {patient_name}', $payload['current']['content']['zones']['header']['texts']['title']);
        $this->assertCount(2, $payload['versions']);
        $this->assertSame('Reihenfolge umgekehrt', $payload['versions'][0]['comment']);
        $this->assertContains('Ausweisvorlage', (string) $payload['message']);

        // Unveraenderter Inhalt erzeugt keine neue Fassung.
        $unchanged = $this->save($payload['current']['content'], (int) $payload['current']['id']);
        $this->assertSame(422, $unchanged->status);
        $this->assertTrue(isset(json_decode($unchanged->body, true)['errors']['template']));
        $this->assertSame(2, $this->templateService()->current()['version_no']);

        // Veraltete Grundlage wird abgelehnt (kein stilles Ueberschreiben).
        $stale = $this->save($content, $base, ['comment' => 'veraltet']);
        $this->assertSame(422, $stale->status);
        $this->assertTrue(isset(json_decode($stale->body, true)['errors']['base_version']));
        $this->assertSame(2, $this->templateService()->current()['version_no']);

        // Defekte und leere Nutzlast.
        $broken = $controller->save(new Request('POST', '/system/patient-card-templates', [], ['content' => '{kaputt']));
        $this->assertSame(422, $broken->status);
        $this->assertSame('Ungültige Daten.', json_decode($broken->body, true)['errors']['template']);
    }

    /** Fassungen lassen sich einzeln laden; unbekannte Kennungen ergeben 404. */
    public function testVersionEndpointReturnsStoredVersion(): void
    {
        $controller = $this->templates();
        $base = $this->editorData($controller->editor(new Request('GET', '/system/patient-card-templates'))->body)['current'];

        $version = $controller->version(new Request('GET', '/x'), ['id' => (string) $base['id']]);
        $this->assertSame(200, $version->status);
        $this->assertSame(1, json_decode($version->body, true)['version']['version_no']);
        $this->assertThrows(HttpException::class, fn (): Response => $controller->version(new Request('GET', '/x'), ['id' => '999']));
        $this->assertThrows(HttpException::class, fn (): Response => $controller->version(new Request('GET', '/x'), ['id' => 'abc']));
    }

    /** Die Vorschau liefert ein PDF mit Beispieldaten und meldet ungueltige Vorlagen als Fehlerseite. */
    public function testPreviewReturnsPdfForValidTemplate(): void
    {
        $controller = $this->templates();
        $this->configureSettings();
        $content = $this->editorData($controller->editor(new Request('GET', '/system/patient-card-templates'))->body)['current']['content'];
        $content['zones']['header']['texts']['title'] = 'Ausweis für {patient_name}';
        $content['blocks'][count($content['blocks']) - 1]['enabled'] = false;

        $preview = $controller->preview(new Request('POST', '/system/patient-card-templates/preview', [], ['content' => json_encode($content)]));
        $this->assertSame(200, $preview->status);
        $this->assertSame('application/pdf', $preview->headers['Content-Type'] ?? '');
        $text = PdfText::text($preview->body);
        $this->assertContains('Ausweis für MUSTERMANN, ERIKA', $text);
        $this->assertNotContains('Schrittmacher - Patientenausweis', $text, 'Die Vorschau folgt der bearbeiteten Vorlage.');

        $bad = $controller->preview(new Request('POST', '/system/patient-card-templates/preview', [], ['content' => '[]']));
        $this->assertSame(422, $bad->status);
        $this->assertContains('Die Vorschau ist nicht möglich', $bad->body);
        $this->assertContains('name', $bad->body);
    }

    /** Der Ausweis friert die beim Erstellen gueltige Fassung ein; spaetere Aenderungen wirken nicht zurueck. */
    public function testCardSnapshotKeepsTemplateVersion(): void
    {
        $this->configureSettings();
        $outcome = $this->importSample();
        $service = $this->service();
        $report = $service->loadReport($outcome->reportId);
        $first = $service->create(PatientCardInput::fromPost($this->post()), $report, null);

        $stored = $service->card($first['card_id'], true);
        $snapshot = json_decode((string) $stored['snapshot'], true);
        $this->assertSame(1, (int) $stored['template_version_id']);
        $this->assertSame(1, $snapshot['template']['version_no']);
        $this->assertSame('Standardvorlage', $snapshot['template']['name']);
        $this->assertSame(
            PatientCardTemplate::default(),
            PatientCardTemplate::normalize($snapshot['template']['content']),
            'Die eingefrorene Vorlage entspricht der beim Erstellen gueltigen Fassung.',
        );
        $pdf = (string) $stored['pdf_content'];
        $this->assertContains('Schrittmacher - Patientenausweis', PdfText::text($pdf));

        // Neue Fassung mit anderer Ueberschrift und ausgeblendetem Hinweisbaustein.
        $current = $this->templateService()->current();
        $content = $current['content'];
        $content['zones']['header']['texts']['title'] = 'Ausweis für {patient_name}';
        $index = 0;
        foreach ($content['blocks'] as $i => $block) {
            if ($block['type'] === 'notice') {
                $index = $i;
            }
        }
        $content['blocks'][$index]['enabled'] = false;
        $this->templateService()->save($content, 'Ueberschrift geaendert', (int) $current['id']);
        $this->assertSame(2, $this->templateService()->current()['version_no']);

        $again = $service->card($first['card_id'], true);
        $this->assertSame($stored['snapshot'], $again['snapshot'], 'Der Snapshot des Ausweises bleibt unveraendert.');
        $this->assertSame($pdf, (string) $again['pdf_content'], 'Das PDF des Ausweises bleibt unveraendert.');

        // Neue Ausweise verwenden dagegen die aktuelle Fassung.
        $second = $service->create(PatientCardInput::fromPost($this->post()), $report, null);
        $nextSnapshot = json_decode((string) $service->card($second['card_id'], true)['snapshot'], true);
        $this->assertSame(2, $nextSnapshot['template']['version_no']);
        $this->assertSame('Ausweis für {patient_name}', $nextSnapshot['template']['content']['zones']['header']['texts']['title']);
        $this->assertNotContains('Schrittmacher - Patientenausweis', PdfText::text((string) $service->card($second['card_id'], true)['pdf_content']));
    }

    /** Die Ausweisliste weist die eingefrorene Fassung aus (Nachvollziehbarkeit). */
    public function testCardListShowsTemplateVersionCount(): void
    {
        $this->createCard();
        $rows = $this->templateService()->versions();
        $this->assertSame(1, (int) $rows[0]['card_count']);
    }

    /** Der Systembereich verlinkt den Editor; das Funktionsband kennt den Bereich. */
    public function testSystemNavigationLinksTheEditor(): void
    {
        $response = (new SystemController($this->app, new View(dirname(__DIR__, 2) . '/templates')))->index();
        $this->assertSame(200, $response->status);
        $this->assertContains('/system/patient-card-templates', $response->body);
        $this->assertContains('Ausweisvorlage', $response->body);
    }
}
