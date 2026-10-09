<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\BiotronikParser;
use App\Import\ImportIssue;
use App\Import\ImportValidator;
use App\Import\MerlinParser;
use App\Import\ParsedRecord;
use App\Import\ParseResult;
use App\Import\ParserChain;
use App\Import\ParserInterface;
use App\Mapping\CategoryAssignment;
use App\Report\ReportSummaryBuilder;
use App\Security\UploadException;
use App\Security\UploadValidator;
use App\Support\IeeeDate;
use App\Support\SourceDate;
use Tests\Support\Fixtures;
use Tests\Support\ReportDataFactory;
use Tests\TestCase;

/**
 * Verhalten des Biotronik-Parsers (IEEE 11073-10103, Creator "BioICSConverter").
 *
 * Grundsatz: Werte, Einheiten und Bezeichnungen werden unveraendert uebernommen
 * (keine Rundung, Normalisierung oder Umrechnung), leere Werte bleiben "".
 */
final class BiotronikParserTest extends TestCase
{
    private BiotronikParser $parser;

    public function setUp(): void
    {
        $this->parser = new BiotronikParser();
    }

    private function document(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<biotronik-ieee11073-export creator="BioICSConverter" format-version="4.10">' . "\n"
            . '<dataset>' . $body . '</dataset>' . "\n"
            . '</biotronik-ieee11073-export>';
    }

    private function parseDocument(string $body): ParseResult
    {
        return $this->parser->parse($this->document($body));
    }

    private static function errorCount(ParseResult $result, string $code): int
    {
        return count(array_filter($result->issues, static fn (ImportIssue $i): bool => $i->isError() && $i->code === $code));
    }

    private static function warningCount(ParseResult $result, string $code): int
    {
        return count(array_filter($result->issues, static fn (ImportIssue $i): bool => !$i->isError() && $i->code === $code));
    }

    /**
     * @param list<ParsedRecord> $records
     */
    private static function byId(array $records, string $id): ParsedRecord
    {
        foreach ($records as $record) {
            if ($record->parameterId === $id) {
                return $record;
            }
        }
        throw new \RuntimeException("Datensatz {$id} nicht gefunden.");
    }

    /**
     * @return list<string>
     */
    private static function codes(ParseResult $result): array
    {
        return array_map(static fn (ParsedRecord $r): string => $r->parameterId, $result->records);
    }

    // ---------------------------------------------------------------- 1. Beispielsdatei

    public function testReferenceSampleFileIsParsedCompletely(): void
    {
        $result = $this->parser->parse(Fixtures::biotronikFile());

        $this->assertSame(61, $result->recordCount, 'Die Beispieldatei enthaelt 61 Wertknoten.');
        $this->assertSame(61, $result->validRecordCount(), 'Kein Wertknoten darf verworfen werden.');
        $this->assertSame('UTF-8 (BOM)', $result->encoding);
        $this->assertCount(1, $result->issues, 'Nur die fehlenden code-Attribute werden vermerkt.');
        $this->assertSame('missing_value_code', $result->issues[0]->code);
        $this->assertFalse($result->issues[0]->isError(), 'Fehlende Codes sind kein Fehler.');
        $this->assertSame(1, self::warningCount($result, 'missing_value_code'), 'Die fehlenden Codes werden zusammengefasst.');
        $this->assertContains('12 Wertknoten', $result->issues[0]->message);

        $positions = array_map(static fn (ParsedRecord $r): int => $r->position, $result->records);
        $this->assertSame(range(1, 61), $positions, 'Positionen bleiben die Reihenfolge der Datei.');
        $this->assertSame(range(1, 61), array_values(array_unique($positions)), 'Positionen sind eindeutig.');
    }

    public function testEveryRecordCarriesTheSectionPath(): void
    {
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $sections = [];
        foreach ($result->records as $record) {
            $sections[$record->section] = true;
            $this->assertTrue($record->section !== '', "Datensatz {$record->parameterId} ohne Abschnittspfad.");
        }
        $this->assertCount(19, $sections, 'Abschnittspfade entsprechen den section-Knoten der Datei.');
        $this->assertSame('MDC/IDC/DEV', self::byId($result->records, '720898')->section);
        $this->assertSame('MDC/IDC/SET/BRADY', self::byId($result->records, '730880')->section);
        $this->assertSame('MDC/ATTR/PT', self::byId($result->records, 'MDC.ATTR.PT.DOB')->section);
    }

