<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Config\Config;
use App\Http\Controller\SystemController;
use App\Http\Controller\SystemSettingsController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\View;
use App\PatientCard\PatientCardRepository;
use App\Security\SessionManager;
use Tests\Support\Images;

/**
 * Rendert die Oberflaeche der Praxis-Informationen im Bereich "System" ueber die echten
 * Controller und Templates.
 *
 * Schuetzt vor Fehlern, die ausschliesslich in der HTML-Schicht auftreten: unbekannte
 * Klassenreferenzen in Templates, fehlende Template-Variablen, unvollstaendige Formulare
 * und Fehlerseiten, die sonst erst im Browser auffallen.
 */
final class SystemSettingsViewTest extends DatabaseTestCase
{
    private Application $app;

    public function setUp(): void
    {
        parent::setUp();
        $this->app = new Application(Config::fromEnvironment(), dirname(__DIR__, 2), $this->clock);
    }

    private function settings(): SystemSettingsController
    {
        return new SystemSettingsController($this->app, new View(dirname(__DIR__, 2) . '/templates'));
    }

    private function repository(): PatientCardRepository
    {
        return new PatientCardRepository($this->pdo);
    }

    private function configureSettings(?int $logoId = null): void
    {
        $this->repository()->saveSettings([
            'center_name' => 'Praxis am Markt',
            'center_address' => "Marktplatz 3\n54321 Musterstadt",
            'practice_phone' => '05432/112233',
            'practice_fax' => '05432/112244',
            'practice_email' => 'praxis@example.de',
            'practice_website' => 'www.praxis-am-markt.de',
            'return_name' => 'Praxis am Markt',
            'return_street' => 'Postfach 12',
            'return_postal_code' => '54320',
            'return_city' => 'Musterstadt',
            'notice_text' => 'Hinweis.',
            'flight_notice_de' => 'Hinweis Flug.',
            'flight_notice_en' => 'Flight notice.',
            'logo_id' => $logoId,
        ], '2026-10-07 08:00:00');
    }

    /** Formular mit allen Feldern, Abschnitten und der Fassungszahl. */
    public function testFormRendersAllPracticeFields(): void
    {
        $this->configureSettings();

        $response = $this->settings()->index(new Request('GET', '/system/settings'));

        $this->assertSame(200, $response->status);
        $this->assertContains('name="_csrf"', $response->body);
        $this->assertContains('action="/system/settings"', $response->body);
        $this->assertContains('enctype="multipart/form-data"', $response->body);
        $this->assertContains('Praxis-Informationen', $response->body);
        $this->assertContains('Rücksendeangaben', $response->body);
        foreach ([
            'center_name' => 'Praxis am Markt',
            'center_address' => 'Marktplatz 3',
            'practice_phone' => '05432/112233',
            'practice_fax' => '05432/112244',
            'practice_email' => 'praxis@example.de',
            'practice_website' => 'www.praxis-am-markt.de',
            'return_name' => 'Praxis am Markt',
            'return_street' => 'Postfach 12',
            'return_postal_code' => '54320',
            'return_city' => 'Musterstadt',
        ] as $field => $value) {
            $this->assertContains('name="' . $field . '"', $response->body);
            $this->assertContains($value, $response->body);
        }
        $this->assertContains('Kein Logo hinterlegt.', $response->body);
        $this->assertContains('Bisher gespeicherte Fassungen: 1', $response->body);
    }

    /** Logo: Vorschau im Formular und Auslieferung des Bildes. */
    public function testFormRendersLogoAndServesIt(): void
    {
        $bytes = Images::png(40, 20);
        $logoId = $this->repository()->insertLogo(
            hash('sha256', $bytes),
            'image/png',
            'logo.png',
            40,
            20,
            $bytes,
            '2026-10-07 08:00:00',
        );
        $this->configureSettings($logoId);

        $controller = $this->settings();
        $response = $controller->index(new Request('GET', '/system/settings'));

        $this->assertSame(200, $response->status);
        $this->assertContains('src="/system/settings/logo"', $response->body);
        $this->assertContains('logo.png', $response->body);
        $this->assertContains('40×20 px', $response->body);

        $image = $controller->logo(new Request('GET', '/system/settings/logo'));
        $this->assertSame(200, $image->status);
        $this->assertSame('image/png', $image->headers['Content-Type'] ?? '');
        $this->assertSame($bytes, $image->body);
    }

    /** Ohne hinterlegtes Logo liefert die Vorschau einen Fehler. */
    public function testLogoIsNotFoundWithoutUpload(): void
    {
        $this->configureSettings();

        $this->assertThrows(
            HttpException::class,
            fn () => $this->settings()->logo(new Request('GET', '/system/settings/logo')),
        );
    }

    /** Speichern legt eine neue Fassung an und leitet mit Erfolgsmeldung zurueck. */
    public function testSaveStoresNewVersionAndRedirects(): void
    {
        $this->configureSettings();

        $response = $this->settings()->save(new Request('POST', '/system/settings', [], [
            'center_name' => 'Praxis am Markt',
            'center_address' => "Marktplatz 3\n54321 Musterstadt",
            'practice_phone' => '05432/999999',
            'practice_fax' => '05432/112244',
            'practice_email' => 'neu@example.de',
            'practice_website' => 'www.praxis-am-markt.de',
            'return_name' => 'Praxis am Markt',
            'return_street' => 'Postfach 12',
            'return_postal_code' => '54320',
            'return_city' => 'Musterstadt',
        ]));

        $this->assertSame(303, $response->status);
        $this->assertSame('/system/settings', $response->headers['Location'] ?? '');

        $messages = array_column(SessionManager::takeFlashes(), 'message');
        $matches = array_values(array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'Praxis-Informationen wurden als neue Fassung Nr. 2 gespeichert'),
        ));
        $this->assertSame(1, count($matches));

        $settings = $this->repository()->settings() ?? [];
        $this->assertSame('05432/999999', $settings['practice_phone']);
        $this->assertSame('neu@example.de', $settings['practice_email']);
        $this->assertSame(2, $this->repository()->countSettingsVersions());
        $this->assertSame('Hinweis.', $settings['notice_text'], 'Die Hinweistexte des Ausweises bleiben erhalten');
        $this->assertSame('Hinweis Flug.', $settings['flight_notice_de']);
        $this->assertSame('Flight notice.', $settings['flight_notice_en']);
    }

    /** Ungueltige Eingaben werden im Formular angezeigt, ohne neue Fassung. */
    public function testSaveRejectsInvalidEmailAddress(): void
    {
        $this->configureSettings();

        $response = $this->settings()->save(new Request('POST', '/system/settings', [], [
            'center_name' => 'Praxis am Markt',
            'practice_email' => 'keine-adresse',
        ]));

        $this->assertSame(422, $response->status);
        $this->assertContains('Bitte eine gültige E-Mail-Adresse angeben', $response->body);
        $this->assertContains('field-error', $response->body);
        $this->assertSame(1, $this->repository()->countSettingsVersions());
        $this->assertSame('praxis@example.de', ($this->repository()->settings() ?? [])['practice_email']);
    }

    /** Die Systeminformationen verlinken die Praxis-Informationen. */
    public function testSystemPageLinksPracticeSettings(): void
    {
        $controller = new SystemController($this->app, new View(dirname(__DIR__, 2) . '/templates'));

        $response = $controller->index();

        $this->assertSame(200, $response->status);
        $this->assertContains('href="/system/settings"', $response->body);
        $this->assertContains('Praxis-Informationen', $response->body);
    }
}
