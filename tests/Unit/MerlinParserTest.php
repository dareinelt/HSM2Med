<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\MerlinParser;
use App\Import\ParsedRecord;
use App\Import\ParseResult;
use Tests\Support\Fixtures;
use Tests\TestCase;

final class MerlinParserTest extends TestCase
{
    private MerlinParser $parser;

    public function setUp(): void
    {
        $this->parser = new MerlinParser();
    }

    private function byId(ParseResult $result, string $id): ParsedRecord
    {
        foreach ($result->records as $record) {
            if ($record->parameterId === $id) {
                return $record;
            }
        }
        $this->fail("Parameter {$id} nicht gefunden.");
    }

    /** @return list<string> */
    private function codes(ParseResult $result): array
    {
        return array_map(static fn ($i): string => $i->code, $result->issues);
    }

    // 1. normales Datensatzformat
    public function testNormalRecordFormat(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $this->assertCount(count(Fixtures::specRecords()), $result->records);
        $this->assertSame([], $result->issues);
        $this->assertSame('UTF-8', $result->encoding);

        $first = $result->records[0];
        $this->assertSame('306', $first->parameterId);
        $this->assertSame('RV Pulse Amplitude', $first->name);
        $this->assertSame('2.5', $first->value);
        $this->assertSame('V', $first->unit);
        $this->assertSame(1, $first->position);
        $this->assertSame("306\x1CRV Pulse Amplitude\x1C2.5\x1CV\x1C", $first->rawRecord);

        // Reihenfolge bleibt erhalten
        foreach (Fixtures::specRecords() as $index => $expected) {
            $this->assertSame($expected[0], $result->records[$index]->parameterId);
            $this->assertSame($index + 1, $result->records[$index]->position);
        }
    }

    // 2. ASCII 0x1C ist das einzige massgebliche Trennzeichen
    public function testAsciiFileSeparatorIsTheOnlyDelimiter(): void
    {
        // Ohne Zeilenumbrueche zwischen Datensaetzen; Tabs, Semikolons, Pipes und Kommas bleiben Teil der Werte
        $text = "1\x1CName;A|B,C\x1Cx\ty\x1Cu\x1C2\x1CZweiter\x1C5\x1Cms\x1C";
        $result = $this->parser->parse($text);
        $this->assertCount(2, $result->records);
        $this->assertSame('Name;A|B,C', $result->records[0]->name);
        $this->assertSame("x\ty", $result->records[0]->value);
        $this->assertSame('2', $result->records[1]->parameterId);

        $noSeparator = $this->parser->parse("306\tRV Pulse Amplitude\t2.5\tV\n");
        $this->assertSame([], $noSeparator->records);
        $this->assertSame(['no_separator'], $this->codes($noSeparator));
    }

    // 3. leere Werte
    public function testEmptyValuesAreKeptAsEmptyString(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $record = $this->byId($result, '2432');
        $this->assertSame('Follow-up Physician', $record->name);
        $this->assertSame('', $record->value);
        $this->assertSame('', $record->unit);
    }

    // 4. leere Einheiten
    public function testEmptyUnits(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $record = $this->byId($result, '2680');
        $this->assertSame('0.0', $record->value);
        $this->assertSame('', $record->unit);
        $this->assertSame('VVI', $this->byId($result, '301')->value);
        $this->assertSame('', $this->byId($result, '301')->unit);
    }

    // 5. Dezimalwerte – unveraendert als Text, keine Rundung
    public function testDecimalValuesAreNotRoundedOrConverted(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $this->assertSame('2.97792', $this->byId($result, '519')->value);
        $this->assertSame('100.0', $this->byId($result, '501')->value);
        $this->assertSame('462.5', $this->byId($result, '507')->value);
        $this->assertSame('0.4', $this->byId($result, '305')->value);
        $this->assertSame('82.0', $this->byId($result, '2709')->value);
    }

    // 6. Prozentwerte
    public function testPercentValues(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $this->assertSame(['40', '%'], [$this->byId($result, '9018')->value, $this->byId($result, '9018')->unit]);
        $this->assertSame(['6.4', '%'], [$this->byId($result, '2708')->value, $this->byId($result, '2708')->unit]);
        $this->assertSame(['67.0', '%'], [$this->byId($result, '2681')->value, $this->byId($result, '2681')->unit]);
    }

