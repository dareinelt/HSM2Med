<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\PatientCard\MeasurementTemplate;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Vorlage der Messwerttabelle auf Seite 2 (config/patient_card_measurements.php).
 */
final class MeasurementTemplateTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    private function template(): MeasurementTemplate
    {
        return MeasurementTemplate::default(dirname(__DIR__, 2));
    }

    /**
     * @return list<array{report_id: int, date: ?string, date_display: string, current: bool}>
     */
    private function columns(int $count): array
    {
        $columns = [];
        for ($index = 0; $index < $count; $index++) {
            $columns[] = [
                'report_id' => $index + 1,
                'date' => sprintf('2026-01-%02d', $index + 1),
                'date_display' => sprintf('%02d.01.2026', $index + 1),
                'current' => $index === 0,
            ];
        }
        return $columns;
    }

    public function testConfigFileIsLoadedAndValidated(): void
    {
        $template = $this->template();

        $this->assertSame('1.0.0', $template->version());
        $this->assertSame(7, $template->columnCount());
        $this->assertSame(6, $template->previousCount());
        $this->assertSame(['Messungen', 'Programmierung'], array_column($template->sections(), 'label'));
        $this->assertNotSame([], $template->parameterIds());
        $this->assertNotSame([], $template->parameterNames());
    }

    public function testTemplateContainsAllRowsOfTheForm(): void
    {
        $rows = 0;
        foreach ($this->template()->sections() as $section) {
            foreach ($section['groups'] as $group) {
                $this->assertNotSame('', $group['label']);
                foreach ($group['rows'] as $row) {
                    $this->assertNotSame('', $row['label']);
                    $rows++;
                }
            }
        }
        $this->assertSame(42, $rows);
    }

    public function testParameterNamesAreNormalized(): void
    {
        $names = $this->template()->parameterNames();
        foreach ($names as $name) {
            $this->assertSame($name, mb_strtolower(trim($name)));
        }
        $this->assertTrue(in_array('mode', $names, true), 'Name "mode" fehlt in der Vorlage.');
        $this->assertTrue(in_array('rv pacing lead impedance', $names, true), 'Name "rv pacing lead impedance" fehlt.');
    }

    public function testResolvesValuesByParameterIdAndName(): void
    {
        $template = $this->template();
        $columns = $this->columns(2);
        $sections = $template->resolve($columns, [
            1 => ['ids' => ['301' => 'DDD'], 'names' => []],
            2 => ['ids' => [], 'names' => ['mode' => 'VVI']],
        ]);

        $this->assertSame(['DDD', 'VVI'], $this->cells($sections, 'Programmierung', 'Bradykardie', 'Betriebsart'));
    }

    public function testParameterIdTakesPrecedenceOverName(): void
    {
        $columns = $this->columns(1);
        $sections = $this->template()->resolve($columns, [
            1 => ['ids' => ['301' => 'DDD'], 'names' => ['mode' => 'VVI']],
        ]);

        $this->assertSame(['DDD'], $this->cells($sections, 'Programmierung', 'Bradykardie', 'Betriebsart'));
    }

    public function testFirstNonEmptyValueWins(): void
    {
        $columns = $this->columns(1);
        $sections = $this->template()->resolve($columns, [
            1 => ['ids' => ['519' => '', '520' => '3.20'], 'names' => ['unloaded battery voltage' => '2.79']],
        ]);

        $this->assertSame(['2.79'], $this->cells($sections, 'Messungen', 'Batterie', 'Spannung [V]'));
    }

    public function testTwoPartValuesAreJoinedWithGlue(): void
    {
        $columns = $this->columns(1);
        $sections = $this->template()->resolve($columns, [
            1 => ['ids' => ['1606' => '0.5', '1607' => '0.4'], 'names' => []],
        ]);

        $this->assertSame(['0.5/0.4'], $this->cells($sections, 'Messungen', 'Elektroden', 'Reizschwelle [V/ms]', 'RV'));
    }

    public function testIncompleteTwoPartValuesKeepTheAvailablePart(): void
    {
        $columns = $this->columns(1);
        $sections = $this->template()->resolve($columns, [
            1 => ['ids' => ['1606' => '0.5'], 'names' => []],
        ]);

        $this->assertSame(['0.5'], $this->cells($sections, 'Messungen', 'Elektroden', 'Reizschwelle [V/ms]', 'RV'));
    }

    public function testRowsWithoutMappingAndMissingValuesStayEmpty(): void
    {
        $columns = $this->columns(3);
        $sections = $this->template()->resolve($columns, []);

        // Zeile ohne Zuordnung in der Vorlage bleibt leer.
        $this->assertSame(['', '', ''], $this->cells($sections, 'Programmierung', 'Bradykardie', 'VV-Zeit'));
        // Vorhandene Zuordnung, aber kein Wert im Bericht: Zelle bleibt leer.
        $this->assertSame(['', '', ''], $this->cells($sections, 'Programmierung', 'Bradykardie', 'Betriebsart'));
    }

    public function testCellsWithoutValuesAreFilledPerReport(): void
    {
        $columns = $this->columns(3);
        $sections = $this->template()->resolve($columns, [
            2 => ['ids' => ['301' => 'DDD'], 'names' => []],
        ]);

        $this->assertSame(['', 'DDD', ''], $this->cells($sections, 'Programmierung', 'Bradykardie', 'Betriebsart'));
    }

    public function testChamberIsCarriedIntoTheResolvedRows(): void
    {
        $sections = $this->template()->resolve($this->columns(1), []);
        $chambers = [];
        foreach ($sections[0]['groups'] as $group) {
            if ($group['label'] !== 'Elektroden') {
                continue;
            }
            foreach ($group['rows'] as $row) {
                $chambers[] = $row['label'] . ':' . $row['chamber'];
            }
        }
        $this->assertTrue(in_array('Impedanz [Ohm]:RA', $chambers, true), 'Kammer RA fehlt in den Elektrodenzeilen.');
        $this->assertTrue(in_array('Impedanz [Ohm]:RV', $chambers, true), 'Kammer RV fehlt in den Elektrodenzeilen.');
    }

    public function testRejectsInvalidConfigurations(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn () => new MeasurementTemplate(['columns' => 7]));
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new MeasurementTemplate(['version' => '1.0', 'columns' => 7]),
        );
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new MeasurementTemplate(['version' => '1.0.0', 'columns' => 1, 'sections' => []]),
        );
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new MeasurementTemplate([
                'version' => '1.0.0',
                'columns' => 7,
                'sections' => [['label' => '', 'groups' => [['label' => 'A', 'rows' => [['label' => 'B']]]]]],
            ]),
        );
        $this->assertThrows(
            InvalidArgumentException::class,
            fn () => new MeasurementTemplate([
                'version' => '1.0.0',
                'columns' => 7,
                'sections' => [['label' => 'A', 'groups' => [['label' => 'G', 'rows' => [['label' => 'R', 'sources' => [['ids' => []]]]]]]]],
            ]),
        );
    }

    public function testConfigFileIsVersionedAndAsciiSafe(): void
    {
        $path = self::ROOT . MeasurementTemplate::FILE;
        $this->assertTrue(is_file($path), 'Vorlagendatei fehlt: ' . $path);
        $config = require $path;
        $this->assertTrue(is_array($config), 'Vorlagendatei liefert kein Array.');
        $this->assertSame(MeasurementTemplate::FILE, '/config/patient_card_measurements.php');
        // Der PDF-Zeichensatz kennt kein Hoch-1: Einheiten deshalb ASCII-sicher schreiben.
        $this->assertNotContains("\u{207B}", (string) file_get_contents($path));
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<string>
     */
    private function cells(array $sections, string $section, string $group, string $label, string $chamber = ''): array
    {
        foreach ($sections as $candidate) {
            if ($candidate['label'] !== $section) {
                continue;
            }
            foreach ($candidate['groups'] as $candidateGroup) {
                if ($candidateGroup['label'] !== $group) {
                    continue;
                }
                foreach ($candidateGroup['rows'] as $row) {
                    if ($row['label'] === $label && $row['chamber'] === $chamber) {
                        return $row['values'];
                    }
                }
            }
        }
        $this->fail(sprintf('Zeile "%s" in %s/%s nicht gefunden.', $label, $section, $group));
    }
}
