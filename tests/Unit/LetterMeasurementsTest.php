<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterMeasurements;
use Tests\TestCase;

/**
 * Messwerttabelle des Befundbriefes: Spaltenauswahl (aktuelle Untersuchung ganz rechts),
 * Beschraenkung auf die in der Vorlage eingestellte Anzahl frueherer Untersuchungen und die
 * Auswahl der Messwerte aus der Checkbox-Liste.
 */
final class LetterMeasurementsTest extends TestCase
{
    /**
     * Aufgeloeste Tabelle mit sechs Untersuchungen; die letzte ist die aktuelle.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function resolved(array $overrides = []): array
    {
        return $overrides + [
            'include' => true,
            'present' => true,
            'template_version' => '1.0',
            'previous_count' => LetterMeasurements::MAX_PREVIOUS,
            'column_count' => 6,
            'columns' => [
                ['report_id' => 11, 'date' => '2024-01-05', 'date_display' => '05.01.2024', 'current' => false],
                ['report_id' => 12, 'date' => '2024-06-11', 'date_display' => '11.06.2024', 'current' => false],
                ['report_id' => 13, 'date' => '2025-01-09', 'date_display' => '09.01.2025', 'current' => false],
                ['report_id' => 14, 'date' => '2025-08-21', 'date_display' => '21.08.2025', 'current' => false],
                ['report_id' => 15, 'date' => '2026-04-02', 'date_display' => '02.04.2026', 'current' => false],
                ['report_id' => 16, 'date' => '2026-10-07', 'date_display' => '07.10.2026', 'current' => true],
            ],
            'sections' => [
                [
                    'key' => '0',
                    'label' => 'Sondenmessung',
                    'groups' => [
                        [
                            'key' => '0.0',
                            'label' => 'Vorhofsonde (RA)',
                            'rows' => [
                                [
                                    'key' => '0.0.0',
                                    'label' => 'Reizschwelle',
                                    'chamber' => 'RA',
                                    'values' => ['0,9 V', '0,8 V', '0,7 V', '0,6 V', '0,5 V', '0,5 V'],
                                    'available' => true,
                                ],
                                [
                                    'key' => '0.0.1',
                                    'label' => 'Impedanz',
                                    'chamber' => 'RA',
                                    'values' => ['', '', '', '', '', ''],
                                    'available' => false,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => '1',
                    'label' => 'Batterie',
                    'groups' => [
                        [
                            'key' => '1.0',
                            'label' => '',
                            'rows' => [
                                [
                                    'key' => '1.0.0',
                                    'label' => 'Restlaufzeit',
                                    'chamber' => '',
                                    'values' => ['48 Monate', '', '30 Monate', '', '12 Monate', ''],
                                    'available' => true,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'key' => '2',
                    'label' => 'Nur alte Untersuchung',
                    'groups' => [
                        [
                            'key' => '2.0',
                            'label' => '',
                            'rows' => [
                                [
                                    'key' => '2.0.0',
                                    'label' => 'Damalige Sondenimpedanz',
                                    'chamber' => '',
                                    'values' => ['640 Ohm', '', '', '', '', ''],
                                    'available' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'available' => ['0.0.0', '1.0.0', '2.0.0'],
            'selected' => ['0.0.0', '1.0.0', '2.0.0'],
        ];
    }

    /** @param array<string, mixed> $part */
    private function rowKeys(array $part): array
    {
        $keys = [];
        foreach ((array) ($part['sections'] ?? []) as $section) {
            foreach ((array) ($section['groups'] ?? []) as $group) {
                foreach ((array) ($group['rows'] ?? []) as $row) {
                    $keys[] = (string) $row['key'];
                }
            }
        }
        return $keys;
    }