    // 7. Datumswerte – Originaltext bleibt erhalten
    public function testDateValuesKeepOriginalText(): void
    {
        $result = $this->parser->parse(Fixtures::specFile());
        $this->assertSame('10/07/2026 07:03:24', $this->byId($result, '105')->value);
        $this->assertSame('10/21/1938 00:00:00', $this->byId($result, '2431')->value);
        $this->assertSame('06/18/2024 00:00:00', $this->byId($result, '2459')->value);

        $date = \App\Support\MerlinDate::parse('10/07/2026 07:03:24');
        $this->assertSame('2026-10-07 07:03:24', $date?->format('Y-m-d H:i:s'));
        $this->assertSame('21.10.1938', \App\Support\MerlinDate::display('10/21/1938 00:00:00', true));
        $this->assertNull(\App\Support\MerlinDate::parse('13/45/2026'));
        $this->assertSame('unbekannt', \App\Support\MerlinDate::display('unbekannt', false));
    }

    // 8. Sonderzeichen
    public function testSpecialCharacters(): void
    {
        $records = [
            ['9001', 'Prüfung Ä Ö Ü ß € µ Ω', 'Wert <b>&"\'</b>', 'µV'],
            ['9002', 'Emoji 🫀 / 中文', '±5 °C', 'Ω'],
            ['9003', 'RV. Capture (Pulse Amp)', 'a/b\\c', '%'],
        ];
        $result = $this->parser->parse(Fixtures::build($records));
        $this->assertSame([], $result->issues);
        $this->assertSame('Prüfung Ä Ö Ü ß € µ Ω', $result->records[0]->name);
        $this->assertSame('Wert <b>&"\'</b>', $result->records[0]->value);
        $this->assertSame('µV', $result->records[0]->unit);
        $this->assertSame('Emoji 🫀 / 中文', $result->records[1]->name);
        $this->assertSame('a/b\\c', $result->records[2]->value);

        // Windows-1252 kodierte Datei wird kontrolliert konvertiert
        $cp1252 = mb_convert_encoding(Fixtures::build([['9001', 'Prüfung', '5 µV', '€']]), 'Windows-1252', 'UTF-8');
        $fallback = $this->parser->parse($cp1252);
        $this->assertSame('Windows-1252', $fallback->encoding);
        $this->assertSame('Prüfung', $fallback->records[0]->name);
        $this->assertSame('€', $fallback->records[0]->unit);
        $this->assertSame(['encoding_fallback'], $this->codes($fallback));

        // UTF-8 mit BOM und UTF-16LE
        $bom = $this->parser->parse("\xEF\xBB\xBF" . Fixtures::build([['1', 'Ä', '1', '']]));
        $this->assertSame('UTF-8 (BOM)', $bom->encoding);
        $this->assertSame('1', $bom->records[0]->parameterId);
        $utf16 = $this->parser->parse("\xFF\xFE" . mb_convert_encoding(Fixtures::build([['1', 'Ä', '1', '']]), 'UTF-16LE', 'UTF-8'));
        $this->assertSame('UTF-16LE', $utf16->encoding);
        $this->assertSame('Ä', $utf16->records[0]->name);
    }

    // 9. unbekannte Parameter werden nicht verworfen
    public function testUnknownParametersAreKept(): void
    {
        $records = [...Fixtures::specRecords(), ['987654', 'Completely Unknown Parameter', 'xyz', 'units']];
        $result = $this->parser->parse(Fixtures::build($records));
        $this->assertCount(count($records), $result->records);
        $unknown = $this->byId($result, '987654');
        $this->assertSame('Completely Unknown Parameter', $unknown->name);
        $this->assertSame('xyz', $unknown->value);
    }

