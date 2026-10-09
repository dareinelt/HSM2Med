<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\BiotronikParser;
use App\Import\DuplicateImportException;
use App\Import\ImportOutcome;
use App\Import\ImportService;
use App\Report\ReportService;
use DateTimeImmutable;
use Tests\Support\Fixtures;
use Tests\Support\PdfText;

/**
 * Vollstaendiger Importweg eines Biotronik-XML-Exports (IEEE 11073-10103) bis in die Datenbank.
 */
final class BiotronikImportDatabaseTest extends DatabaseTestCase
{
    private function importSample(?ImportService $service = null, ?string $bytes = null, string $filename = 'BIOIEEE_ANN.xml'): ImportOutcome
    {
        $service ??= $this->importService();
        $bytes ??= Fixtures::biotronikFile();
        return $service->import($service->analyze($bytes, $filename), $bytes);
    }

    public function testSuccessfulImport(): void
    {
        $outcome = $this->importSample();

        $this->assertSame('completed_with_warnings', $outcome->status, 'Nur die fehlenden code-Attribute werden vermerkt.');
        $this->assertSame(61, $outcome->parameterCount);

        $this->assertSame(1, $this->rowCount('imports'));
        $this->assertSame(1, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(1, $this->rowCount('devices'));
        $this->assertSame(1, $this->rowCount('leads'));
        $this->assertSame(1, $this->rowCount('report_leads'));
        $this->assertSame(61, $this->rowCount('report_parameters'));
        $this->assertSame(61, $this->rowCount('parameter_definitions'));
        $this->assertSame(1, $this->rowCount('import_errors'));

        $import = $this->pdo->query('SELECT * FROM imports')->fetch();
        $this->assertSame('BIOIEEE_ANN.xml', $import['filename']);
        $this->assertSame(hash('sha256', Fixtures::biotronikFile()), $import['file_hash']);
        $this->assertSame(strlen(Fixtures::biotronikFile()), (int) $import['file_size']);
        $this->assertSame('UTF-8 (BOM)', $import['encoding']);
        $this->assertSame('1.0.0', $import['parser_version'], 'Die Parser-Version des erkannten Parsers.');
        $this->assertSame(61, (int) $import['record_count']);
        $this->assertSame(61, (int) $import['valid_record_count']);
        $this->assertSame(1, (int) $import['warning_count']);
        $this->assertSame(0, (int) $import['error_count']);
        $this->assertSame('2026-10-07 08:00:00', $import['imported_at']);
        $this->assertTrue(is_file($this->archiveDir . '/' . $import['archive_filename']), 'Originaldatei archiviert');
        $this->assertSame(Fixtures::biotronikFile(), file_get_contents($this->archiveDir . '/' . $import['archive_filename']));

        $issue = $this->pdo->query('SELECT * FROM import_errors')->fetch();
        $this->assertSame(['warning', 'missing_value_code'], [$issue['severity'], $issue['error_code']]);
        $this->assertNull($issue['record_position'], 'Die Warnung gilt fuer die ganze Datei.');
    }

    public function testReportSnapshotContainsSourceData(): void
    {
        $outcome = $this->importSample();
        $report = $this->pdo->query('SELECT * FROM reports')->fetch();

        $this->assertSame('LASTNAME1, FIRSTNAME1', $report['patient_name_snapshot']);
        $this->assertNull($report['patient_identifier_snapshot'], 'Die Datei enthaelt keine Patienten-ID.');
        $this->assertSame('19280425', $report['patient_dob_snapshot'], 'Der Originalwert bleibt erhalten.');
        $this->assertSame('BIO', $report['device_manufacturer_snapshot']);
        $this->assertSame('Enticos 4 SR', $report['device_model_name_snapshot']);
        $this->assertNull($report['device_model_number_snapshot'], 'Die Datei enthaelt keine Modellnummer.');
        $this->assertSame('1000118587', $report['device_serial_snapshot']);
        $this->assertSame('2026-10-08 12:38:29', $report['session_timestamp'], 'Ortszeit ohne Umrechnung.');
        $this->assertSame('20261008T123829+0200', $report['session_timestamp_raw']);
        $this->assertSame('2025-12-02 00:00:00', $report['interrogation_timestamp']);
        $this->assertSame('20251202T000000', $report['interrogation_timestamp_raw']);
        $this->assertSame(1, (int) $report['report_version']);
        $this->assertSame('1.0.0', $report['parser_version']);
        $this->assertSame('1.1.0', $report['mapping_version']);
        $this->assertSame(61, (int) $report['parameter_count']);
        $this->assertSame($outcome->reportId, (int) $report['id']);

        $snapshot = json_decode($report['summary_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $snapshot['leads']);
        $this->assertSame('8001149599', $snapshot['leads'][0]['serial_number']);
        $this->assertSame('11.12.2023', $snapshot['leads'][0]['implant_date_display']);
    }

    public function testPatientIdentityFollowsNameAndDateOfBirth(): void
    {
        $this->importSample();
        $patient = $this->pdo->query('SELECT * FROM patients')->fetch();

        $this->assertNull($patient['patient_identifier']);
        $this->assertSame('LASTNAME1, FIRSTNAME1', $patient['patient_name']);
        $this->assertSame('LASTNAME1', $patient['last_name']);
        $this->assertSame('FIRSTNAME1', $patient['first_name']);
        $this->assertSame('1928-04-25', $patient['date_of_birth']);
        $this->assertSame('19280425', $patient['date_of_birth_raw']);
        $this->assertSame('lastname1|firstname1|1928-04-25', $patient['identity_key'], 'Zuordnung ueber Nachname, Vorname und Geburtsdatum.');
    }

    public function testDeviceAndLeadAreStoredUnchanged(): void
    {
        $this->importSample();

        $device = $this->pdo->query('SELECT * FROM devices')->fetch();
        $this->assertSame('1000118587', $device['serial_number']);
        $this->assertSame('Enticos 4 SR', $device['model_name']);
        $this->assertSame('BIO', $device['manufacturer']);
        $this->assertNull($device['model_number']);
        $this->assertSame('2023-12-11', $device['implant_date']);
        $this->assertSame('20231211T000000', $device['implant_date_raw']);

        $lead = $this->pdo->query('SELECT * FROM leads')->fetch();
        $this->assertSame((int) $device['id'], (int) $lead['device_id']);
        $this->assertSame('rv', $lead['chamber']);
        $this->assertSame('RV', $lead['chamber_source']);
        $this->assertSame('BIO', $lead['manufacturer']);
        $this->assertSame('Solia S 60', $lead['model_number']);
        $this->assertSame('8001149599', $lead['serial_number']);
        $this->assertSame('2023-12-11', $lead['implant_date']);
        $this->assertSame('20231211T000000', $lead['implant_date_raw']);
        $this->assertNull($lead['lead_type'], 'POLARITY_TYPE wird nicht als Sondentyp uebernommen.');
    }

    public function testAllParametersAreStoredVerbatim(): void
    {
        $parsed = (new BiotronikParser())->parse(Fixtures::biotronikFile());
        $outcome = $this->importSample();

        $stored = $this->pdo->query(
            'SELECT parameter_id, parameter_name, value, unit, category, mapping_source, original_position, raw_record
             FROM report_parameters WHERE report_id = ' . $outcome->reportId . ' ORDER BY original_position'
        )->fetchAll();
        $this->assertCount(61, $stored);

        foreach ($parsed->records as $i => $record) {
            $row = $stored[$i];
            $this->assertSame($record->parameterId, $row['parameter_id']);
            $this->assertSame($record->name, $row['parameter_name']);
            $this->assertSame($record->value, $row['value'], "Wert von {$record->parameterId}");
            $this->assertSame($record->unit, $row['unit'], "Einheit von {$record->parameterId}");
            $this->assertSame($record->position, (int) $row['original_position']);
            $this->assertSame($record->rawRecord, $row['raw_record']);
        }

        $byId = array_column($stored, null, 'parameter_id');
        $this->assertSame('Enticos 4 SR', $byId['720898']['value']);
        $this->assertSame(['40', '%'], [$byId['721536']['value'], $byId['721536']['unit']]);
        $this->assertSame(['60', '{beats}/min'], [$byId['730880']['value'], $byId['730880']['unit']]);
        $this->assertSame(['585', 'Ohm'], [$byId['722433']['value'], $byId['722433']['unit']]);
        $this->assertSame('20261008T123829+0200', $byId['721025']['value'], 'Datumswerte bleiben im Quellformat.');
        $this->assertSame(['lead', 'id'], [$byId['720966']['category'], $byId['720966']['mapping_source']]);
        $this->assertSame(['patient', 'name'], [$byId['MDC.ATTR.PT.DOB']['category'], $byId['MDC.ATTR.PT.DOB']['mapping_source']]);
        $this->assertSame('19280425', $byId['MDC.ATTR.PT.DOB']['value']);
        $this->assertSame('FIRSTNAME1', $byId['MDC.ATTR.PT.NAME.GIVEN']['value']);
        $this->assertSame(['other', 'none'], [$byId['BIO.REQUEST.DATE']['category'], $byId['BIO.REQUEST.DATE']['mapping_source']]);
        $this->assertSame('', $byId['720966']['unit'], 'Fehlende Einheit bleibt ein leerer String.');

        $definition = $this->pdo->query("SELECT * FROM parameter_definitions WHERE source_parameter_id = '730880'")->fetch();
        $this->assertSame('pacing', $definition['category_key']);
        $this->assertSame('1.1.0', $definition['mapping_version']);
        $this->assertNull($definition['standard_system'], 'Es werden keine Standardcodes erfunden.');
    }

    public function testReportReloadMatchesImport(): void
    {
        $outcome = $this->importSample();
        $data = $this->reportService()->load($outcome->reportId);

        $this->assertTrue($data !== null);
        $this->assertCount(61, $data->parameters);
        $this->assertSame('LASTNAME1, FIRSTNAME1', $data->report['patient_name_snapshot']);
        $this->assertSame('BIOIEEE_ANN.xml', $data->import['filename']);
        $this->assertCount(1, $data->leads());
        $this->assertSame('rv', $data->leads()[0]['chamber']);
        $this->assertSame(61, array_sum(array_map(static fn (array $c): int => count($c['parameters']), $data->categories())));
    }

    public function testPdfIsGeneratedFromTheDatabase(): void
    {
        $outcome = $this->importSample();
        foreach (glob($this->archiveDir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
            unlink($file);
        }

        $service = $this->reportService();
        $data = $service->load($outcome->reportId);
        $pdf = $service->renderPdf($data, true, new DateTimeImmutable('2026-10-08 09:00:00'));

        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        $text = PdfText::text($pdf);
        $this->assertContains('LASTNAME1, FIRSTNAME1', $text);
        $this->assertContains('25.04.1928', $text);
        $this->assertContains('Enticos 4 SR', $text);
        $this->assertContains('1000118587', $text);
        $this->assertContains('BIO', $text);
        $this->assertContains('8001149599', $text);
        $this->assertContains('Bericht Nr. ' . $outcome->reportId, $text);
        foreach ($data->parameters as $parameter) {
            $this->assertContains($parameter['parameter_id'], $text);
        }
        $this->assertSame('Bericht_' . $outcome->reportId . '_2026-10-08_SN1000118587.pdf', ReportService::pdfFilename($data));
    }

    public function testRepeatedImportIsDetectedAndCanBeForced(): void
    {
        $this->importSample();
        $service = $this->importService();
        $analysis = $service->analyze(Fixtures::biotronikFile(), 'BIOIEEE_ANN_kopie.xml');

        $this->assertCount(1, $service->findImportsByHash($analysis->fileHash));
        $this->assertThrows(DuplicateImportException::class, fn () => $service->import($analysis, Fixtures::biotronikFile()));
        $this->assertSame(1, $this->rowCount('reports'));

        $second = $service->import($analysis, Fixtures::biotronikFile(), true);
        $this->assertSame(2, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patients'), 'Gleicher Patient wird wiederverwendet.');
        $this->assertSame(1, $this->rowCount('devices'), 'Gleiches Geraet wird wiederverwendet.');
        $this->assertSame(1, $this->rowCount('leads'), 'Gleiche Sonde wird wiederverwendet.');
        $this->assertSame(2, $this->rowCount('report_leads'));
        $this->assertSame(61, $this->rowCount('parameter_definitions'), 'Parameterdefinitionen werden nicht dupliziert.');
        $this->assertSame(2, $second->reportId);
    }
}
