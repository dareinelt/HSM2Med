<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Request;
use App\Http\Response;
use App\PatientCard\PatientCardInput;
use Tests\Support\Images;

/**
 * Rendert die Oberflaechen des Patientenausweises ueber die echten Controller und Templates.
 *
 * Schuetzt vor Fehlern, die ausschliesslich in der HTML-Schicht auftreten: unbekannte
 * Klassenreferenzen in Templates, fehlende Template-Variablen, unvollstaendige Formulare
 * und Fehlerseiten, die sonst erst im Browser auffallen.
 */
final class PatientCardViewTest extends PatientCardTestCase
{
    /** Uebersicht: leerer Zustand und Treffer der Suche (nutzt PatientCardRepository::search). */
    public function testCardListRendersEmptyAndFilled(): void
    {
        $empty = $this->cards()->index(new Request('GET', '/patient-cards'));
        $this->assertSame(200, $empty->status);
        $this->assertContains('Patientenausweise', $empty->body);
        $this->assertContains('Ausweis aus einem Bericht erstellen', $empty->body);

        $card = $this->createCard();

        $filled = $this->cards()->index(new Request('GET', '/patient-cards', ['q' => 'LASTNAME']));
        $this->assertSame(200, $filled->status);
        $this->assertContains('/patient-cards/' . $card['card_id'], $filled->body);
        $this->assertContains('LASTNAME', $filled->body);

        $patientFilter = $this->cards()->index(new Request('GET', '/patient-cards', ['patient' => 'LASTNAME']));
        $this->assertContains('/patient-cards/' . $card['card_id'], $patientFilter->body);

        $noMatch = $this->cards()->index(new Request('GET', '/patient-cards', ['q' => 'gibtesnicht']));
        $this->assertContains('0 Ausweis(e) gefunden', $noMatch->body);
        $this->assertContains('Noch keine Patientenausweise erstellt', $noMatch->body);
    }

    /** Berichtsauswahl als Einstieg in den Assistenten. */
    public function testReportSelectionRenders(): void
    {
        $outcome = $this->importSample();
        $response = $this->cards()->selectReport(new Request('GET', '/patient-cards/new'));

        $this->assertSame(200, $response->status);
        $this->assertContains('/patient-cards/reports/' . $outcome->reportId, $response->body);
        $this->assertContains('Ausweis erstellen', $response->body);
    }

    /** Assistent: alle sechs Schritte, CSRF-Feld und Vorbelegung aus dem Bericht. */
    public function testWizardRendersAllSixSteps(): void
    {
        $this->configureSettings();
        $outcome = $this->importSample();

        $response = $this->cards()->wizard(
            new Request('GET', '/patient-cards/reports/' . $outcome->reportId, ['step' => '6']),
            ['id' => (string) $outcome->reportId],
        );

        $this->assertSame(200, $response->status);
        foreach ([
            '1. Patient identifizieren',
            '2. Patientendaten ergänzen',
            '3. Notfallkontakt',
            '4. Hausarzt',
            '5. Nachsorge und Kontrolle',
            '6. Zusammenfassung und Bestätigung',
        ] as $heading) {
            $this->assertContains($heading, $response->body);
        }
        $this->assertContains('name="_csrf"', $response->body);
        $this->assertContains('value="LASTNAME"', $response->body);
        $this->assertContains('Nachsorgezentrum Beispielstadt', $response->body);
        $this->assertContains('Ja, dies ist der richtige Patient.', $response->body);
        // MRT-Tauglichkeit: Auswahlfeld mit allen Werten plus Zusatzangabe
        $this->assertContains('name="mrt_compatibility"', $response->body);
        $this->assertContains('MRT-Tauglichkeit: Zusatzangabe', $response->body);
        foreach (PatientCardInput::MRT_VALUES as $mrtValue) {
            $this->assertContains('>' . $mrtValue . '</option>', $response->body);
        }
    }

    /** MRT-Tauglichkeit wird gespeichert und in Assistent, Zusammenfassung und Detailansicht angezeigt. */
    public function testWizardAndDetailShowMrtCompatibility(): void
    {
        $this->configureSettings();
        $outcome = $this->importSample();
        $service = $this->service();
        $wizard = $service->wizard($outcome->reportId);
        $result = $service->create(
            PatientCardInput::fromPost($this->post([
                'mrt_compatibility' => 'MRT-bedingt tauglich',
                'mrt_compatibility_note' => 'Nur mit Auflagen.',
            ])),
            $wizard['report'],
            $wizard['masterData'],
        );

        // Zusammenfassung des Assistenten (Schritt 6) zeigt den Wert
        $summary = $this->cards()->wizard(
            new Request('GET', '/patient-cards/reports/' . $outcome->reportId, ['step' => '6']),
            ['id' => (string) $outcome->reportId],
        );
        $this->assertContains('MRT-bedingt tauglich (Nur mit Auflagen.)', $summary->body);

        // Detailansicht zeigt die Angabe aus dem Snapshot
        $detail = $this->cards()->show(
            new Request('GET', '/patient-cards/' . $result['card_id']),
            ['id' => (string) $result['card_id']],
        );
        $this->assertContains('MRT-Tauglichkeit', $detail->body);
        $this->assertContains('MRT-bedingt tauglich (Nur mit Auflagen.)', $detail->body);
    }

