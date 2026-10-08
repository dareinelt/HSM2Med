<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\DashboardController;
use App\Http\Controller\PatientController;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\View;
use App\Security\SessionManager;

/**
 * Der Patientenvorgang ist fuehrend.
 *
 * Ohne aktiven Patienten sind Import, Patientenausweis und Brief nicht zu starten; Listen,
 * Berichte und Nachschlagewerke bleiben erreichbar. Das Neuanlegen eines Patienten waehlt
 * ihn automatisch als aktiven Patienten.
 *
 * Hinweis: Der Kernel kann hier nicht ausgefuehrt werden, weil der Testlauf bereits Ausgaben
 * erzeugt hat und PHP danach keine Sitzung mehr starten kann. Geprueft wird deshalb die
 * Entscheidung des Kernels (requiresPatientSelection) ueber die echte Routentabelle.
 */
final class PatientFirstWorkflowTest extends DatabaseTestCase
{
    private Application $app;

    public function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
    }

    public function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function view(): View
    {
        // Wie im Kernel: der aktive Patient wird erst beim Rendern ermittelt.
        return new View(dirname(__DIR__, 2) . '/templates', fn (): ?array => $this->app->activePatientSummary());
    }

    private function patients(): PatientController
    {
        return new PatientController($this->app, $this->view());
    }

    private function dashboard(): DashboardController
    {
        return new DashboardController($this->app, $this->view());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'last_name' => 'Mustermann',
            'first_name' => 'Erika',
            'date_of_birth' => '21.10.1938',
            'patient_identifier' => 'P-100',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '01234/56789',
            'indication' => 'Bradykardie, geplante Schrittmacherimplantation',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function create(array $overrides = []): int
    {
        $response = $this->patients()->create(new Request('POST', '/patients', [], $this->post($overrides)));
        $location = (string) ($response->headers['Location'] ?? '');
        return (int) substr($location, (int) strrpos($location, '/') + 1);
    }

    /** Das Neuanlegen eines Patienten waehlt ihn automatisch als aktiven Patienten. */
    public function testCreateSelectsTheNewPatient(): void
    {
        $this->assertNull($this->app->activePatient()->id());

        $patientId = $this->create();

        $this->assertSame($patientId, $this->app->activePatient()->id());
        $this->assertTrue($this->app->activePatient()->isSelected());

        $flashes = SessionManager::takeFlashes();
        $this->assertSame(1, count($flashes));
        $this->assertSame('success', $flashes[0]['type']);
        $this->assertContains('als aktiver Patient ausgewählt', $flashes[0]['message']);
    }

    /** Die Akte weist den aktiven Patienten aus und bietet das Aufheben der Auswahl an. */
    public function testRecordShowsActiveState(): void
    {
        $patientId = $this->create();

        $show = $this->patients()->show(new Request('GET', '/patients/' . $patientId), ['id' => (string) $patientId]);

        $this->assertSame(200, $show->status);
        $this->assertContains('Aktiver Patient', $show->body);
        $this->assertContains('action="/patients/select/clear"', $show->body);
        $this->assertContains('name="_csrf"', $show->body);
    }

    /** Ein anderer Patient laesst sich auswaehlen; die Auswahl ist danach umgestellt. */
    public function testSelectSwitchesTheActivePatient(): void
    {
        $first = $this->create(['patient_identifier' => 'P-1']);
        $second = $this->create(['first_name' => 'Max', 'patient_identifier' => 'P-2']);
        $this->assertSame($second, $this->app->activePatient()->id());

        $response = $this->patients()->select(new Request('POST', '/patients/' . $first . '/select'), ['id' => (string) $first]);

        $this->assertSame(303, $response->status);
        $this->assertSame('/patients/' . $first, $response->headers['Location']);
        $this->assertSame($first, $this->app->activePatient()->id());

        $show = $this->patients()->show(new Request('GET', '/patients/' . $second), ['id' => (string) $second]);
        $this->assertContains('Als aktiven Patienten wählen', $show->body);
    }

    /** Die Auswahl laesst sich aufheben; danach sind die Vorgaenge wieder gesperrt. */
    public function testClearRemovesTheSelection(): void
    {
        $patientId = $this->create();

        $response = $this->patients()->clearActive(new Request('POST', '/patients/select/clear'));

        $this->assertSame(303, $response->status);
        $this->assertSame('/patients', $response->headers['Location']);
        $this->assertNull($this->app->activePatient()->id());

        $index = $this->patients()->index(new Request('GET', '/patients'));
        $this->assertContains('Kein Patient gewählt. Import, Patientenausweis und Brief', $index->body);
        $this->assertContains('action="/patients/' . $patientId . '/select"', $index->body);
    }

    /** Die Uebersicht weist ohne Auswahl darauf hin, dass ein Patient fehlt. */
    public function testIndexExplainsMissingSelection(): void
    {
        $this->create();
        $this->app->activePatient()->clear();

        $index = $this->patients()->index(new Request('GET', '/patients'));

        $this->assertContains('Kein Patient gewählt. Import, Patientenausweis und Brief', $index->body);
        $this->assertContains('Auswählen', $index->body);
        $this->assertContains('href="/patients/new"', $index->body);
    }

    /** Die Uebersicht weist mit Auswahl den aktiven Patienten aus. */
    public function testIndexShowsActivePatient(): void
    {
        $patientId = $this->create();

        $index = $this->patients()->index(new Request('GET', '/patients'));

        $this->assertContains('Aktiver Patient: Nr. ' . $patientId, $index->body);
        $this->assertContains('class="is-active-patient"', $index->body);
    }

    /** Ohne aktiven Patienten sind alle patientenbezogenen Vorgaenge gesperrt. */
    public function testGatedRoutesRequireAPatient(): void
    {
        $kernel = new Kernel($this->app);
        $this->assertFalse($this->app->activePatient()->isSelected());

        foreach ([
            '/import',
            '/patient-cards/new',
            '/patient-cards/patients/1',
            '/patient-cards/reports/1',
            '/letters/new',
            '/letters/patients/1',
        ] as $path) {
            $this->assertTrue(
                $kernel->requiresPatientSelection(new Request('GET', $path)),
                'Vorgang muss einen Patienten voraussetzen: ' . $path,
            );
        }

        foreach (['/import', '/letters', '/patient-cards/reports/1'] as $path) {
            $this->assertTrue(
                $kernel->requiresPatientSelection(new Request('POST', $path, [], ['_csrf' => 'x'])),
                'Auch das Absenden muss einen Patienten voraussetzen: ' . $path,
            );
        }
    }

    /** Listen, Berichte und Nachschlagewerke bleiben ohne Patienten erreichbar. */
    public function testOpenRoutesStayReachableWithoutPatient(): void
    {
        $kernel = new Kernel($this->app);

        foreach ([
            '/',
            '/health',
            '/reports',
            '/reports/1',
            '/imports',
            '/patients',
            '/patients/new',
            '/patients/1',
            '/patient-cards',
            '/patient-cards/1',
            '/patient-cards/settings',
            '/letters',
            '/letters/1',
            '/system',
        ] as $path) {
            $this->assertFalse(
                $kernel->requiresPatientSelection(new Request('GET', $path)),
                'Ziel darf keinen Patienten voraussetzen: ' . $path,
            );
        }
    }

    /** Mit aktivem Patienten sind die Vorgaenge freigegeben. */
    public function testGatedRoutesOpenWithPatient(): void
    {
        $patientId = $this->create();
        $kernel = new Kernel($this->app);

        foreach (['/import', '/patient-cards/new', '/letters/new', '/patient-cards/patients/1', '/letters/patients/1'] as $path) {
            $this->assertFalse(
                $kernel->requiresPatientSelection(new Request('GET', $path)),
                'Vorgang muss mit Patienten freigegeben sein: ' . $path,
            );
        }

        $this->assertSame($patientId, $this->app->activePatient()->id());
    }

    /** Ohne Patienten weist der Rahmen (Titelleiste, Funktionsband) auf die fehlende Auswahl hin. */
    public function testFrameShowsMissingSelection(): void
    {
        $response = $this->dashboard()
            ->index(new Request('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertContains('Kein Patient gewählt', $response->body);
        $this->assertContains('is-disabled', $response->body);
    }

    /** Mit Patienten zeigt der Rahmen den aktiven Patienten; das Funktionsband ist bedienbar. */
    public function testFrameShowsActivePatient(): void
    {
        $this->create();

        $response = $this->dashboard()
            ->index(new Request('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertContains('Mustermann, Erika', $response->body);
        $this->assertFalse(str_contains($response->body, 'Kein Patient gewählt'));
    }

    /** Der Fuß verweist auf die Autoren-Info; der Hinweistext liegt als Overlay im Rahmen. */
    public function testFooterShowsAuthorInfo(): void
    {
        $response = $this->dashboard()
            ->index(new Request('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertContains('data-author-info', $response->body);
        $this->assertContains('HSM2Med by Daniel-André Reinelt', $response->body);
        $this->assertContains('data-author-info-dialog', $response->body);
        $this->assertContains('<strong>kein Medizinprodukt</strong>', $response->body);
        $this->assertContains('Für die Therapieentscheidung und medizinische Beurteilung', $response->body);
        $this->assertContains('Anthropic Claude Opus 5.5, DeepSeek 4.1 Flash, Qwen3.8', $response->body);
    }

    /** Eine veraltete Auswahl wird beim Auflösen verworfen. */
    public function testStaleSelectionIsDropped(): void
    {
        $patientId = $this->create();
        $this->app->activePatient()->select($patientId + 999);
        $this->assertSame($patientId + 999, $this->app->activePatient()->id());

        $this->assertNull($this->app->activePatientSummary());
        $this->assertNull($this->app->activePatient()->id());
        $this->assertFalse($this->app->activePatient()->isSelected());
    }
}