    // ---------------------------------------------------------------- 2. Stammdaten

    public function testDeviceValuesAreTakenVerbatim(): void
    {
        $records = $this->parser->parse(Fixtures::biotronikFile())->records;

        $model = self::byId($records, '720898');
        $this->assertSame(['MODEL', 'Enticos 4 SR', ''], [$model->name, $model->value, $model->unit]);
        $this->assertSame('1000118587', self::byId($records, '720899')->value);
        $this->assertSame('BIO', self::byId($records, '720900')->value);
        $this->assertSame('20231211T000000', self::byId($records, '720901')->value);
        $this->assertSame('Klinikum WF', self::byId($records, '720904')->value);
    }

    public function testLeadAndSessionValuesAreTakenVerbatim(): void
    {
        $records = $this->parser->parse(Fixtures::biotronikFile())->records;

        $this->assertSame('Solia S 60', self::byId($records, '720961')->value);
        $this->assertSame('8001149599', self::byId($records, '720962')->value);
        $this->assertSame('BIO', self::byId($records, '720963')->value);
        $this->assertSame('BI', self::byId($records, '720965')->value);
        $this->assertSame('RV', self::byId($records, '720966')->value);
        $this->assertSame('20261008T123829+0200', self::byId($records, '721025')->value);
        $this->assertSame('20251202T000000', self::byId($records, '721028')->value);
    }

    public function testPatientValuesWithoutCodeAreKept(): void
    {
        $records = $this->parser->parse(Fixtures::biotronikFile())->records;

        $this->assertSame('LASTNAME1', self::byId($records, 'MDC.ATTR.PT.NAME.FAMILY')->value);
        $this->assertSame('FIRSTNAME1', self::byId($records, 'MDC.ATTR.PT.NAME.GIVEN')->value);
        $this->assertSame('2', self::byId($records, 'MDC.ATTR.PT.SEX')->value);
        $this->assertSame('19280425', self::byId($records, 'MDC.ATTR.PT.DOB')->value);
    }

    // ---------------------------------------------------------------- 3. Einheiten

    public function testUnitsAreTakenVerbatim(): void
    {
        $records = $this->parser->parse(Fixtures::biotronikFile())->records;

        $this->assertSame('40', self::byId($records, '721536')->value);
        $this->assertSame('%', self::byId($records, '721536')->unit);
        $this->assertSame(['60', '{beats}/min'], [self::byId($records, '730880')->value, self::byId($records, '730880')->unit]);
        $this->assertSame(['585', 'Ohm'], [self::byId($records, '722433')->value, self::byId($records, '722433')->unit]);
        $this->assertSame(['4.7', 'V'], [self::byId($records, '722177')->value, self::byId($records, '722177')->unit]);
        $this->assertSame(['5.7', 'mV'], [self::byId($records, '722054')->value, self::byId($records, '722054')->unit]);
        $this->assertSame(['0.4', 'ms'], [self::byId($records, '722241')->value, self::byId($records, '722241')->unit]);
        $this->assertSame('', self::byId($records, '730752')->unit, 'Ohne unit-Attribut bleibt die Einheit leer.');
    }

    // ---------------------------------------------------------------- 4. Keine Umrechnung

    public function testValuesAreNeitherRoundedNorNormalised(): void
    {
        $body = '<section name="MDC"><section name="IDC"><section name="MSMT">'
            . '<value code="1" name="A" type="Numeric" unit="mV">6.0</value>'
            . '<value code="2" name="B" type="Numeric" unit="mV">0.40</value>'
            . '<value code="3" name="C" type="Numeric" unit="V">+5.0</value>'
            . '<value code="4" name="D" type="Numeric" unit="Ohm">585.00</value>'
            . '<value code="5" name="E" type="Numeric" unit="&amp;#37;"> 40 </value>'
            . '</section></section></section>';
        $result = $this->parseDocument($body);

        $this->assertSame(['6.0', '0.40', '+5.0', '585.00', '40'], array_map(static fn (ParsedRecord $r): string => $r->value, $result->records));
        $this->assertSame(['mV', 'mV', 'V', 'Ohm', '&#37;'], array_map(static fn (ParsedRecord $r): string => $r->unit, $result->records));
    }