    /** Die Anzahl frueherer Untersuchungen stammt aus der Vorlage und ist begrenzt. */
    public function testPreviousCountReadsTemplateOption(): void
    {
        $template = static fn (mixed $value): array => [
            'zones' => ['measurement_table' => ['options' => ['previous' => $value]]],
        ];

        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount(null));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount([]));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount(['zones' => []]));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount($template(null)));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount($template('viele')));
        $this->assertSame(4, LetterMeasurements::DEFAULT_PREVIOUS);

        $this->assertSame(2, LetterMeasurements::previousCount($template('2')));
        $this->assertSame(2, LetterMeasurements::previousCount($template(2)));
        $this->assertSame(0, LetterMeasurements::previousCount($template('0')));
        $this->assertSame(LetterMeasurements::MAX_PREVIOUS, LetterMeasurements::previousCount($template('99')));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount($template('-1')));
        $this->assertSame(LetterMeasurements::DEFAULT_PREVIOUS, LetterMeasurements::previousCount($template('2.5')));
    }

    /** Die aktuelle Untersuchung bleibt ganz rechts, links stehen die letzten frueheren. */
    public function testFreezeKeepsCurrentColumnRightmost(): void
    {
        $part = LetterMeasurements::freeze($this->resolved(), 2, null, true);

        $this->assertTrue($part['include']);
        $this->assertTrue($part['present']);
        $this->assertSame(2, $part['previous_count']);
        $this->assertSame(3, $part['column_count']);
        $this->assertCount(3, $part['columns']);
        $this->assertSame([14, 15, 16], array_column($part['columns'], 'report_id'));
        $this->assertSame('07.10.2026', $part['columns'][2]['date_display']);
        $this->assertTrue($part['columns'][2]['current']);
        $this->assertFalse($part['columns'][0]['current']);
    }

    /** Nur die angewaehlten Messwerte erscheinen; Zeilen ohne Wert entfallen. */
    public function testFreezeAppliesSelection(): void
    {
        $part = LetterMeasurements::freeze($this->resolved(), 2, ['0.0.0', '0.0.1'], true);

        $this->assertSame(['0.0.0'], $this->rowKeys($part), 'Nicht angewaehlte und wertlose Zeilen entfallen.');
        $this->assertSame(['0.0.0'], $part['selected']);
        $this->assertSame(['0,6 V', '0,5 V', '0,5 V'], $part['sections'][0]['groups'][0]['rows'][0]['values']);
        $this->assertCount(1, $part['sections'], 'Leere Abschnitte werden nicht gedruckt.');
    }

    /** Ohne Auswahl (Formular ohne Checkbox-Liste) erscheinen alle verfuegbaren Messwerte. */
    public function testFreezeWithoutSelectionKeepsAllAvailableValues(): void
    {
        $part = LetterMeasurements::freeze($this->resolved(), 4, null, true);

        $this->assertSame(['0.0.0', '1.0.0'], $this->rowKeys($part), 'Die Zeile mit Werten nur in alten Spalten entfaellt.');
        $this->assertSame(['0.0.0', '1.0.0'], $part['selected']);
        $this->assertSame(['', '30 Monate', '', '12 Monate', ''], $part['sections'][1]['groups'][0]['rows'][0]['values']);
        $this->assertSame(5, $part['column_count']);
        $this->assertSame([12, 13, 14, 15, 16], array_column($part['columns'], 'report_id'));
    }

    /** Der Anwender kann die Messwerttabelle fuer den Brief abwaehlen. */
    public function testFreezeMarksTableAsNotChosen(): void
    {
        $part = LetterMeasurements::freeze($this->resolved(), 2, null, false);

        $this->assertFalse($part['include']);
        $this->assertFalse($part['present']);
        $this->assertSame([], $part['sections']);
        $this->assertSame([], $part['columns']);
        $this->assertSame(0, $part['column_count']);
        $this->assertSame(['0.0.0', '1.0.0', '2.0.0'], $part['available'], 'Die Auswahl bleibt fuer die Anzeige erhalten.');
    }

    /** Eine leere Auswahl oder eine Tabelle ohne Werte ergibt eine gueltige, leere Tabelle. */
    public function testFreezeWithoutValuesYieldsEmptyPart(): void
    {
        $empty = LetterMeasurements::freeze($this->resolved(), 2, [], true);
        $this->assertTrue($empty['include']);
        $this->assertFalse($empty['present']);
        $this->assertSame([], $this->rowKeys($empty));

        $nothing = LetterMeasurements::freeze([
            'present' => false,
            'template_version' => '1.0',
            'columns' => [],
            'sections' => [],
            'available' => [],
        ], 4, null, true);
        $this->assertTrue($nothing['include']);
        $this->assertFalse($nothing['present']);
        $this->assertSame(4, $nothing['previous_count']);
    }

    /** Die Anzahl frueherer Untersuchungen wird auf den Bereich der Vorlage begrenzt. */
    public function testFreezeClampsPreviousCount(): void
    {
        $this->assertSame(
            6,
            LetterMeasurements::freeze($this->resolved(), 99, null, true)['column_count'],
            'Mehr Spalten als Untersuchungen gibt es nicht.',
        );
        $this->assertSame(1, LetterMeasurements::freeze($this->resolved(), 0, null, true)['column_count']);
        $this->assertSame(1, LetterMeasurements::freeze($this->resolved(), -3, null, true)['column_count']);
        $this->assertSame(
            LetterMeasurements::MAX_PREVIOUS,
            LetterMeasurements::freeze($this->resolved(), 99, null, true)['previous_count'],
        );
    }

    /** Die Checkbox-Liste nennt je Zeile die Verfuegbarkeit und die Werte der Untersuchungen. */
    public function testChoicesListRowsWithAvailability(): void
    {
        $choices = LetterMeasurements::choices($this->resolved());

        $this->assertCount(4, $choices);
        $this->assertSame(['0.0.0', '0.0.1', '1.0.0', '2.0.0'], array_column($choices, 'key'));
        $this->assertSame('Reizschwelle', $choices[0]['label']);
        $this->assertTrue($choices[0]['available']);
        $this->assertSame(['0,9 V', '0,8 V', '0,7 V', '0,6 V', '0,5 V', '0,5 V'], $choices[0]['values']);
        $this->assertFalse($choices[1]['available'], 'Ohne Wert in den Untersuchungen ist die Zeile nicht waehlbar.');
        $this->assertSame([], LetterMeasurements::choices([]));
    }
}
