<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\BiotronikParser;
use App\Import\DuplicateImportException;
use App\Import\ImportOutcome;
use App\Import\ImportService;
use App\Import\PatientResolutionException;
use App\Import\PatientResolutionMode;
use App\Letter\LetterMeasurements;
use App\Patient\PatientInput;
use App\Patient\PatientRepository;
use App\Patient\PatientService;
use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardRepository;
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
        $this->assertSame('19400515', $report['patient_dob_snapshot'], 'Der Originalwert bleibt erhalten.');
        $this->assertSame('BIO', $report['device_manufacturer_snapshot']);
        $this->assertSame('Enticos 4 SR', $report['device_model_name_snapshot']);
        $this->assertNull($report['device_model_number_snapshot'], 'Die Datei enthaelt keine Modellnummer.');
        $this->assertSame('9000000101', $report['device_serial_snapshot']);
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
        $this->assertSame('8000000202', $snapshot['leads'][0]['serial_number']);
        $this->assertSame('15.03.2024', $snapshot['leads'][0]['implant_date_display']);
    }

    public function testPatientIdentityFollowsNameAndDateOfBirth(): void
    {
        $this->importSample();
        $patient = $this->pdo->query('SELECT * FROM patients')->fetch();

        $this->assertNull($patient['patient_identifier']);
        $this->assertSame('LASTNAME1, FIRSTNAME1', $patient['patient_name']);
        $this->assertSame('LASTNAME1', $patient['last_name']);
        $this->assertSame('FIRSTNAME1', $patient['first_name']);
        $this->assertSame('1940-05-15', $patient['date_of_birth']);
        $this->assertSame('19400515', $patient['date_of_birth_raw']);
        $this->assertSame('lastname1|firstname1|1940-05-15', $patient['identity_key'], 'Zuordnung ueber Nachname, Vorname und Geburtsdatum.');
    }

    /** Ein vor dem Import angelegter Patient wird ueber die Identitaet wiederverwendet. */
    public function testImportReusesPatientCreatedBeforeImport(): void
    {
        $patientId = $this->createPatient('LASTNAME1', 'FIRSTNAME1', '15.05.1940');
        $outcome = $this->importSample();

        $this->assertSame('completed_with_warnings', $outcome->status, 'Warnungen verhindern die Zuordnung nicht.');
        $this->assertSame(1, $this->rowCount('patients'), 'Kein doppelter Patient durch abweichendes Datums-Rohformat.');
        $report = $this->pdo->query('SELECT * FROM reports')->fetch();
        $this->assertSame($patientId, (int) $report['patient_id']);

        $reports = (new PatientRepository($this->pdo))->reportsByPatient($patientId);
        $this->assertCount(1, $reports, 'Der Bericht steht fuer Brief und Ausweis bereit.');
        $this->assertSame($outcome->reportId, (int) $reports[0]['id']);
    }

    /** H2: Bei mehreren Patienten mit derselben Identitaet wird nie automatisch entschieden. */
    public function testMultipleIdentityMatchesRequireExplicitUserDecision(): void
    {
        $firstId = $this->createPatient('LASTNAME1', 'FIRSTNAME1', '15.05.1940');
        $this->pdo->prepare(
            'INSERT INTO patients (patient_name, last_name, first_name, date_of_birth, date_of_birth_raw, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['LASTNAME1, FIRSTNAME1', 'LASTNAME1', 'FIRSTNAME1', '1940-05-15', '19400515', '2026-10-07 08:00:00', '2026-10-07 08:00:00']);
        $secondId = (int) $this->pdo->lastInsertId();

        $bytes = Fixtures::biotronikFile();
        $service = $this->importService();

        // Auch ohne aktiven Patienten keine stille Auswahl des aeltesten Treffers.
        $withoutActive = $service->analyze($bytes, 'BIOIEEE_ANN.xml', null);
        $this->assertSame(PatientResolutionMode::IdentityMultiple, $withoutActive->patientResolution->mode);
        $this->assertTrue($withoutActive->patientResolution->requiresDecision());
        $this->assertCount(2, $withoutActive->patientResolution->options);
        $this->assertThrows(PatientResolutionException::class, fn () => $service->import($withoutActive, $bytes));
        $this->assertSame(0, $this->rowCount('reports'));

        // Auch mit aktivem Patienten keine stille Auswahl ohne ausdrueckliche Bestaetigung.
        $withActive = $service->analyze($bytes, 'BIOIEEE_ANN.xml', $secondId);
        $this->assertSame(PatientResolutionMode::IdentityMultiple, $withActive->patientResolution->mode);
        $this->assertTrue($withActive->patientResolution->requiresDecision());
        $this->assertThrows(PatientResolutionException::class, fn () => $service->import($withActive, $bytes, false, $secondId));
        $this->assertSame(0, $this->rowCount('reports'));

        // Erst die ausdrueckliche Benutzerauswahl speichert den Bericht beim gewaehlten Patienten.
        $outcome = $service->import($withActive, $bytes, false, $secondId, 'patient:' . $firstId);
        $report = $this->pdo->query('SELECT * FROM reports')->fetch();
        $this->assertSame($outcome->reportId, (int) $report['id']);
        $this->assertSame($firstId, (int) $report['patient_id']);
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$secondId]));
    }

    /** H1: Unvollstaendige Datei-Identitaet wird niemals stillschweigend dem aktiven Patienten zugeordnet. */
    public function testIncompleteFileIdentityRequiresExplicitUserDecision(): void
    {
        $activeId = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950');

        // Reproduktion aus dem Review: Merlin-Export mit entferntem Geburtsdatum, fremdem Namen und fremder ID.
        $merlinWithoutDob = str_replace("2431\x1CPatient Date of Birth\x1C10/21/1938 00:00:00\x1C\x1C", '', Fixtures::sampleFile());
        $service = $this->importService();
        $analysis = $service->analyze($merlinWithoutDob, 'MERLIN_ohne_dob.log', $activeId);

        $this->assertSame(PatientResolutionMode::IncompleteIdentity, $analysis->patientResolution->mode);
        $this->assertTrue($analysis->patientResolution->requiresDecision());
        $warningCodes = array_map(static fn ($w): string => $w->code, $analysis->validation->warnings);
        $this->assertTrue(in_array('missing_patient_dob', $warningCodes, true));
        $this->assertTrue(in_array('incomplete_patient_identity', $warningCodes, true));

        // Ohne Auswahl wird der Import abgewiesen; Erika erhaelt weder Bericht noch fremdes Geraet.
        $this->assertThrows(
            PatientResolutionException::class,
            fn () => $service->import($analysis, $merlinWithoutDob, false, $activeId),
        );
        $this->assertSame(0, $this->rowCount('reports'));
        $this->assertSame(0, $this->rowCount('devices'));

        // Entscheidet der Benutzer "neuer Patient", landet der Bericht beim neuen Patienten mit der Datei-ID.
        $outcome = $service->import($analysis, $merlinWithoutDob, false, $activeId, 'new');
        $this->assertSame(2, $this->rowCount('patients'));
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$activeId]));
        $report = $this->pdo->query('SELECT * FROM reports WHERE id = ' . $outcome->reportId)->fetch();
        $newPatient = $this->pdo->query('SELECT * FROM patients WHERE id = ' . (int) $report['patient_id'])->fetch();
        $this->assertSame('LASTNAME, FIRSTNAME', $newPatient['patient_name']);
        $this->assertSame('10358141', $newPatient['patient_identifier']);

        // Ebenso bei einem Namen ohne Komma (fehlender Vorname).
        $noComma = str_replace('LASTNAME, FIRSTNAME', 'EINZELNAME', Fixtures::sampleFile());
        $noCommaAnalysis = $service->analyze($noComma, 'MERLIN_einzelname.log', $activeId);
        $this->assertSame(PatientResolutionMode::IncompleteIdentity, $noCommaAnalysis->patientResolution->mode);
        $this->assertThrows(
            PatientResolutionException::class,
            fn () => $service->import($noCommaAnalysis, $noComma, false, $activeId),
        );
    }

    /** M1: Eine bekannte Patienten-ID ordnet bei abweichender Identitaet niemals automatisch zu. */
    public function testKnownPatientIdentifierWithDifferentIdentityReportsConflict(): void
    {
        $existingId = (int) (new PatientService($this->pdo, new PatientRepository($this->pdo), $this->clock))
            ->create(PatientInput::fromPost([
                'last_name' => 'MUSTER',
                'first_name' => 'ERIKA',
                'date_of_birth' => '01.01.1950',
                'patient_identifier' => '10358141',
            ]))['patient_id'];

        $bytes = Fixtures::sampleFile();
        $service = $this->importService();
        $analysis = $service->analyze($bytes, 'MERLIN__ANN_5809481.log');

        $this->assertSame(PatientResolutionMode::IdentifierConflict, $analysis->patientResolution->mode);
        $this->assertTrue($analysis->patientResolution->requiresDecision());
        $this->assertSame($existingId, $analysis->patientResolution->conflictingPatient['id'] ?? null);
        $this->assertThrows(PatientResolutionException::class, fn () => $service->import($analysis, $bytes));
        $this->assertSame(0, $this->rowCount('reports'));

        // Bei Auswahl "neuer Patient" bleibt die vergebene ID beim Altpatienten, der Snapshot behaelt sie.
        $outcome = $service->import($analysis, $bytes, false, null, 'new');
        $this->assertSame(2, $this->rowCount('patients'));
        $this->assertSame(0, $this->rowCount('reports', 'patient_id = ?', [$existingId]));
        $report = $this->pdo->query('SELECT * FROM reports WHERE id = ' . $outcome->reportId)->fetch();
        $this->assertSame('10358141', $report['patient_identifier_snapshot']);
        $newPatient = $this->pdo->query('SELECT * FROM patients WHERE id = ' . (int) $report['patient_id'])->fetch();
        $this->assertSame('LASTNAME, FIRSTNAME', $newPatient['patient_name']);
        $this->assertNull($newPatient['patient_identifier']);
    }

    /** M2: Wechselt der aktive Patient zwischen Vorschau und Speichern, wird das Speichern abgewiesen. */
    public function testActivePatientChangeBetweenPreviewAndCommitIsRejected(): void
    {
        $erikaId = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950');
        $maxId = $this->createPatient('BEISPIEL', 'MAX', '02.02.1960');
        $bytes = str_replace(
            ['>FIRSTNAME1</value>', '>LASTNAME1</value>', '>19400515</value>'],
            ['></value>', '></value>', '></value>'],
            Fixtures::biotronikFile(),
        );

        $service = $this->importService();
        $previewAnalysis = $service->analyze($bytes, 'BIOIEEE_ANN.xml', $erikaId);
        $previewTarget = $previewAnalysis->patientResolution->defaultTarget;
        $this->assertSame('patient:' . $erikaId, $previewTarget);

        // Beim Speichern ist in einem anderen Tab inzwischen Max aktiv geworden.
        $commitAnalysis = $service->analyze($bytes, 'BIOIEEE_ANN.xml', $maxId);
        $this->assertThrows(
            PatientResolutionException::class,
            fn () => $service->import($commitAnalysis, $bytes, false, $maxId, $previewTarget),
        );
        $this->assertSame(0, $this->rowCount('reports'));
    }

    /** Ohne jegliche Patientendaten in der Datei wird der Bericht dem aktiven Patienten zugeordnet. */
    public function testFileWithoutPatientIdentityUsesActivePatient(): void
    {
        $patientId = $this->createPatient('MUSTER', 'ERIKA', '01.01.1950');
        $bytes = str_replace(
            ['>FIRSTNAME1</value>', '>LASTNAME1</value>', '>19400515</value>'],
            ['></value>', '></value>', '></value>'],
            Fixtures::biotronikFile(),
        );

        $service = $this->importService();
        $analysis = $service->analyze($bytes, 'BIOIEEE_ANN.xml', $patientId);
        $messages = array_map(static fn ($issue): string => $issue->message, $analysis->validation->warnings);
        $this->assertTrue(in_array(
            'Keine Patientendaten in der Datei; der Bericht wird dem aktiven Patienten zugeordnet.',
            $messages,
            true,
        ));

        $outcome = $service->import($analysis, $bytes, false, $patientId);
        $this->assertSame('completed_with_warnings', $outcome->status);
        $this->assertSame(1, $this->rowCount('patients'));
        $report = $this->pdo->query('SELECT * FROM reports')->fetch();
        $this->assertSame($patientId, (int) $report['patient_id']);
        $this->assertSame(1, $this->rowCount('reports', 'patient_id = ?', [$patientId]));
    }

    /** Die importierten Biotronik-Messwerte stehen in der Messwerttabelle von Brief und Ausweis. */
    public function testBiotronikMeasurementsAreAvailableForLetterAndCard(): void
    {
        $outcome = $this->importSample();
        $report = $this->reportService()->load($outcome->reportId);
        $this->assertTrue($report !== null);
        $patientId = (int) $report->report['patient_id'];

        $template = MeasurementTemplate::default(dirname(__DIR__, 2));
        $measurements = (new LetterMeasurements($template, new PatientCardRepository($this->pdo)))
            ->resolve($patientId, $report);
        $this->assertTrue($measurements['present'], 'Der Brief erhaelt eine Messwerttabelle.');

        $cells = $this->measurementCells($measurements['sections']);
        $this->assertSame('MOS', $cells['Batterie|Status|']);
        $this->assertSame('585', $cells['Elektroden|Impedanz [Ohm]|RV']);
        $this->assertSame('6.0', $cells['Elektroden|Wahrnehmung [mV]|RV']);
        $this->assertSame('4.7/0.4', $cells['Elektroden|Reizschwelle [V/ms]|RV']);
        $this->assertSame('VVI', $cells['Bradykardie|Betriebsart|']);
        $this->assertSame('60', $cells['Bradykardie|Untere Grenzfrequenz [1/min]|']);
        $this->assertSame('50', $cells['Bradykardie|Hysteresefrequenz [1/min]|']);
        $this->assertSame('55', $cells['Bradykardie|Ruhe-/Nachtfunktion|']);
        $this->assertSame('5.0/1.5', $cells['RV|Output [V/ms]|']);
        $this->assertSame('BI', $cells['RV|Wahrnehmungspolarität|']);
        $this->assertSame('BI', $cells['RV|Stimulationspolarität|']);

        // Der Ausweis loest dieselbe Vorlage mit denselben Werten auf.
        $cardValues = (new PatientCardRepository($this->pdo))->measurementValues(
            [$outcome->reportId],
            $template->parameterIds(),
            $template->parameterNames(),
        );
        $cardSections = $template->resolve([[
            'report_id' => $outcome->reportId,
            'date' => null,
            'date_display' => '',
            'current' => true,
        ]], $cardValues);
        $this->assertSame('VVI', $this->measurementCells($cardSections)['Bradykardie|Betriebsart|']);
    }

    public function testDeviceAndLeadAreStoredUnchanged(): void
    {
        $this->importSample();

        $device = $this->pdo->query('SELECT * FROM devices')->fetch();
        $this->assertSame('9000000101', $device['serial_number']);
        $this->assertSame('Enticos 4 SR', $device['model_name']);
        $this->assertSame('BIO', $device['manufacturer']);
        $this->assertNull($device['model_number']);
        $this->assertSame('2024-03-15', $device['implant_date']);
        $this->assertSame('20240315T000000', $device['implant_date_raw']);

        $lead = $this->pdo->query('SELECT * FROM leads')->fetch();
        $this->assertSame((int) $device['id'], (int) $lead['device_id']);
        $this->assertSame('rv', $lead['chamber']);
        $this->assertSame('RV', $lead['chamber_source']);
        $this->assertSame('BIO', $lead['manufacturer']);
        $this->assertSame('Solia S 60', $lead['model_number']);
        $this->assertSame('8000000202', $lead['serial_number']);
        $this->assertSame('2024-03-15', $lead['implant_date']);
        $this->assertSame('20240315T000000', $lead['implant_date_raw']);
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
        $this->assertSame('19400515', $byId['MDC.ATTR.PT.DOB']['value']);
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
        $this->assertContains('15.05.1940', $text);
        $this->assertContains('Enticos 4 SR', $text);
        $this->assertContains('9000000101', $text);
        $this->assertContains('BIO', $text);
        $this->assertContains('8000000202', $text);
        $this->assertContains('Bericht Nr. ' . $outcome->reportId, $text);
        foreach ($data->parameters as $parameter) {
            $this->assertContains($parameter['parameter_id'], $text);
        }
        $this->assertSame('Bericht_' . $outcome->reportId . '_2026-10-08_SN9000000101.pdf', ReportService::pdfFilename($data));
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

    private function createPatient(string $lastName, string $firstName, string $dateOfBirth): int
    {
        $service = new PatientService($this->pdo, new PatientRepository($this->pdo), $this->clock);
        return (int) $service->create(PatientInput::fromPost([
            'last_name' => $lastName,
            'first_name' => $firstName,
            'date_of_birth' => $dateOfBirth,
        ]))['patient_id'];
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return array<string, string> Gruppe|Zeile|Kammer => Wert der aktuellen Untersuchung
     */
    private function measurementCells(array $sections): array
    {
        $cells = [];
        foreach ($sections as $section) {
            foreach ((array) ($section['groups'] ?? []) as $group) {
                foreach ((array) ($group['rows'] ?? []) as $row) {
                    $key = (string) $group['label'] . '|' . (string) $row['label'] . '|' . (string) ($row['chamber'] ?? '');
                    $cells[$key] = (string) (((array) ($row['values'] ?? []))[0] ?? '');
                }
            }
        }
        return $cells;
    }
}