    /** Unbekannter Auswahlwert: 422 und kein Ausweis. */
    public function testWizardRejectsUnknownMrtValue(): void
    {
        $this->configureSettings();
        $outcome = $this->importSample();

        $response = $this->cards()->generate(
            new Request('POST', '/patient-cards/reports/' . $outcome->reportId, [], $this->post(['mrt_compatibility' => 'irgendwie tauglich'])),
            ['id' => (string) $outcome->reportId],
        );

        $this->assertSame(422, $response->status);
        $this->assertContains('MRT-Tauglichkeit', $response->body);
        $this->assertSame(0, $this->rowCount('patient_cards'));
    }

    /** Konflikt zwischen Eingabe und bestaetigten Angaben: 422 mit Entscheidungstabelle. */
    public function testWizardRendersConflictTable(): void
    {
        $card = $this->createCard();

        $response = $this->cards()->generate(
            new Request('POST', '/patient-cards/reports/' . $card['report_id'], [], $this->post(['street' => 'Andere Straße 5'])),
            ['id' => (string) $card['report_id']],
        );

        $this->assertSame(422, $response->status);
        $this->assertContains('Abweichungen zu bereits bestätigten Angaben', $response->body);
        $this->assertContains('name="conflict[street]"', $response->body);
        $this->assertContains('Andere Straße 5', $response->body);
        $this->assertContains('Musterstraße 12', $response->body);
        $this->assertContains('Bitte für jedes Feld entscheiden', $response->body);
    }

    /** Fehlende Bestaetigung: 422 mit Fehlermeldung, kein Ausweis. */
    public function testWizardRendersMissingConfirmation(): void
    {
        $outcome = $this->importSample();
        $this->configureSettings();

        $response = $this->cards()->generate(
            new Request('POST', '/patient-cards/reports/' . $outcome->reportId, [], $this->post(['confirm_merge' => '0'])),
            ['id' => (string) $outcome->reportId],
        );

        $this->assertSame(422, $response->status);
        $this->assertContains('ist erforderlich', $response->body);
        $this->assertSame(0, $this->rowCount('patient_cards'));
    }

