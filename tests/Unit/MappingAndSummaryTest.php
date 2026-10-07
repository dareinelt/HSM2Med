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
        $validation = $validator->validate($duplicate, $builder->build($duplicate->records));
        $this->assertTrue($validation->isValid());
        $codes = array_map(static fn ($w) => $w->code, $validation->warnings);
        $this->assertTrue(in_array('duplicate_parameter_id', $codes, true));
        $this->assertTrue(in_array('missing_device_serial', $codes, true));

        $nothing = (new MerlinParser())->parse("kein Merlin\x1C");
        $this->assertFalse($validator->validate($nothing, $builder->build($nothing->records))->isValid());
    }

    public function testMappingConfigurationIsConsistent(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/parameter_mapping.php';
        $mapping = new ParameterMapping($config);
        $this->assertSame('1.0.0', $mapping->version());
        foreach ($config['by_id'] as $id => $target) {
            $this->assertTrue(preg_match('/^\d{1,32}$/D', (string) $id) === 1, "ID {$id}");
        }
        $this->assertSame([], $config['standard_codes'], 'Standardzuordnungen nur explizit dokumentiert');
    }
}
