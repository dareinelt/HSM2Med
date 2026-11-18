<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Import\ImportValidator;
use App\Import\MerlinParser;
use App\Mapping\CategoryAssignment;
use App\Mapping\ParameterMapping;
use App\Report\ReportSummaryBuilder;
use Tests\Support\Fixtures;
use Tests\Support\ReportDataFactory;
use Tests\TestCase;

final class MappingAndSummaryTest extends TestCase
{
    public function testEverySampleParameterIsCategorizedById(): void
    {
        $mapping = ReportDataFactory::mapping();
        $result = (new MerlinParser())->parse(Fixtures::sampleFile());
        foreach ($result->records as $record) {
            $assignment = $mapping->resolve($record->parameterId, $record->name);
            $this->assertSame(CategoryAssignment::SOURCE_ID, $assignment->source, "Parameter {$record->parameterId}");
            $this->assertNotSame('other', $assignment->key, "Parameter {$record->parameterId}");
        }
    }

    public function testResolutionOrderAndFallback(): void
    {
        $mapping = ReportDataFactory::mapping();
        $this->assertSame(['ventricle', 'id'], [$mapping->resolve('306', 'egal')->key, $mapping->resolve('306', 'egal')->source]);
        $byPattern = $mapping->resolve('999999', 'MRI Something New');
        $this->assertSame('mri', $byPattern->key);
        $this->assertSame('pattern', $byPattern->source);
        $unknown = $mapping->resolve('999998', 'Völlig unbekannt');
        $this->assertSame(['other', 'none'], [$unknown->key, $unknown->source]);
        $this->assertSame('Völlig unbekannt', $unknown->displayName);
        // Keine unbelegten Standardzuordnungen
        $this->assertNull($mapping->standardCode('306'));
    }

    public function testSummaryUsesOnlySourceData(): void
    {
        $mapping = ReportDataFactory::mapping();
        $builder = new ReportSummaryBuilder($mapping);
        $result = (new MerlinParser())->parse(Fixtures::sampleFile());
        $summary = $builder->build($result->records);

        $this->assertSame('LASTNAME, FIRSTNAME', $summary->value('patient_name'));
        $this->assertSame('10358141', $summary->value('patient_identifier'));
        $this->assertSame('5809481', $summary->value('device_serial'));
        $this->assertSame('Endurity Core', $summary->value('device_model_name'));
        $this->assertSame('2152', $summary->value('device_model_number'));
        $this->assertNull($summary->value('device_manufacturer'), 'Hersteller darf nicht ergaenzt werden');

        $this->assertCount(2, $summary->leads);
        [$atrial, $rv] = $summary->leads;
        $this->assertSame(['atrial', 'St. Jude Medical', '2088TC Tendril STS', 'EEL193668', '06/18/2024 00:00:00'], [
            $atrial['chamber'], $atrial['manufacturer'], $atrial['model_number'], $atrial['serial_number'], $atrial['implant_date'],
        ]);
        $this->assertSame(['rv', 'EEM126412'], [$rv['chamber'], $rv['serial_number']]);

        $snapshot = $builder->toSnapshot($summary);
        $this->assertSame(1, $snapshot['snapshot_version']);
        $deviceRows = array_column($snapshot['sections'][1]['rows'], null, 'label');
        $this->assertNull($deviceRows['Hersteller']['value']);
        $this->assertSame('07.10.2026 07:03:24', $deviceRows['Sitzungszeitpunkt']['value']);
        $this->assertSame('10/07/2026 07:03:24', $deviceRows['Sitzungszeitpunkt']['original']);
    }

    public function testValidatorWarningsAndBlockingErrors(): void
    {
        $mapping = ReportDataFactory::mapping();
        $builder = new ReportSummaryBuilder($mapping);
        $validator = new ImportValidator();

        $ok = (new MerlinParser())->parse(Fixtures::sampleFile());
        $this->assertTrue($validator->validate($ok, $builder->build($ok->records))->isValid());

        $duplicate = (new MerlinParser())->parse(Fixtures::build([['1', 'A', '1', ''], ['1', 'A', '2', '']]));
        $validation = $validator->validate($duplicate, $builder->build($duplicate->records), false);
        $this->assertTrue($validation->isValid());
        $codes = array_map(static fn ($w) => $w->code, $validation->warnings);
        $messages = array_map(static fn ($w) => $w->message, $validation->warnings);
        $this->assertTrue(in_array('duplicate_parameter_id', $codes, true));
        $this->assertTrue(in_array('missing_device_serial', $codes, true));
        $this->assertTrue(in_array('missing_patient', $codes, true));
        $this->assertFalse(in_array('missing_patient_dob', $codes, true), 'Ohne jegliche Patientendaten erscheint nur missing_patient.');
        $this->assertTrue(in_array('Keine Patientendaten vorhanden; der Bericht wird ohne Patientenzuordnung gespeichert.', $messages, true));

        $validationWithActive = $validator->validate($duplicate, $builder->build($duplicate->records), true);
        $messagesWithActive = array_map(static fn ($w) => $w->message, $validationWithActive->warnings);
        $this->assertTrue(in_array('Keine Patientendaten in der Datei; der Bericht wird dem aktiven Patienten zugeordnet.', $messagesWithActive, true));

        // Teil-Identitaet ohne Geburtsdatum meldet missing_patient_dob und incomplete_patient_identity.
        $withoutDob = (new MerlinParser())->parse(Fixtures::build([
            ['202', 'Device Serial Number', '5809481', ''],
            ['204', 'Patient ID', '10358141', ''],
            ['2430', 'Patient Name', 'LASTNAME, FIRSTNAME', ''],
        ]));
        $partialValidation = $validator->validate($withoutDob, $builder->build($withoutDob->records), true);
        $partialCodes = array_map(static fn ($w) => $w->code, $partialValidation->warnings);
        $this->assertSame(['missing_patient_dob', 'incomplete_patient_identity'], $partialCodes);

        // Name ohne Komma (fehlender Vorname) meldet incomplete_patient_identity.
        $withoutFirstName = (new MerlinParser())->parse(Fixtures::build([
            ['202', 'Device Serial Number', '5809481', ''],
            ['2430', 'Patient Name', 'EINZELNAME', ''],
            ['2431', 'Patient Date of Birth', '10/21/1938 00:00:00', ''],
        ]));
        $nameCodes = array_map(static fn ($w) => $w->code, $validator->validate($withoutFirstName, $builder->build($withoutFirstName->records), true)->warnings);
        $this->assertSame(['incomplete_patient_identity'], $nameCodes);

        $nothing = (new MerlinParser())->parse("kein Merlin\x1C");
        $this->assertFalse($validator->validate($nothing, $builder->build($nothing->records))->isValid());
    }

    public function testMappingConfigurationIsConsistent(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/parameter_mapping.php';
        $mapping = new ParameterMapping($config);
        $this->assertSame('1.1.0', $mapping->version());
        foreach ($config['by_id'] as $id => $target) {
            $this->assertTrue(preg_match('/^\d{1,32}$/D', (string) $id) === 1, "ID {$id}");
        }
        $this->assertSame([], $config['standard_codes'], 'Standardzuordnungen nur explizit dokumentiert');
    }
}