    // 10. fehlerhafte Datensaetze werden protokolliert, Import laeuft weiter
    public function testInvalidRecordsAreLoggedAndSkipped(): void
    {
        $text = "306\x1CRV Pulse Amplitude\x1C2.5\x1CV\x1C\n"
            . "ABC\x1CUngueltige ID\x1C1\x1Cms\x1C\n"
            . "777\x1CNur zwei Felder\x1C\n"
            . "305\x1CRV Pulse Width\x1C0.4\x1Cms\x1C\n"
            . "Rest ohne Trennzeichen";
        $result = $this->parser->parse($text);

        $this->assertSame(['306', '305'], array_map(static fn ($r) => $r->parameterId, $result->records));
        $this->assertSame(['invalid_parameter_id', 'incomplete_record', 'unterminated_record'], $this->codes($result));
        $this->assertSame(5, $result->recordCount);
        $this->assertSame(2, $result->validRecordCount());
        $this->assertSame(3, $result->invalidRecordCount());
        $this->assertSame(2, $result->issues[0]->position);
        $this->assertSame("ABC\x1CUngueltige ID\x1C1\x1Cms\x1C", $result->issues[0]->rawRecord);
        $this->assertSame("777\x1CNur zwei Felder\x1C", $result->issues[1]->rawRecord);
        $this->assertSame(4, $result->records[1]->position);
        // Positionen bleiben trotz Fehlern eindeutig
        $positions = array_merge(array_map(static fn ($r) => $r->position, $result->records), array_map(static fn ($i) => $i->position, $result->issues));
        sort($positions);
        $this->assertSame([1, 2, 3, 4, 5], $positions);

        $empty = $this->parser->parse('');
        $this->assertSame(['empty_file'], $this->codes($empty));

        $noTerminator = $this->parser->parse("306\x1CRV Pulse Amplitude\x1C2.5\x1CV");
        $this->assertCount(1, $noTerminator->records);
        $this->assertSame('V', $noTerminator->records[0]->unit);
        $this->assertSame(['missing_final_separator'], $this->codes($noTerminator));
    }

    // 11. sehr lange Parameterbezeichnungen
    public function testVeryLongParameterNames(): void
    {
        $longName = str_repeat('Sehr lange Parameterbezeichnung mit Umlauten äöü ', 400);
        $longValue = str_repeat('1234567890', 2000);
        $result = $this->parser->parse(Fixtures::build([['42', $longName, $longValue, 'ms']]));
        $this->assertSame([], $result->issues);
        $this->assertSame($longName, $result->records[0]->name);
        $this->assertSame($longValue, $result->records[0]->value);

        $tooLongId = $this->parser->parse(Fixtures::build([[str_repeat('9', 33), 'x', '1', '']]));
        $this->assertSame(['invalid_parameter_id'], $this->codes($tooLongId));
    }

    // 12. unterschiedliche Zeilenumbrueche
    public function testDifferentLineBreaks(): void
    {
        $expected = $this->parser->parse(Fixtures::specFile("\n"));
        foreach (["\r\n", "\r", '', "\n\n", "\r\n\r\n"] as $eol) {
            $result = $this->parser->parse(Fixtures::specFile($eol));
            $this->assertSame([], $result->issues, 'Zeilenumbruch ' . bin2hex($eol));
            $this->assertCount(count($expected->records), $result->records);
            foreach ($expected->records as $i => $record) {
                $this->assertSame($record->parameterId, $result->records[$i]->parameterId);
                $this->assertSame($record->name, $result->records[$i]->name);
                $this->assertSame($record->value, $result->records[$i]->value);
                $this->assertSame($record->unit, $result->records[$i]->unit);
                $this->assertSame($record->rawRecord, $result->records[$i]->rawRecord);
            }
        }

        // Zeilenumbruch innerhalb eines Feldes wird uebernommen und als Warnung gemeldet
        $inField = $this->parser->parse("1\x1CName\x1CZeile1\nZeile2\x1Cms\x1C");
        $this->assertSame("Zeile1\nZeile2", $inField->records[0]->value);
        $this->assertSame(['line_break_in_field'], $this->codes($inField));
    }

    public function testReferenceSampleFile(): void
    {
        $bytes = Fixtures::sampleFile();
        $result = $this->parser->parse($bytes);
        $this->assertSame(113, $result->recordCount);
        $this->assertCount(113, $result->records);
        $this->assertSame([], $result->issues);
        $this->assertSame(452, substr_count($bytes, "\x1C"));
        $ids = array_map(static fn ($r) => $r->parameterId, $result->records);
        $this->assertCount(113, array_unique($ids));
        foreach (Fixtures::specRecords() as [$id, $name, $value, $unit]) {
            $record = $this->byId($result, $id);
            $this->assertSame([$name, $value, $unit], [$record->name, $record->value, $record->unit], "Parameter {$id}");
        }
    }
}