    public function testEmptyValuesStayEmptyAndAreNeverZero(): void
    {
        $body = '<section name="MDC">'
            . '<value code="1" name="Leer" type="String"></value>'
            . '<value code="2" name="Selbstschliessend" type="String"/>'
            . '<value code="3" name="NurLeerzeichen" type="String">   </value>'
            . '<value code="4" name="OhneEinheit" type="Numeric">0</value>'
            . '</section>';
        $result = $this->parseDocument($body);

        $this->assertSame(['', '', '', '0'], array_map(static fn (ParsedRecord $r): string => $r->value, $result->records));
        foreach ($result->records as $record) {
            $this->assertSame('', $record->unit);
            $this->assertTrue(is_string($record->value), 'Werte bleiben Zeichenketten.');
        }
        $this->assertSame(4, $result->validRecordCount());
    }

    // ---------------------------------------------------------------- 5. Sonderzeichen / Grenzfaelle

    public function testSpecialCharactersAndEntitiesAreKept(): void
    {
        $body = '<section name="MDC"><section name="IDC">'
            . '<value code="1" name="Prüfung ÄÖÜ äöü ß" type="String" unit="µV">Wert &lt;b&gt;&amp;&quot;&apos;&lt;/b&gt;</value>'
            . '<value code="2" name="Emoji 🫀 中文" type="String">±5 °C – 2 Ω</value>'
            . '<value code="3" name="NeueZeile" type="String">Zeile1&#10;Zeile2</value>'
            . '</section></section>';
        $result = $this->parseDocument($body);

        $this->assertSame('Prüfung ÄÖÜ äöü ß', $result->records[0]->name);
        $this->assertSame('µV', $result->records[0]->unit);
        $this->assertSame('Wert <b>&"\'</b>', $result->records[0]->value, 'XML-Entities werden aufgeloest.');
        $this->assertSame('Emoji 🫀 中文', $result->records[1]->name);
        $this->assertSame('±5 °C – 2 Ω', $result->records[1]->value);
        $this->assertSame("Zeile1\nZeile2", $result->records[2]->value);
    }

    public function testLongPathsGetShortStableSyntheticIds(): void
    {
        $long = str_repeat('SEHR_LANGER_ABSCHNITT_', 12);
        $body = '<section name="' . $long . '"><value name="' . $long . 'WERT" type="String">x</value></section>';

        $first = $this->parseDocument($body);
        $second = $this->parseDocument($body);

        $this->assertCount(1, $first->records);
        $id = $first->records[0]->parameterId;
        $this->assertTrue(strlen($id) <= BiotronikParser::MAX_PARAMETER_ID_LENGTH, "ID zu lang: {$id}");
        $this->assertContains('~', $id, 'Ueberlange Pfade erhalten eine Pruefsumme.');
        $this->assertSame($id, $second->records[0]->parameterId, 'Die Ersatz-ID ist reproduzierbar.');
        $this->assertSame($long . 'WERT', $first->records[0]->name, 'Die Bezeichnung wird nicht gekuerzt.');
    }

    public function testValueWithoutNameIsReportedAndSkipped(): void
    {
        $body = '<section name="MDC">'
            . '<value code="1" name="Gut" type="String">ok</value>'
            . '<value code="2" type="String">ohneName</value>'
            . '<value name="OhneCode" type="String">ersatzId</value>'
            . '</section>';
        $result = $this->parseDocument($body);

        $this->assertSame(3, $result->recordCount, 'Positionen zaehlen auch verworfene Knoten.');
        $this->assertSame(2, $result->validRecordCount());
        $this->assertSame(['1', 'MDC.OHNECODE'], self::codes($result));
        $this->assertSame(1, self::errorCount($result, 'missing_value_name'));
        $this->assertSame(1, self::warningCount($result, 'missing_value_code'));
        $this->assertContains('ohneName', (string) $result->errors()[0]->rawRecord);
    }

