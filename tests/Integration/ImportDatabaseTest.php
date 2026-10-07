<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Import\DuplicateImportException;
use App\Import\ImportFailedException;
use App\Import\ImportService;
use App\Import\MerlinParser;
use App\Mapping\ParameterMapping;
use App\Repository\ReportRepository;
use DateTimeImmutable;
use PDOException;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\Support\PdfText;

/**
 * Hilfsklasse: bricht beim zweiten Parameterblock ab (Rollback-Test).
 */
final class FailingImportService extends ImportService
{
    protected function insertParameterChunk(array $rows, int $chunkIndex): void
    {
        if ($chunkIndex >= 1) {
            throw new RuntimeException('Simulierter Fehler beim Speichern der Parameter');
        }
        parent::insertParameterChunk($rows, $chunkIndex);
    }
}

final class ImportDatabaseTest extends DatabaseTestCase
{
    private function importSample(?ImportService $service = null, ?string $bytes = null, string $filename = 'MERLIN__ANN_5809481.log'): \App\Import\ImportOutcome
    {
        $service ??= $this->importService();
        $bytes ??= Fixtures::sampleFile();
        return $service->import($service->analyze($bytes, $filename), $bytes);
    }

    /** Test 13: Erfolgreicher Import speichert alle Tabellen konsistent. */
    public function testSuccessfulImport(): void
    {
        $outcome = $this->importSample();
        $this->assertSame('completed', $outcome->status);
        $this->assertSame(113, $outcome->parameterCount);

        $this->assertSame(1, $this->rowCount('imports'));
        $this->assertSame(1, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(1, $this->rowCount('devices'));
        $this->assertSame(2, $this->rowCount('leads'));
        $this->assertSame(2, $this->rowCount('report_leads'));
        $this->assertSame(113, $this->rowCount('report_parameters'));
        $this->assertSame(113, $this->rowCount('parameter_definitions'));
        $this->assertSame(0, $this->rowCount('import_errors'));

        $import = $this->pdo->query('SELECT * FROM imports')->fetch();
        $this->assertSame(hash('sha256', Fixtures::sampleFile()), $import['file_hash']);
        $this->assertSame(strlen(Fixtures::sampleFile()), (int) $import['file_size']);
        $this->assertSame(113, (int) $import['record_count']);
        $this->assertSame('2026-10-07 08:00:00', $import['imported_at']);
        $this->assertTrue(is_file($this->archiveDir . '/' . $import['archive_filename']), 'Originaldatei archiviert');
        $this->assertSame(Fixtures::sampleFile(), file_get_contents($this->archiveDir . '/' . $import['archive_filename']));

        $report = $this->pdo->query('SELECT * FROM reports')->fetch();
        $this->assertSame('LASTNAME, FIRSTNAME', $report['patient_name_snapshot']);
        $this->assertSame('10358141', $report['patient_identifier_snapshot']);
        $this->assertSame('5809481', $report['device_serial_snapshot']);
        $this->assertSame('2152', $report['device_model_number_snapshot']);
        $this->assertNull($report['device_manufacturer_snapshot']);
        $this->assertSame('2026-10-07 07:03:24', $report['session_timestamp']);
        $this->assertSame('10/07/2026 07:03:24', $report['session_timestamp_raw']);
        $this->assertSame(113, (int) $report['parameter_count']);
        $snapshot = json_decode($report['summary_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $snapshot['leads']);

        $device = $this->pdo->query('SELECT * FROM devices')->fetch();
        $this->assertSame('5809481', $device['serial_number']);
        $this->assertSame((int) $report['device_id'], (int) $device['id']);
    }

    /** Test 14: Fehler mitten im Import -> vollstaendiger Rollback. */
    public function testRollbackOnFailure(): void
    {
        $service = $this->importService(null, FailingImportService::class);
        $exception = $this->assertThrows(ImportFailedException::class, fn () => $this->importSample($service));

        foreach (['reports', 'report_parameters', 'report_leads', 'patients', 'devices', 'leads', 'parameter_definitions'] as $table) {
            $this->assertSame(0, $this->rowCount($table), "Tabelle {$table} muss leer sein");
        }
        $this->assertSame(1, $this->rowCount('imports'));
        $failed = $this->pdo->query('SELECT * FROM imports')->fetch();
        $this->assertSame('failed', $failed['status']);
        $this->assertSame((int) $failed['id'], $exception->failedImportId);

        // Erneuter Import derselben Datei ist nach Fehlschlag moeglich
        $outcome = $this->importSample();
        $this->assertSame(113, $outcome->parameterCount);
    }

    /** Test 15: Fremdschluessel verhindern verwaiste oder geloeschte Bezuege. */
    public function testForeignKeysAreEnforced(): void
    {
        $outcome = $this->importSample();

        $this->assertThrows(PDOException::class, fn () => $this->pdo->exec('DELETE FROM imports WHERE id = ' . $outcome->importId));
        $this->assertThrows(PDOException::class, fn () => $this->pdo->exec('DELETE FROM devices'));
        $this->assertThrows(PDOException::class, fn () => $this->pdo->exec('DELETE FROM parameter_definitions'));
        $this->assertThrows(PDOException::class, fn () => $this->pdo->exec(
            "INSERT INTO report_parameters (report_id, parameter_definition_id, parameter_id, parameter_name, display_name, value, unit, category, category_label, category_sort, mapping_source, original_position, raw_record, created_at)
             SELECT 999999, id, '1', 'x', 'x', '', '', 'other', 'x', 1, 'none', 1, '', NOW() FROM parameter_definitions LIMIT 1"
        ));
        $this->assertThrows(PDOException::class, fn () => $this->pdo->exec(
            "INSERT INTO reports (import_id, report_version, parser_version, mapping_version, summary_snapshot, parameter_count, created_at)
             VALUES (999999, 1, '1', '1', '{}', 0, NOW())"
        ));
        $this->assertSame(1, $this->rowCount('reports'));
    }

    /** Test 16: Anzahl gespeicherter Parameter entspricht den gueltigen Datensaetzen, Werte unveraendert. */
    public function testAllParametersStoredUnchanged(): void
    {
        $records = Fixtures::specRecords();
        $records[] = ['77777', 'Leerer Wert', '', ''];
        $records[] = ['77778', 'Null-Wert', '0', 'mV'];
        $records[] = ['77779', 'Umlaute ÄÖÜ äöü ß', 'µs Ω', '°C'];
        $text = Fixtures::build($records);
        // Fehlerhafter Datensatz: ungueltige ID
        $text .= "\r\nABC\x1CDefekt\x1C1\x1C\x1C";

        $parsed = (new MerlinParser())->parse($text);
        $this->assertSame(count($records), count($parsed->records));
        $this->assertTrue(count($parsed->errors()) > 0);

        $service = $this->importService();
        $outcome = $service->import($service->analyze($text, 'spec.txt'), $text);
        $this->assertSame('completed_with_errors', $outcome->status);
        $this->assertSame(count($records), $outcome->parameterCount);
        $this->assertSame(count($records), $this->rowCount('report_parameters'));
        $this->assertSame(count($parsed->issues), $this->rowCount('import_errors'));

        $stored = $this->pdo->query('SELECT parameter_id, parameter_name, value, unit, original_position, raw_record FROM report_parameters ORDER BY original_position')->fetchAll();
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
        $this->assertSame('', $byId['77777']['value'], 'Leerer Wert bleibt leerer String');
        $this->assertSame('0', $byId['77778']['value']);
        $this->assertSame('µs Ω', $byId['77779']['value']);
        $this->assertSame('°C', $byId['77779']['unit']);

        $import = $this->pdo->query('SELECT * FROM imports')->fetch();
        $this->assertSame(count($records) + 1, (int) $import['record_count']);
        $this->assertSame(count($records), (int) $import['valid_record_count']);
    }

    /** Test 17: Gespeicherter Bericht wird vollstaendig und unveraendert wieder geladen. */
    public function testReportReloadMatchesImport(): void
    {
        $outcome = $this->importSample();
        $data = $this->reportService()->load($outcome->reportId);
        $this->assertTrue($data !== null);
        $parsed = (new MerlinParser())->parse(Fixtures::sampleFile());

        $this->assertCount(113, $data->parameters);
        foreach ($parsed->records as $i => $record) {
            $this->assertSame($record->parameterId, $data->parameters[$i]['parameter_id']);
            $this->assertSame($record->value, $data->parameters[$i]['value']);
            $this->assertSame($record->unit, $data->parameters[$i]['unit']);
        }
        $this->assertSame('LASTNAME, FIRSTNAME', $data->report['patient_name_snapshot']);
        $this->assertSame('MERLIN__ANN_5809481.log', $data->import['filename']);
        $this->assertCount(2, $data->leads());
        $this->assertSame(113, array_sum(array_map(static fn ($c) => count($c['parameters']), $data->categories())));
        $this->assertNull($this->reportService()->load(999));

        // Manipulierte Parameteranzahl wird erkannt
        $this->pdo->exec('UPDATE reports SET parameter_count = 5');
        $this->assertThrows(\RuntimeException::class, fn () => $this->reportService()->load($outcome->reportId));
    }

    /** Test 18: Historische Berichte bleiben trotz neuer Importe und geaenderter Zuordnung unveraendert. */
    public function testHistoricReportsStayUnchanged(): void
    {
        $first = $this->importSample();
        $generatedAt = new DateTimeImmutable('2026-10-08 09:00:00');
        $service = $this->reportService();
        $pdfBefore = $service->renderPdf($service->load($first->reportId), true, $generatedAt);
        $snapshotBefore = $this->pdo->query('SELECT * FROM reports WHERE id = ' . $first->reportId)->fetch();
        $paramsBefore = $this->pdo->query('SELECT * FROM report_parameters WHERE report_id = ' . $first->reportId . ' ORDER BY id')->fetchAll();

        // Neue Zuordnungsversion: Parameter 306 wird anders kategorisiert, Kategorie umbenannt
        $config = require dirname(__DIR__, 2) . '/config/parameter_mapping.php';
        $config['version'] = '2.0.0';
        $config['categories']['ventricle']['label'] = 'Ventrikel NEU';
        $config['by_id']['306'] = 'other';
        $newMapping = new ParameterMapping($config);

        // Neue Auslesung desselben Geraets mit geaendertem Patientennamen und Wert
        $bytes = str_replace(['LASTNAME, FIRSTNAME', '10/07/2026 07:03:24'], ['NEUNAME, VORNAME', '11/07/2026 07:03:24'], Fixtures::sampleFile());
        $this->clock->set(new DateTimeImmutable('2026-11-07 08:00:00'));
        $second = $this->importSample($this->importService($newMapping), $bytes, 'zweite.log');

        $this->assertSame(2, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('devices'), 'Gleiches Geraet wird wiederverwendet');
        $this->assertSame($snapshotBefore, $this->pdo->query('SELECT * FROM reports WHERE id = ' . $first->reportId)->fetch());
        $this->assertSame($paramsBefore, $this->pdo->query('SELECT * FROM report_parameters WHERE report_id = ' . $first->reportId . ' ORDER BY id')->fetchAll());

        $old = $service->load($first->reportId);
        $new = $service->load($second->reportId);
        $this->assertSame('LASTNAME, FIRSTNAME', $old->report['patient_name_snapshot']);
        $this->assertSame('NEUNAME, VORNAME', $new->report['patient_name_snapshot']);
        $this->assertSame('1.0.0', $old->report['mapping_version']);
        $this->assertSame('2.0.0', $new->report['mapping_version']);
        $oldLabels = array_column($old->categories(), 'label');
        $newLabels = array_column($new->categories(), 'label');
        $this->assertTrue(in_array('Ventrikel / RV', $oldLabels, true));
        $this->assertTrue(in_array('Ventrikel NEU', $newLabels, true));

        $this->assertSame($pdfBefore, $service->renderPdf($old, true, $generatedAt), 'Historisches PDF identisch');

        $related = (new ReportRepository($this->pdo))->relatedByDevice((int) $old->report['device_id'], $old->id());
        $this->assertCount(1, $related);
    }

    /** Test 24: PDF eines historischen Berichts entsteht allein aus der Datenbank. */
    public function testPdfFromDatabaseWithoutOriginalFile(): void
    {
        $outcome = $this->importSample();
        foreach (glob($this->archiveDir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
            unlink($file);
        }
        $this->assertSame([], glob($this->archiveDir . '/*') ?: []);

        $service = $this->reportService();
        $data = $service->load($outcome->reportId);
        $pdf = $service->renderPdf($data, true, new DateTimeImmutable('2026-10-08 09:00:00'));
        $this->assertSame('%PDF-', substr($pdf, 0, 5));
        $text = PdfText::text($pdf);
        $this->assertContains('LASTNAME, FIRSTNAME', $text);
        $this->assertContains('EEL193668', $text);
        $this->assertContains('Endurity Core', $text);
        $this->assertContains('Bericht Nr. ' . $outcome->reportId, $text);
        foreach ($data->parameters as $parameter) {
            $this->assertContains($parameter['parameter_id'], $text);
        }
        $this->assertSame('Bericht_' . $outcome->reportId . '_2026-10-07_SN5809481.pdf', \App\Report\ReportService::pdfFilename($data));
    }

    public function testDuplicateDetection(): void
    {
        $this->importSample();
        $service = $this->importService();
        $analysis = $service->analyze(Fixtures::sampleFile(), 'nochmal.log');
        $this->assertCount(1, $service->findImportsByHash($analysis->fileHash));
        $this->assertThrows(DuplicateImportException::class, fn () => $service->import($analysis, Fixtures::sampleFile()));
        $this->assertSame(1, $this->rowCount('reports'));

        $outcome = $service->import($analysis, Fixtures::sampleFile(), true);
        $this->assertSame(2, $this->rowCount('reports'));
        $this->assertSame(1, $this->rowCount('patients'));
        $this->assertSame(2, $outcome->reportId);
    }

    public function testSearchFilters(): void
    {
        $this->importSample();
        $bytes = str_replace(['5809481', 'LASTNAME'], ['1111111', 'MUSTER'], Fixtures::sampleFile());
        $this->importSample(null, $bytes, 'muster.log');
        $repo = new ReportRepository($this->pdo);

        $this->assertSame(2, $repo->search([], 50, 0)['total']);
        $this->assertSame(1, $repo->search(['serial' => '1111111'], 50, 0)['total']);
        $this->assertSame(1, $repo->search(['patient' => 'muster'], 50, 0)['total']);
        $this->assertSame(2, $repo->search(['patient_id' => '10358141'], 50, 0)['total']);
        $this->assertSame(1, $repo->search(['filename' => 'muster'], 50, 0)['total']);
        $this->assertSame(2, $repo->search(['model' => 'Endurity'], 50, 0)['total']);
        $this->assertSame(1, $repo->search(['q' => '5809481'], 50, 0)['total']);
        $this->assertSame(2, $repo->search(['date_from' => '2026-10-07', 'date_to' => '2026-10-07'], 50, 0)['total']);
        $this->assertSame(0, $repo->search(['date_from' => '2026-10-08'], 50, 0)['total']);
        $this->assertSame(0, $repo->search(['q' => "' OR 1=1 -- %"], 50, 0)['total']);
        $this->assertCount(1, $repo->search([], 1, 1)['rows']);
        $stats = $repo->statistics();
        $this->assertSame(2, (int) $stats['reports']);
    }
}
