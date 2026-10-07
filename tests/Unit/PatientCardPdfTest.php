<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\PatientCard\PatientCardPdfGenerator;
use App\Report\Pdf\ImageData;
use App\Report\Pdf\PdfDocument;
use DateTimeImmutable;
use Tests\Support\Images;
use Tests\Support\PatientCardFactory;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Patientenausweis-PDF: genau zwei DIN-A4-Seiten mit allen Pflichtangaben.
 */
final class PatientCardPdfTest extends TestCase
{
    private function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));
    }

    public function testProducesExactlyTwoA4Pages(): void
    {
        $pdf = (new PatientCardPdfGenerator())->generate(PatientCardFactory::snapshot(), null, $this->generatedAt());

        $this->assertTrue(str_starts_with($pdf, '%PDF-1.4'));
        $this->assertTrue(str_ends_with($pdf, "%%EOF\n"));
        $this->assertSame(2, PdfText::pageCount($pdf));
        $this->assertCount(2, PdfText::pages($pdf));
        $this->assertSame(2, PatientCardPdfGenerator::PAGES);

        // DIN A4: 595.28 x 841.89 Punkt
        $this->assertSame(2, substr_count($pdf, '/MediaBox [0 0 595.28 841.89]'));
        $this->assertSame(595.28, PdfDocument::PAGE_WIDTH);
        $this->assertSame(841.89, PdfDocument::PAGE_HEIGHT);
    }

    public function testPageOneContainsPatientDeviceAndNoticeData(): void
    {
        $pdf = (new PatientCardPdfGenerator())->generate(PatientCardFactory::snapshot(), null, $this->generatedAt());
        $pages = PdfText::pages($pdf);
        $pageOne = $pages[0];

        foreach ([
            'Schrittmacher - Patientenausweis',
            '(Patient Identification Card)',
            // Abschnittsueberschriften der Vorlage idcard_ann.png
            'Patientendaten:',
            'Notfallkontakt:',
            'Hausarzt:',
            'Betreuendes Nachsorgezentrum:',
            'Implantate:',
            'Schrittmacher:',
            'Elektroden:',
            'Hinweise:',
            'Achtung Flugsicherheit:',
            'Attention Airline Security:',
            'Sonstiges',
            'Bemerkung',
            'Nächste Kontrolle in',
            // Tabellenkoepfe der Vorlage
            'Modell', 'Impl.Ort', 'Impl.Datum', 'Lokalisation',
            // Patient
            'LASTNAME, FIRSTNAME', '21.10.1938', 'Musterstraße', '12345', 'Beispielstadt', '10358141', 'Bradykardie',
            // Geraet und Elektroden
            'Endurity Core', '2152', '5809481', 'links pektoral', '18.06.2024',
            'EEM126412', 'EEL193668', '2088TC Tendril STS',
            // Stammdatentexte
            'Nachsorgezentrum Beispielstadt', 'Musterweg 5',
            'Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem',
            'Das Gerät kann Metalldetektoren auslösen',
            'The device may trigger metal detectors',
            '07.04.2027', 'Dr. med. Kontrolle', 'Dr. med. Hausarzt',
            'Angehörige Beispielperson',
            'Bericht Nr. 2 vom 07.10.2026',
            // Fusszeile
            'Automatisch erzeugter Patientenausweis', 'Erstellt am 07.10.2026 09:15:00',
            'Seite 1 von 2',
        ] as $expected) {
            $this->assertContains($expected, $pageOne);
        }
        // Die Vorlage zeigt weder das rote Achtung-Feld noch englische Abschnittstitel.
        foreach ([
            'Achtung / Attention',
            'Patientendaten / Patient data',
            'Gerät / Device',
            'Elektroden / Leads',
            'Hinweise / Notes',
            'Nachsorge / Follow-up',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $pageOne);
        }
        // Keine medizinische Bewertung
        foreach (['Empfehlung:', 'Diagnose: ', 'normal', 'kritisch'] as $forbidden) {
            $this->assertNotContains($forbidden, $pageOne);
        }
        $this->assertContains('Logo nicht hinterlegt', $pageOne);
    }

    public function testPageTwoContainsHistory(): void
    {
        $pdf = (new PatientCardPdfGenerator())->generate(PatientCardFactory::snapshot(), null, $this->generatedAt());
        $pageTwo = PdfText::pages($pdf)[1];

        foreach ([
            'Vergangene Nachsorgeuntersuchungen',
            'Previous follow-up examinations',
            '07.04.2026', 'Bericht Nr. 1',
            '15.01.2026', 'Bericht Nr. 3',
            'Nachsorgezentrum Beispielstadt',
            'Die Angaben stammen aus den importierten Nachsorgeberichten',
            'Seite 2 von 2',
        ] as $expected) {
            $this->assertContains($expected, $pageTwo);
        }
        $this->assertNotContains('Patientendaten:', $pageTwo);
    }

    public function testEmptyHistoryIsAnnounced(): void
    {
        $card = PatientCardFactory::snapshot(['history' => []]);
        $pdf = (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt());
        $this->assertSame(2, PdfText::pageCount($pdf));
        $this->assertContains('keine früheren Nachsorgeuntersuchungen', PdfText::pages($pdf)[1]);
    }

    public function testMissingOptionalDataIsMarked(): void
    {
        $card = PatientCardFactory::snapshot([
            'leads' => [],
            'settings' => ['notice_text' => '', 'flight_notice_de' => '', 'flight_notice_en' => '', 'center_name' => '', 'center_address' => ''],
            'patient' => ['street' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'indication' => ''],
            'device' => ['implant_location' => ''],
            'emergency_contact' => ['name' => '', 'phone' => ''],
            'physician' => ['name' => '', 'practice' => ''],
        ]);
        $pdf = (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt());
        // Zeilenumbrueche des Wrappings wieder zu Leerzeichen machen, damit der Satz zusammenhaengt.
        $text = str_replace("\n", ' ', PdfText::text($pdf));
        $this->assertSame(2, PdfText::pageCount($pdf));
        $this->assertContains('Für diesen Bericht sind keine Elektrodendaten hinterlegt.', $text);
        $this->assertContains('nicht angegeben', $text);
    }

    public function testFitsOnPageOneWithMaximalMasterData(): void
    {
        $card = PatientCardFactory::snapshot([
            'settings' => [
                'notice_text' => str_repeat('Hinweis zur sicheren Nutzung des implantierten Systems. ', 8),
                'flight_notice_de' => str_repeat('Flugsicherheitshinweis für Kontrollen. ', 7),
                'flight_notice_en' => str_repeat('Airline security notice for screening. ', 6),
                'center_name' => 'Zentrum für Kardiologie und Rhythmologie Beispielstadt-Nord',
                'center_address' => 'Sehr Lange Nachsorgestraße 123, 12345 Beispielstadt, Haus 4, Ebene 2, Ambulanz 7',
            ],
            'patient' => [
                'street' => 'Sehr lange Patientenstraße 123 a, Hinterhaus, Aufgang C, 2. Stock',
                'indication' => 'Bradykardie, Vorhofflimmern, Zustand nach Schrittmacherimplantation',
            ],
            'physician' => ['name' => 'Dr. med. Sehrlangerhausarztname', 'practice' => 'Gemeinschaftspraxis für Innere Medizin und Kardiologie am Marktplatz 12'],
            'emergency_contact' => ['name' => 'Sehrlangerangehoerigenname', 'phone' => '+49 30 123456789'],
            'follow_up' => ['control_physician' => 'Dr. med. Sehrlangerkontrollarztname'],
        ]);

        $pdf = (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt());
        $this->assertSame(2, PdfText::pageCount($pdf));
        $this->assertContains('Bradykardie, Vorhofflimmern', str_replace("\n", ' ', PdfText::text($pdf)));
    }

    public function testEmbedsLogoAsImage(): void
    {
        $logo = ImageData::fromBytes(Images::png(40, 20));
        $pdf = (new PatientCardPdfGenerator())->generate(PatientCardFactory::snapshot(), $logo, $this->generatedAt());

        $this->assertContains('/Subtype /Image', $pdf);
        $this->assertContains('/SMask', $pdf);
        $this->assertNotContains('Logo nicht hinterlegt', PdfText::text($pdf));
        $this->assertSame(2, PdfText::pageCount($pdf));
    }

    public function testIsDeterministic(): void
    {
        $generator = new PatientCardPdfGenerator();
        $card = PatientCardFactory::snapshot();
        $a = $generator->generate($card, null, $this->generatedAt());
        $b = $generator->generate($card, null, $this->generatedAt());
        $this->assertSame(hash('sha256', $a), hash('sha256', $b));
    }

    public function testRejectsUnsupportedCardVersion(): void
    {
        $card = PatientCardFactory::snapshot(['card_version' => 2]);
        $this->assertThrows(
            \RuntimeException::class,
            fn () => (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt()),
        );
    }

    public function testRejectsTooLongNoticeTexts(): void
    {
        $card = PatientCardFactory::snapshot([
            'settings' => ['notice_text' => str_repeat('Sehr langer Hinweistext. ', 200)],
        ]);
        $exception = $this->assertThrows(
            \RuntimeException::class,
            fn () => (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt()),
        );
        $this->assertContains('Seite 1', $exception->getMessage());
    }

    public function testFilenameUsesPatientAndReportDate(): void
    {
        $name = PatientCardPdfGenerator::filename(PatientCardFactory::snapshot());
        $this->assertSame('Patientenausweis_LASTNAME_FIRSTNAME_2026-10-07.pdf', $name);

        $name = PatientCardPdfGenerator::filename(PatientCardFactory::snapshot([
            'patient' => ['patient_name' => 'Müller-Lüdenscheidt, Jörg'],
            'follow_up' => ['report_date' => ''],
            'generated_at' => '2026-10-07 08:00:00',
        ]));
        $this->assertSame('Patientenausweis_M_ller-L_denscheidt_J_rg.pdf', $name);

        // Ohne Berichtsdatum wird der Erstellungszeitpunkt verwendet
        $name = PatientCardPdfGenerator::filename(PatientCardFactory::snapshot(['follow_up' => ['report_date' => null]]));
        $this->assertSame('Patientenausweis_LASTNAME_FIRSTNAME_2026-10-07.pdf', $name);
    }

    public function testEscapesCharactersOutsideWinAnsi(): void
    {
        $card = PatientCardFactory::snapshot([
            'patient' => ['patient_name' => 'TEST, Ω🫀'],
            'settings' => ['notice_text' => 'Prüfung ß € ± °C µV'],
        ]);
        $pdf = (new PatientCardPdfGenerator())->generate($card, null, $this->generatedAt());
        $text = PdfText::text($pdf);
        $this->assertContains('Prüfung ß € ± °C µV', $text);
        $this->assertContains('[U+03A9]', $text);
        $this->assertContains('[U+1FAC0]', $text);
    }
}