    public function testNulByteMakesTheDocumentInvalid(): void
    {
        // NUL ist in XML nicht zulaessig; die Datei wird abgewiesen statt still uebernommen.
        $result = $this->parseDocument('<section name="MDC"><value code="1" name="NUL" type="String">a' . "\0" . 'b</value></section>');

        $this->assertSame(0, $result->recordCount);
        $this->assertSame(1, self::errorCount($result, 'invalid_xml'));
    }

    // ---------------------------------------------------------------- 6. Duplikate & Signatur

    public function testDuplicateNamesAreDistinguishedByCode(): void
    {
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $byName = [];
        foreach ($result->records as $record) {
            $byName[$record->name][] = [$record->parameterId, $record->section];
        }

        $this->assertCount(3, $byName['SERIAL'], 'SERIAL kommt in drei Abschnitten vor.');
        $this->assertSame(
            ['720899', '720962', 'BIO.REQUEST.PROGRAMMER.SERIAL'],
            array_column($byName['SERIAL'], 0),
            'Namensgleiche Felder sind nur ueber code bzw. Abschnittspfad unterscheidbar.',
        );
        $this->assertCount(2, $byName['MODEL']);
        $this->assertCount(2, $byName['DTM']);
        $this->assertCount(3, $byName['TYPE'], 'TYPE kommt in DEV, SESS und STAT/EPISODE vor.');
        $this->assertCount(5, $byName['POLARITY']);
    }

    public function testEnvelopedSignatureIsIgnored(): void
    {
        $body = '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<SignedInfo><SignatureMethod Algorithm="x"/></SignedInfo>'
            . '<SignatureValue>QUJD</SignatureValue>'
            . '</Signature>'
            . '<section name="MDC"><value code="1" name="A" type="String">ok</value></section>';
        $result = $this->parseDocument($body);

        $this->assertSame(['1'], self::codes($result));
        $this->assertSame('MDC', $result->records[0]->section);
    }

    // ---------------------------------------------------------------- 7. Fehlerfaelle

    public function testEmptyFileIsReported(): void
    {
        $result = $this->parser->parse('');
        $this->assertSame(0, $result->recordCount);
        $this->assertSame(1, self::errorCount($result, 'empty_file'));
    }

    public function testInvalidXmlIsReported(): void
    {
        $result = $this->parser->parse('<biotronik-ieee11073-export><dataset>');
        $this->assertSame(0, $result->recordCount);
        $this->assertSame(1, self::errorCount($result, 'invalid_xml'));
        $this->assertTrue($result->errors()[0]->message !== '');
    }

    public function testUnexpectedRootElementIsReported(): void
    {
        $result = $this->parser->parse('<?xml version="1.0"?><merlin-export><dataset/></merlin-export>');
        $this->assertSame(1, self::errorCount($result, 'unexpected_root'));
        $this->assertContains('merlin-export', $result->errors()[0]->message);
    }

    public function testDocumentWithoutValuesIsReported(): void
    {
        $result = $this->parseDocument('<section name="MDC"><section name="IDC"/></section>');
        $this->assertSame(0, $result->recordCount);
        $this->assertSame(1, self::errorCount($result, 'no_values'));
    }

    public function testMalformedValueNodeDoesNotAbortTheImport(): void
    {
        $body = '<section name="MDC">'
            . '<value code="1" name="A" type="String">ok</value>'
            . '<value code="2" name="B" type="String">a &amp; b</value>'
            . '</section>';
        $result = $this->parseDocument($body);

        $this->assertSame(2, $result->validRecordCount());
        $this->assertSame('a & b', $result->records[1]->value);
    }

    // ---------------------------------------------------------------- 8. Datumswerte

