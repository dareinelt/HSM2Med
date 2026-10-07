<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Report\Pdf\PdfDocument;
use App\Report\PdfGenerator;
use DateTimeImmutable;
use Tests\Support\Fixtures;
use Tests\Support\PdfText;
use Tests\Support\ReportDataFactory;
use Tests\TestCase;

final class PdfGeneratorTest extends TestCase
{
    private function generatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));
    }

    // 19. PDF-Erzeugung
    public function testPdfGeneration(): void
    {
        $data = ReportDataFactory::fromText(Fixtures::sampleFile(), 7, 'MERLIN__ANN_5809481.log');
        $pdf = (new PdfGenerator())->generate($data, false, $this->generatedAt());

        $this->assertTrue(str_starts_with($pdf, '%PDF-1.4'));
        $this->assertTrue(str_ends_with($pdf, "%%EOF\n"));
        $this->assertContains('/Type /Catalog', $pdf);

        $text = PdfText::text($pdf);
        foreach ([
            'HERZSCHRITTMACHER – AUSLESEBERICHT',
            'Automatisch erzeugter Datenbericht',
            'Keine originale Abbott-/Merlin-Dokumentation',
            'LASTNAME, FIRSTNAME', '10358141', '21.10.1938',
            'Endurity Core', '2152', '5809481',
            'nicht in den Quelldaten enthalten', // Geraetehersteller fehlt in der Datei
            'EEL193668', 'EEM126412', '2088TC Tendril STS',
            'RV Pulse Amplitude', '2.97792', 'Unloaded Battery Voltage',
            'MERLIN__ANN_5809481.log', 'Bericht-Nr.', 'Seite 1 von',
            'keine medizinische Bewertung', 'Erstellt am 07.10.2026 09:15:00',
        ] as $expected) {
            $this->assertContains($expected, $text);
        }
        // Keine medizinische Interpretation
        foreach (['normal', 'auffällig', 'kritisch', 'Empfehlung:'] as $forbidden) {
            $this->assertNotContains($forbidden, $text);
        }
        // Alle Parameter-IDs im Bericht
        foreach ($data->parameters as $p) {
            $this->assertContains((string) $p['parameter_id'], $text);
        }
        // Leere Werte werden kenntlich gemacht
        $this->assertContains('(leer)', $text);
    }

    // 20. mehrseitige PDF mit Seitenzahlen und Kopfzeile
    public function testMultiPagePdf(): void
    {
        $data = ReportDataFactory::fromText(Fixtures::sampleFile());
        $pdf = (new PdfGenerator())->generate($data, true, $this->generatedAt());
        $pages = PdfText::pages($pdf);
        $count = PdfText::pageCount($pdf);
        $this->assertTrue($count >= 3, "Erwartet mindestens 3 Seiten, erhalten {$count}");
        $this->assertCount($count, $pages);
        foreach ($pages as $index => $page) {
            $this->assertContains(sprintf('Seite %d von %d', $index + 1, $count), $page);
            $this->assertContains('keine medizinische Bewertung', $page);
            if ($index > 0) {
                $this->assertContains('Bericht Nr. 1', $page);
                $this->assertContains('Patient: LASTNAME, FIRSTNAME', $page);
            }
        }
        // Rohdatenanhang
        $text = PdfText::text($pdf);
        $this->assertContains('Anhang: Originaldaten / Importdaten', $text);
        $this->assertContains('306 | RV Pulse Amplitude | 2.5 | V', $text);
        $this->assertContains('2432 | Follow-up Physician |  | ', $text);
    }

    // 21. Sonderzeichen
    public function testSpecialCharacters(): void
    {
        $records = [
            ['202', 'Device Serial Number', 'SN-ÄÖÜ', ''],
            ['9001', 'Prüfung (Klammern) \\ Backslash ß €', 'Wert ±5 °C µV', 'Ω'],
            ['9002', 'Emoji 🫀', '中文', ''],
            ['9003', 'Steuerzeichen', "A\tB", ''],
        ];
        $data = ReportDataFactory::fromText(Fixtures::build($records));
        $pdf = (new PdfGenerator())->generate($data, true, $this->generatedAt());
        $text = PdfText::text($pdf);
        $this->assertContains('SN-ÄÖÜ', $text);
        $this->assertContains('Prüfung (Klammern) \\ Backslash ß €', $text);
        $this->assertContains('Wert ±5 °C µV', $text);
        // Zeichen ausserhalb von Windows-1252 bleiben als Codepoint sichtbar (kein Informationsverlust)
        $this->assertContains('[U+03A9]', $text);
        $this->assertContains('[U+1FAC0]', $text);
        $this->assertContains('[U+4E2D][U+6587]', $text);
        $this->assertContains('A[0x09]B', $text);
        $this->assertSame('[U+03A9]', PdfDocument::sanitize('Ω'));
        $this->assertSame('äöü', PdfDocument::sanitize('äöü'));
    }

    // 22. lange Tabellen: Seitenumbrueche mit wiederholtem Tabellenkopf, keine Datenverluste
    public function testLongTables(): void
    {
        $records = [['202', 'Device Serial Number', '123', '']];
        for ($i = 1; $i <= 600; $i++) {
            $records[] = [(string) (50000 + $i), 'Unbekannter Parameter Nr. ' . $i, sprintf('%d.%d', $i, $i % 10), 'ms'];
        }
        $records[] = ['60001', str_repeat('Extrem lange Bezeichnung ', 40), str_repeat('LangerWertOhneLeerzeichen', 30), 'V'];
        $data = ReportDataFactory::fromText(Fixtures::build($records));
        $pdf = (new PdfGenerator())->generate($data, false, $this->generatedAt());
        $pages = PdfText::pages($pdf);
        $this->assertTrue(count($pages) >= 10, 'Erwartet viele Seiten, erhalten ' . count($pages));

        $text = implode("\n", $pages);
        for ($i = 1; $i <= 600; $i++) {
            $this->assertContains('Unbekannter Parameter Nr. ' . $i . "\n", $text . "\n");
        }
        // Tabellenkopf auf Folgeseiten wiederholt
        $withHeader = array_filter($pages, static fn (string $p): bool => str_contains($p, "ID\nParameter\nWert\nEinheit"));
        $this->assertTrue(count($withHeader) >= count($pages) - 2, 'Tabellenkopf fehlt auf Folgeseiten');
        // Ueberlanger Wert wird umbrochen, nicht abgeschnitten
        $joined = str_replace("\n", '', $text);
        $this->assertContains(str_repeat('LangerWertOhneLeerzeichen', 30), $joined);
    }

    // 23. unbekannte Parameter erscheinen in "Sonstige / Nicht kategorisiert"
    public function testUnknownParameters(): void
    {
        $records = [...Fixtures::specRecords(), ['987654', 'Completely Unknown Parameter', 'xyz', 'units']];
        $data = ReportDataFactory::fromText(Fixtures::build($records));
        $other = array_values(array_filter($data->categories(), static fn (array $c): bool => $c['key'] === 'other'));
        $this->assertCount(1, $other);
        $this->assertSame('987654', $other[0]['parameters'][0]['parameter_id']);

        $text = PdfText::text((new PdfGenerator())->generate($data, false, $this->generatedAt()));
        $this->assertContains('Sonstige / Nicht kategorisiert', $text);
        $this->assertContains('Completely Unknown Parameter', $text);
        $this->assertContains('xyz', $text);
    }

    public function testDeterministicOutput(): void
    {
        $data = ReportDataFactory::fromText(Fixtures::sampleFile());
        $generator = new PdfGenerator();
        $a = $generator->generate($data, true, $this->generatedAt());
        $b = $generator->generate($data, true, $this->generatedAt());
        $this->assertSame(md5($a), md5($b), 'Gleicher Snapshot + gleicher Zeitpunkt muss identische PDF ergeben');

        $later = $generator->generate($data, true, $this->generatedAt()->modify('+1 day'));
        $strip = static fn (string $t): string => (string) preg_replace('/Erstellt am [0-9.: ]+/', '', $t);
        $this->assertSame($strip(PdfText::text($a)), $strip(PdfText::text($later)));
    }

    public function testRejectsUnsupportedReportVersion(): void
    {
        $data = ReportDataFactory::fromText(Fixtures::specFile());
        $report = $data->report;
        $report['report_version'] = 99;
        $other = new \App\Report\ReportData($report, $data->import, $data->summary, $data->parameters, $data->issues);
        $this->assertThrows(\RuntimeException::class, fn () => (new PdfGenerator())->generate($other, false, $this->generatedAt()));
    }
}
