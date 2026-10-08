<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterPdfGenerator;
use App\Report\Pdf\PdfDocument;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\LetterFactory;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Brief-PDF: Briefkopf, Patientendaten, Brieftext, optionaler Befundteil, mehrseitiger Anhang
 * mit wiederholter Kopfzeile und Fusszeile auf jeder Seite.
 *
 * Anders als der Patientenausweis ist der Brief bewusst nicht auf zwei Seiten begrenzt.
 */
final class LetterPdfTest extends TestCase
{
    private function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));
    }

    private function generate(array $overrides = []): string
    {
        return (new LetterPdfGenerator())->generate(LetterFactory::snapshot($overrides), null, $this->generatedAt());
    }

    /** Test 1: Der Brief ist ein gueltiges, deterministisches PDF in DIN A4 mit mehreren Seiten. */
    public function testProducesValidMultiPageA4Pdf(): void
    {
        $pdf = $this->generate();

        $this->assertTrue(str_starts_with($pdf, '%PDF-1.4'));
        $this->assertTrue(str_ends_with($pdf, "%%EOF\n"));
        $this->assertSame(LetterPdfGenerator::pageCount($pdf), PdfText::pageCount($pdf));
        $this->assertTrue(PdfText::pageCount($pdf) > 1, 'Der Brief mit Anhang braucht mehr als eine Seite.');
        $this->assertCount(PdfText::pageCount($pdf), PdfText::pages($pdf));
        $this->assertSame(PdfText::pageCount($pdf), substr_count($pdf, '/MediaBox [0 0 595.28 841.89]'));
        $this->assertSame(595.28, PdfDocument::PAGE_WIDTH);
        $this->assertSame(841.89, PdfDocument::PAGE_HEIGHT);
        $this->assertSame($pdf, $this->generate(), 'Der Brief muss reproduzierbar sein.');
    }

    /** Test 2: Briefkopf, Patientendaten und Dokumentangaben stehen auf Seite 1. */
    public function testPageOneContainsHeaderAndPatientData(): void
    {
        $pageOne = PdfText::pages($this->generate())[0];

        foreach ([
            'Nachsorgezentrum Beispielstadt',
            'Musterweg 5',
            'Brief zur Schrittmacher-/ICD-Abfrage',
            'BRIEF-0001-2026-0001',
            '07.10.2026',
            'Stammdatenfassung 1',
            'Patientendaten',
            'LASTNAME, FIRSTNAME',
            '21.10.1938',
            '10358141',
            'Musterstraße 12',
            '12345 Beispielstadt',
            'Telefon: 01234/56789',
            'Sehr geehrte Damen und Herren,',
        ] as $needle) {
            $this->assertContains($needle, $pageOne);
        }
    }

    /** Test 3: Die Brieftextteile erscheinen in der Reihenfolge des Konzepts (§6). */
    public function testBodySectionsAppearInConceptOrder(): void
    {
        $text = PdfText::text($this->generate());

        $positions = [];
        foreach ([
            'Anamnese',
            'Sick-Sinus-Syndrom',
            'Vormedikation',
            'Metoprolol',
            'Befund: Schrittmacher-/ICD-Abfrage',
            'SN-123456',
            'Epikrise',
            'Kontrollierte Abfrage im Rahmen der Nachsorge',
            'Mit freundlichen Grüßen',
        ] as $needle) {
            $position = strpos($text, $needle);
            $this->assertTrue($position !== false, sprintf('"%s" fehlt im Brief.', $needle));
            $positions[] = (int) $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Die Reihenfolge der Briefteile muss dem Konzept entsprechen.');
    }

    /** Test 4: Fassungsstand und Autor jedes Bausteins werden ausgewiesen. */
    public function testBodySectionsShowFrozenVersions(): void
    {
        $text = PdfText::text($this->generate());

        $this->assertContains('Fassung 3 · erfasst von Dr. med. Beispiel', $text);
        $this->assertContains('Fassung 2 · erfasst von Dr. med. Beispiel', $text);
        $this->assertContains('Stand 03.10.2026 11:15', $text);
    }

    /** Test 5: Der Befundteil entfaellt vollstaendig, wenn kein Bericht zugeordnet ist. */
    public function testReportSectionIsOmittedWithoutReport(): void
    {
        $text = PdfText::text($this->generate(['report' => null]));

        $this->assertNotContains('Befund: Schrittmacher-/ICD-Abfrage', $text);
        $this->assertNotContains('SN-123456', $text);
        // Die uebrigen Teile bleiben erhalten.
        $this->assertContains('Anamnese', $text);
        $this->assertContains('Epikrise', $text);
        $this->assertContains('Anhang: Schrittmacher-/ICD-Abfrage (vollständige Tabelle)', $text);
    }

    /** Test 6: Fehlende Bausteine werden als "nicht angegeben" ausgewiesen, nicht erfunden. */
    public function testMissingBlocksAreMarkedAsNotProvided(): void
    {
        $text = PdfText::text($this->generate([
            'anamnesis' => ['present' => false, 'version' => null, 'author_name' => '', 'text' => '', 'entries' => []],
            'premedication' => ['present' => false, 'version' => null, 'author_name' => '', 'text' => '', 'entries' => []],
            'epicrisis' => ['present' => false, 'version' => null, 'author_name' => '', 'text' => '', 'entries' => []],
        ]));

        $this->assertSame(3, substr_count($text, 'nicht angegeben'));
        $this->assertNotContains('Fassung 3', $text);
    }

    /** Test 7: Der Anhang enthaelt die vollstaendige Abfragetabelle auf eigenen Seiten. */
    public function testAppendixContainsCompleteTableOnOwnPages(): void
    {
        $pdf = $this->generate();
        $pages = PdfText::pages($pdf);

        // Seite 1 gehoert dem Brieftext, der Anhang beginnt auf einer neuen Seite.
        $this->assertNotContains('Anhang: Schrittmacher-/ICD-Abfrage', $pages[0]);

        $appendixText = implode("\n", array_slice($pages, 1));
        foreach ([
            'Anhang: Schrittmacher-/ICD-Abfrage (vollständige Tabelle)',
            'Geräteart: Zweikammer-Schrittmacher',
            'angegebene Werte: 31',
            'MRT-Tauglichkeit (aus Patientenausweis Nr. 1)',
            'Parameter',
            'Wert',
            // Abschnitte des Wunschkatalogs
            'Gerät',
            'Batterie',
            'Messdaten',
            'Programmierung – Bradykardie',
            'Vorhofsonde (RA)',
            'Ventrikelsonde (RV)',
            'AV-Zeiten',
            // Ausgewaehlte Werte
            'ERI – Austausch empfohlen',
            'Magnetfrequenz',
            '98/min',
            'Betriebsart',
            'DDD',
            'Refraktärzeit (PVARP)',
            '250 ms',
            'ModeSwitch Betriebsfrequenz',
        ] as $needle) {
            if ($needle === 'ModeSwitch Betriebsfrequenz') {
                // Der Wert steht in der Tabelle; die Beschriftung lautet "ModeSwitch Frequenz".
                $this->assertContains('ModeSwitch Frequenz', $appendixText);
                continue;
            }
            $this->assertContains($needle, $appendixText);
        }

        // Bemerkungen stehen am Ende des Anhangs.
        $this->assertContains('Bemerkungen', $appendixText);
        $this->assertContains('Sondenmessung ohne Auffälligkeit.', $appendixText);
    }

    /** Test 8: Ohne Abfrage entfaellt der Anhang, der Brief bleibt aber gueltig. */
    public function testAppendixIsOmittedWithoutDeviceCheck(): void
    {
        $pdf = $this->generate(['appendix' => [
            'present' => false,
            'device_type' => '',
            'device_type_label' => '',
            'sections' => [],
            'notes' => '',
            'filled' => 0,
            'mrt_label' => '',
        ]]);

        $text = PdfText::text($pdf);
        $this->assertNotContains('Anhang: Schrittmacher-/ICD-Abfrage', $text);
        $this->assertNotContains('Programmierung – Bradykardie', $text);
        $this->assertNotContains('Vorhofsonde (RA)', $text);
        $this->assertSame(1, PdfText::pageCount($pdf));
    }

    /** Test 9: Lange Abfragen laufen mehrseitig und wiederholen die Kopfzeile. */
    public function testAppendixRepeatsHeaderOnEveryPage(): void
    {
        $rows = [];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ['label' => sprintf('Sondenmessung %d – Reizschwelle', $i), 'value' => sprintf('%d,5 V bei 0,4 ms', $i)];
        }
        $pdf = $this->generate(['appendix' => LetterFactory::appendix([
            'sections' => [['key' => 'measurements', 'label' => 'Messdaten', 'rows' => $rows]],
        ])]);
        $pages = PdfText::pages($pdf);

        $this->assertTrue(PdfText::pageCount($pdf) > 3, 'Der lange Anhang braucht mehr als drei Seiten.');
        $this->assertSame(PdfText::pageCount($pdf), LetterPdfGenerator::pageCount($pdf));
        foreach (array_slice($pages, 1) as $index => $page) {
            $this->assertContains('Parameter', $page, sprintf('Kopfzeile fehlt auf Anhangseite %d.', $index + 1));
            $this->assertContains('Wert', $page);
        }
        $this->assertContains('Messdaten', $pages[1]);
    }

    /** Test 10: Jede Seite traegt die Fusszeile mit Dokumentnummer, Zeitpunkt und Seitenzahl. */
    public function testEveryPageHasFooter(): void
    {
        $pages = PdfText::pages($this->generate());
        $total = count($pages);

        foreach ($pages as $index => $page) {
            $this->assertContains('keine medizinische Bewertung oder Diagnose.', $page);
            $this->assertContains('Erstellt am 07.10.2026 09:15:00', $page);
            $this->assertContains('Dokument BRIEF-0001-2026-0001', $page);
            $this->assertContains('Brief-Fassung 1', $page);
            $this->assertContains(sprintf('Seite %d von %d', $index + 1, $total), $page);
        }
    }

    /** Test 11: Eine unbekannte Brief-Fassung wird abgelehnt. */
    public function testRejectsUnsupportedLetterVersion(): void
    {
        $e = $this->assertThrows(RuntimeException::class, function (): void {
            (new LetterPdfGenerator())->generate(LetterFactory::snapshot(['letter_version' => 9]), null, $this->generatedAt());
        });
        $this->assertContains('Brief-Fassung 9', $e->getMessage());
    }

    /** Test 12: Der Dateiname ist ASCII, stabil und ohne Sonderzeichen. */
    public function testFilenameIsSafeAndStable(): void
    {
        $snapshot = LetterFactory::snapshot();
        $filename = LetterPdfGenerator::filename($snapshot);

        $this->assertSame('Brief_Schrittmacher-ICD-Abfrage_LASTNAME_FIRSTNAME_2026-10-07.pdf', $filename);
        $this->assertSame($filename, LetterPdfGenerator::filename($snapshot));
        $this->assertSame('Brief_Schrittmacher-ICD-Abfrage_Patient.pdf', LetterPdfGenerator::filename(['patient' => [], 'document' => []]));
    }
}