    public function testIeeeDatesAreParsedWithoutConversion(): void
    {
        $withOffset = IeeeDate::parse('20261008T123829+0200');
        $this->assertSame('2026-10-08 12:38:29', $withOffset?->format('Y-m-d H:i:s'), 'Die Ortszeit bleibt erhalten.');
        $this->assertSame('+02:00', $withOffset?->format('P'));

        $this->assertSame('2023-12-11 00:00:00', IeeeDate::parse('20231211T000000')?->format('Y-m-d H:i:s'));
        $this->assertSame('1928-04-25 00:00:00', IeeeDate::parse('19280425')?->format('Y-m-d H:i:s'));

        $this->assertNull(IeeeDate::parse('13/45/2024'), 'Merlin-Format gehoert nicht zum IEEE-Format.');
        $this->assertNull(IeeeDate::parse('20261345'), 'Ueberlaeufe werden abgewiesen.');
        $this->assertNull(IeeeDate::parse('2026-10-08'));
        $this->assertNull(IeeeDate::parse(''));
        $this->assertNull(IeeeDate::parse(null));
    }

    public function testIeeeDateDisplayFollowsDateOnlyRule(): void
    {
        $this->assertSame('25.04.1928', IeeeDate::display('19280425', false));
        $this->assertSame('11.12.2023', IeeeDate::display('20231211T000000', true), 'Reine Datumsangabe ohne Uhrzeit.');
        $this->assertSame('11.12.2023 00:00:00', IeeeDate::display('20231211T000000', false));
        $this->assertSame('08.10.2026 12:38:29', IeeeDate::display('20261008T123829+0200', false));
        $this->assertSame('unbekannt', IeeeDate::display('unbekannt', false), 'Unbekanntes bleibt unveraendert.');
        $this->assertSame('', IeeeDate::display('', false));
    }

    public function testSourceDateHandlesBothSourceFormats(): void
    {
        $this->assertSame('21.10.1938', SourceDate::display('10/21/1938 00:00:00', true));
        $this->assertSame('25.04.1928', SourceDate::display('19280425', true));
        $this->assertSame('08.10.2026 12:38:29', SourceDate::display('20261008T123829+0200', false));
        $this->assertSame('08.10.2026 12:38:29', SourceDate::parse('20261008T123829+0200')?->format('d.m.Y H:i:s'));
        $this->assertSame('18.06.2024 00:00:00', SourceDate::display('06/18/2024 00:00:00', false));
    }

    // ---------------------------------------------------------------- 9. Erkennung

    public function testSupportsDetectsBiotronikFiles(): void
    {
        $this->assertTrue($this->parser->supports(Fixtures::biotronikFile(), Fixtures::biotronikUploadName()));
        $this->assertTrue($this->parser->supports(Fixtures::biotronikFile(), 'BIOTRONIC_ANN.xml'), 'Inhalt genuegt.');
        $this->assertTrue($this->parser->supports('<biotronik-ieee11073-export/>', 'namenlos'), 'Wurzelelement genuegt.');
        $this->assertTrue($this->parser->supports('irgendwas', 'BIOIEEE_1.xml'), 'Praefix genuegt.');

        $this->assertFalse($this->parser->supports(Fixtures::sampleFile(), 'merlin.log'));
        $this->assertTrue($this->parser->supports('', 'BIOIEEE_1.xml'), 'Das Namenspraefix der Exportdateien genuegt.');
        $this->assertFalse($this->parser->supports('<root/>', 'andere.xml'));
    }

    public function testParserChainPicksTheRightParser(): void
    {
        $chain = ParserChain::default();

        $merlin = $chain->forBytes(Fixtures::sampleFile(), 'MERLIN__ANN_5809481.log');
        $this->assertSame(MerlinParser::NAME, $merlin->name());
        $this->assertSame(MerlinParser::VERSION, $merlin->version());

        $biotronik = $chain->forBytes(Fixtures::biotronikFile(), Fixtures::biotronikUploadName());
        $this->assertSame(BiotronikParser::NAME, $biotronik->name());
        $this->assertSame(BiotronikParser::VERSION, $biotronik->version());

        $this->assertSame(BiotronikParser::NAME, $chain->forBytes(Fixtures::biotronikFile(), 'BIOIEEE_ANN.xml')->name());
        $this->assertTrue($chain->supports(Fixtures::biotronikFile(), Fixtures::biotronikUploadName()));
        $this->assertSame(2, count($chain->descriptions()), 'Beide Parser sind bekannt.');
    }