    /** Detailansicht eines Ausweises inklusive Snapshot-Angaben. */
    public function testCardDetailRenders(): void
    {
        $card = $this->createCard();

        $response = $this->cards()->show(
            new Request('GET', '/patient-cards/' . $card['card_id']),
            ['id' => (string) $card['card_id']],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('Patientenausweis Nr. ' . $card['card_id'], $response->body);
        $this->assertContains('Prüfsumme (SHA-256)', $response->body);
        $this->assertContains('Nachsorgezentrum Beispielstadt', $response->body);
        $this->assertContains('PDF herunterladen', $response->body);
        $this->assertContains('/patient-cards/' . $card['card_id'] . '/pdf', $response->body);
    }

    /** Patientenseite mit Ausweisen und Nachsorgeverlauf. */
    public function testPatientHistoryRenders(): void
    {
        $card = $this->createCard();

        $response = $this->cards()->patient(
            new Request('GET', '/patient-cards/patients/' . $card['patient_id']),
            ['patient' => (string) $card['patient_id']],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('LASTNAME', $response->body);
        $this->assertContains('/patient-cards/' . $card['card_id'], $response->body);
        $this->assertContains('Nachsorgeuntersuchungen', $response->body);
    }

    /** Hinweistexte des Ausweises; Praxis-Informationen werden nur angezeigt. */
    public function testSettingsFormRenders(): void
    {
        $this->configureSettings();

        $response = $this->cardSettings()->index(new Request('GET', '/patient-cards/settings'));

        $this->assertSame(200, $response->status);
        $this->assertContains('name="_csrf"', $response->body);
        $this->assertContains('action="/patient-cards/settings"', $response->body);
        $this->assertContains('Nachsorgezentrum Beispielstadt', $response->body);
        $this->assertContains('Kein Logo hinterlegt.', $response->body);
        $this->assertContains('Bisher gespeicherte Fassungen: 1', $response->body);

        // Die Praxis-Informationen sind hier nur lesbar und werden im Bereich System gepflegt.
        $this->assertContains('/system/settings', $response->body);
        $this->assertNotContains('name="center_name"', $response->body);
        $this->assertNotContains('name="return_street"', $response->body);
        $this->assertContains('name="notice_text"', $response->body);
    }

    /** Stammdatenformular mit Logo: Vorschau, Metadaten und Auslieferung des Bildes. */
    public function testSettingsFormRendersLogoAndServesIt(): void
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

        $controller = $this->cardSettings();
        $response = $controller->index(new Request('GET', '/patient-cards/settings'));

        $this->assertSame(200, $response->status);
        $this->assertContains('src="/patient-cards/settings/logo"', $response->body);
        $this->assertContains('width="40" height="20"', $response->body);
        $this->assertNotContains('Kein Logo hinterlegt.', $response->body);

        $image = $controller->logo(new Request('GET', '/patient-cards/settings/logo'));
        $this->assertSame(200, $image->status);
        $this->assertSame('image/png', $image->headers['Content-Type'] ?? '');
        $this->assertSame($bytes, $image->body);
    }

    /** Ausweispdf wird aus dem Snapshot ausgeliefert (inline und als Download). */
    public function testCardPdfResponse(): void
    {
        $card = $this->createCard();

        $inline = $this->cards()->pdf(new Request('GET', '/patient-cards/' . $card['card_id'] . '/pdf'), ['id' => (string) $card['card_id']]);
        $this->assertSame(200, $inline->status);
        $this->assertTrue(str_starts_with($inline->body, '%PDF-'), 'Antwort ist kein PDF.');
        $this->assertContains('inline;', $inline->headers['Content-Disposition'] ?? '');

        $download = $this->cards()->pdf(
            new Request('GET', '/patient-cards/' . $card['card_id'] . '/pdf', ['download' => '1']),
            ['id' => (string) $card['card_id']],
        );
        $this->assertContains('attachment;', $download->headers['Content-Disposition'] ?? '');
        $this->assertContains('Patientenausweis_LASTNAME_FIRSTNAME_', $download->headers['Content-Disposition'] ?? '');
    }

    /** Unbekannte Kennungen liefern 404 statt einer Fehlerseite. */
    public function testUnknownIdsAreNotFound(): void
    {
        $this->assertThrows(
            \App\Http\HttpException::class,
            fn () => $this->cards()->show(new Request('GET', '/patient-cards/9999'), ['id' => '9999']),
        );
        $this->assertThrows(
            \App\Http\HttpException::class,
            fn () => $this->cards()->patient(new Request('GET', '/patient-cards/patients/9999'), ['patient' => '9999']),
        );
    }

    /** Kein Ausweis ohne Bericht: der Assistent eines unbekannten Berichts endet in 404. */
    public function testWizardWithoutReportIsNotFound(): void
    {
        $this->assertThrows(
            \App\Http\HttpException::class,
            fn () => $this->cards()->wizard(new Request('GET', '/patient-cards/reports/9999'), ['id' => '9999']),
        );
    }

    /** Antworttypen sind unveraendert HTML. */
    public function testResponsesAreHtml(): void
    {
        $response = $this->cards()->index(new Request('GET', '/patient-cards'));
        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type'] ?? '');
        $this->assertTrue($response instanceof Response);
    }

    /** Berichtsdetail nennt den Ausweis, sobald einer existiert (§14). */
    public function testReportDetailListsCardVersions(): void
    {
        $card = $this->createCard();
        $response = $this->reports()->show(
            new Request('GET', '/reports/' . $card['report_id']),
            ['id' => (string) $card['report_id']],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('Patientenausweis', $response->body);
        $this->assertContains('1 Fassung(en)', $response->body);
        $this->assertContains('/patient-cards/' . $card['card_id'] . '/pdf?download=1', $response->body);
        $this->assertContains('/patient-cards/patients/' . $card['patient_id'], $response->body);
    }

    /** Berichtsdetail ohne Ausweis bietet das Erstellen an. */
    public function testReportDetailWithoutCardOffersCreation(): void
    {
        $outcome = $this->importSample();
        $response = $this->reports()->show(
            new Request('GET', '/reports/' . $outcome->reportId),
            ['id' => (string) $outcome->reportId],
        );

        $this->assertSame(200, $response->status);
        $this->assertContains('0 Fassung(en)', $response->body);
        $this->assertContains('/patient-cards/reports/' . $outcome->reportId, $response->body);
    }
}