    public function testMerlinBehaviourIsUnchanged(): void
    {
        $parser = new MerlinParser();
        $this->assertTrue($parser instanceof ParserInterface);
        $this->assertTrue($parser->supports(Fixtures::sampleFile()));
        $this->assertFalse($parser->supports(Fixtures::biotronikFile()));

        $result = $parser->parse(Fixtures::sampleFile());
        $this->assertSame(113, $result->recordCount);
        $this->assertSame(0, self::errorCount($result, 'empty_file'));
        foreach ($result->records as $record) {
            $this->assertSame('', $record->section, 'Merlin-Datensaetze haben keinen Abschnittspfad.');
        }
    }

    // ---------------------------------------------------------------- 10. Upload-Pruefung

    public function testUploadValidatorAcceptsBiotronikXml(): void
    {
        $validator = new UploadValidator(1024 * 1024);
        $bytes = Fixtures::biotronikFile();

        $validator->validateContent($bytes, Fixtures::biotronikUploadName());
        $validator->validateContent($bytes, 'BIOTRONIC_ANN.xml');
        $this->assertTrue(in_array('xml', UploadValidator::ALLOWED_EXTENSIONS, true));

        $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateContent('<?xml version="1.0"?><merlin-export/>', 'fremd.xml'),
            'Fremde Wurzelelemente werden abgewiesen.',
        );
        $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateContent('kein xml', 'kaputt.xml'),
        );
        $this->assertThrows(
            UploadException::class,
            fn () => $validator->validateContent($bytes, 'export.csv'),
            'Die Endung bleibt beschraenkt.',
        );
    }

    // ---------------------------------------------------------------- 11. Berichtskopf

    public function testSummaryUsesOnlySourceData(): void
    {
        $mapping = ReportDataFactory::mapping();
        $builder = new ReportSummaryBuilder($mapping);
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $summary = $builder->build($result->records);

        $this->assertSame('LASTNAME1, FIRSTNAME1', $summary->value('patient_name'), 'Nachname und Vorname werden zusammengefuehrt.');
        $this->assertNull($summary->value('patient_identifier'), 'Die Datei enthaelt keine Patienten-ID.');
        $this->assertSame('19280425', $summary->value('patient_dob'), 'Der Originalwert bleibt erhalten.');
        $this->assertSame('Enticos 4 SR', $summary->value('device_model_name'));
        $this->assertSame('1000118587', $summary->value('device_serial'));
        $this->assertSame('BIO', $summary->value('device_manufacturer'));
        $this->assertSame('VVI', $summary->value('mode'));
        $this->assertSame('60', $summary->value('base_rate'));
        $this->assertNull($summary->value('device_model_number'), 'Modellnummer wird nicht ergaenzt.');

        $this->assertCount(1, $summary->leads, 'Nur die eine Sonde der Datei.');
        $lead = $summary->leads[0];
        $this->assertSame('rv', $lead['chamber']);
        $this->assertSame('BIO', $lead['manufacturer']);
        $this->assertSame('Solia S 60', $lead['model_number']);
        $this->assertSame('8001149599', $lead['serial_number']);
        $this->assertSame('20231211T000000', $lead['implant_date'], 'Das Implantationsdatum bleibt im Quellformat.');
    }

    public function testSnapshotRowsAndValidation(): void
    {
        $mapping = ReportDataFactory::mapping();
        $builder = new ReportSummaryBuilder($mapping);
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $summary = $builder->build($result->records);
        $snapshot = $builder->toSnapshot($summary);

        $rows = [];
        foreach ($snapshot['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row;
            }
        }
        $this->assertSame('25.04.1928', $rows['Geburtsdatum']['value']);
        $this->assertSame('19280425', $rows['Geburtsdatum']['original']);
        $this->assertSame('08.10.2026 12:38:29', $rows['Sitzungszeitpunkt']['value']);
        $this->assertSame('20261008T123829+0200', $rows['Sitzungszeitpunkt']['original']);
        $this->assertSame('11.12.2023', $rows['Implantation']['value']);
        $this->assertSame('20231211T000000', $rows['Implantation']['original']);
        $this->assertSame('02.12.2025 00:00:00', $rows['Letzte Abfrage']['value']);
        $this->assertSame('60', $rows['Grundfrequenz']['value']);
        $this->assertSame('{beats}/min', $rows['Grundfrequenz']['unit']);
        $this->assertSame('BIO', $rows['Hersteller']['value']);
        $this->assertSame('1000118587', $rows['Seriennummer']['value']);
        $this->assertSame('LASTNAME1, FIRSTNAME1', $rows['Name']['value']);

        $this->assertCount(1, $snapshot['leads']);
        $this->assertSame('8001149599', $snapshot['leads'][0]['serial_number']);
        $this->assertSame('11.12.2023', $snapshot['leads'][0]['implant_date_display']);
        $this->assertSame('20231211T000000', $snapshot['leads'][0]['implant_date']);

        $this->assertTrue((new ImportValidator())->validate($result, $summary)->isValid(), 'Der Beispieldatensatz ist importierbar.');
    }

    public function testTwoLeadSectionsBecomeTwoLeads(): void
    {
        $lead = static fn (string $model, string $serial, string $location): string =>
            '<section name="LEAD">'
            . '<value code="720961" name="MODEL" type="String">' . $model . '</value>'
            . '<value code="720962" name="SERIAL" type="String">' . $serial . '</value>'
            . '<value code="720963" name="MFG" type="String">BIO</value>'
            . '<value code="720964" name="IMPLANT_DT" type="DateTime">20231211T000000</value>'
            . '<value code="720966" name="LOCATION" type="String">' . $location . '</value>'
            . '</section>';
        $result = $this->parseDocument(
            '<section name="MDC"><section name="IDC">'
            . $lead('Solia S 60', '8001149599', 'RV')
            . $lead('Solia S 45', '8001149598', 'RA')
            . '</section></section>'
        );

        $this->assertSame(['MDC/IDC/LEAD#1', 'MDC/IDC/LEAD#2'], [
            $result->records[0]->section,
            $result->records[5]->section,
        ], 'Gleichnamige Abschnitte werden nummeriert.');

        $summary = (new ReportSummaryBuilder(ReportDataFactory::mapping()))->build($result->records);
        $this->assertCount(2, $summary->leads);
        $this->assertSame(['atrial', 'rv'], array_column($summary->leads, 'chamber'), 'Sortierung: Atrium vor Ventrikel.');
        $this->assertSame(['8001149598', '8001149599'], array_column($summary->leads, 'serial_number'));
    }

    // ---------------------------------------------------------------- 12. Parameterzuordnung

    public function testCodedParametersAreCategorizedById(): void
    {
        $mapping = ReportDataFactory::mapping();
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $coded = 0;
        foreach ($result->records as $record) {
            if (preg_match('/^\d{6}$/D', $record->parameterId) !== 1) {
                continue;
            }
            $coded++;
            $assignment = $mapping->resolve($record->parameterId, $record->name);
            $this->assertSame(CategoryAssignment::SOURCE_ID, $assignment->source, "Parameter {$record->parameterId}");
            $this->assertNotSame('other', $assignment->key, "Parameter {$record->parameterId}");
        }
        $this->assertSame(49, $coded, 'Die Datei enthaelt 49 Werte mit code.');
    }

    public function testUncodedParametersFallBackToTheName(): void
    {
        $mapping = ReportDataFactory::mapping();
        $result = $this->parser->parse(Fixtures::biotronikFile());
        $sources = [];
        foreach ($result->records as $record) {
            if (preg_match('/^\d{6}$/D', $record->parameterId) === 1) {
                continue;
            }
            $source = $mapping->resolve($record->parameterId, $record->name)->source;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
        }
        // NAME_GIVEN, NAME_FAMILY, SEX, DOB, CONTAINS_PII_DATA, SW_VERSION_AT_REQUEST/FOLLOWUP, FOLLOWUP_ID
        $this->assertSame(['name' => 8, 'none' => 4], $sources, 'Nicht zuordenbare Felder bleiben "Sonstige".');

        $this->assertSame('patient', $mapping->resolve('MDC.ATTR.PT.DOB', 'DOB')->key);
        $this->assertSame('lead', $mapping->resolve('720966', 'LOCATION')->key);
        $this->assertSame('pacing', $mapping->resolve('730880', 'LOWRATE')->key);
        $this->assertSame('battery', $mapping->resolve('721536', 'REMAINING_PERCENTAGE')->key);
    }
}
