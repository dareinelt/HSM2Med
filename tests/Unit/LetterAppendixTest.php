<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\DeviceCheckAppendix;
use App\Patient\DeviceCheckInput;
use App\Patient\DeviceCheckTemplate;
use Tests\TestCase;

/**
 * Der Anhang des Briefes bildet den Wunschkatalog des aerztlichen Dienstes ab: je Geraeteart
 * nur die zutreffenden Abschnitte (Tachykardie nur bei ICD/CRT-D, LV-Sonde nur bei CRT-D,
 * Schockimpedanz nur bei ICD/CRT-D) und die MRT-Angabe aus dem Patientenausweis.
 */
final class LetterAppendixTest extends TestCase
{
    private DeviceCheckTemplate $template;

    public function setUp(): void
    {
        $this->template = DeviceCheckTemplate::default(dirname(__DIR__, 2));
    }

    private function appendix(array $content, ?array $mrt = null): array
    {
        $described = DeviceCheckInput::describe($content, $this->template);
        return (new DeviceCheckAppendix($this->template))->build($described, $mrt);
    }

    /** @return list<string> */
    private function labels(array $appendix): array
    {
        $labels = [];
        foreach ($appendix['sections'] as $section) {
            $labels[] = $section['label'];
            foreach ($section['rows'] as $row) {
                $labels[] = $row['label'];
            }
        }
        return $labels;
    }

    /** @return list<string> */
    private function values(array $appendix): array
    {
        $values = [];
        foreach ($appendix['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $values[] = $row['value'];
            }
        }
        return $values;
    }

    /** @return array<string, mixed> */
    private function content(string $deviceType, array $overrides = []): array
    {
        return $overrides + [
            'device_type' => $deviceType,
            'values' => [],
            'leads' => [],
            'notes' => '',
        ];
    }

    /** Test 1: Einkammer-Schrittmacher – Grundabschnitte, keine Tachykardie, keine LV-Sonde. */
    public function testPacemakerHasNoTachycardiaSection(): void
    {
        $appendix = $this->appendix($this->content('pacemaker', [
            'values' => [
                'device.manufacturer' => 'Beispielhersteller',
                'device.model' => 'PM-1000',
                'battery.status' => 'ERI',
                'brady.mode' => 'AAI',
                'ra.sensitivity' => '0,5',
            ],
            'leads' => [['model' => 'LEAD-1', 'location' => 'RA', 'impedance' => '512']],
        ]));

        $this->assertSame('pacemaker', $appendix['device_type']);
        $this->assertSame('Schrittmacher', $appendix['device_type_label']);

        $labels = $this->labels($appendix);
        foreach (['Gerät', 'Sonden (Elektroden)', 'Batterie', 'Programmierung Bradykardie', 'RA (Vorhofsonde)'] as $needle) {
            $this->assertTrue(in_array($needle, $labels, true), sprintf('Abschnitt "%s" fehlt.', $needle));
        }
        $this->assertTrue(!in_array('Tachykardie', $labels, true), 'Ein Schrittmacher hat keinen Abschnitt Tachykardie.');
        $this->assertTrue(!in_array('LV (linksventrikuläre Sonde)', $labels, true), 'Ein Schrittmacher hat keine LV-Sonde.');

        $rows = $this->values($appendix);
        $this->assertTrue(!in_array('Sonde 1 – Schockimpedanz (Ohm)', $labels, true), 'Ohne ICD gibt es keine Schockimpedanz.');
        $this->assertTrue(in_array('ERI', $rows, true));
        $this->assertTrue(in_array('AAI', $rows, true));
    }

    /** Test 2: ICD – Tachykardie mit VT1/VT2/VF und Schockimpedanz an der Sonde. */
    public function testIcdHasTachycardiaAndShockImpedance(): void
    {
        $appendix = $this->appendix($this->content('icd', [
            'values' => [
                'tachy.vt1.rate' => '170',
                'tachy.vt1.cycle_length' => '350',
                'tachy.vt1.therapy' => 'ATP, 2 x 35 J',
                'tachy.vt2.rate' => '200',
                'tachy.vt2.cycle_length' => '300',
                'tachy.vt2.therapy' => 'Schock 35 J',
                'tachy.vf.rate' => '250',
                'tachy.vf.therapy' => 'Schock 40 J',
            ],
            'leads' => [[
                'model' => 'LEAD-RV-1',
                'location' => 'RV',
                'impedance' => '640',
                'shock_impedance' => '48',
            ]],
        ]));

        $labels = $this->labels($appendix);
        $this->assertTrue(in_array('Tachykardie', $labels, true), 'Der ICD braucht den Abschnitt Tachykardie.');
        $this->assertTrue(in_array('Sonde 1 – Schockimpedanz (Ohm)', $labels, true), 'Der ICD braucht die Schockimpedanz.');
        $this->assertTrue(!in_array('LV (linksventrikuläre Sonde)', $labels, true), 'Ein ICD hat keine LV-Sonde.');

        foreach (['VT1 – Erkennung: Frequenz (1/min)', 'VT1 – Therapie: Maßnahmen', 'VT2 – Erkennung: Zykluslänge (ms)', 'VF – Therapie: Maßnahmen'] as $needle) {
            $this->assertTrue(in_array($needle, $labels, true), sprintf('"%s" fehlt im Tachykardie-Abschnitt.', $needle));
        }
        // VT1/VT2/VF stehen in dieser Reihenfolge.
        $vt1 = array_search('VT1 – Therapie: Maßnahmen', $labels, true);
        $vt2 = array_search('VT2 – Therapie: Maßnahmen', $labels, true);
        $vf = array_search('VF – Therapie: Maßnahmen', $labels, true);
        $this->assertTrue($vt1 !== false && $vt2 !== false && $vf !== false && $vt1 < $vt2 && $vt2 < $vf, 'VT1, VT2 und VF stehen in der Reihenfolge des Wunschkatalogs.');

        $rows = $this->values($appendix);
        foreach (['ATP, 2 x 35 J', 'Schock 35 J', 'Schock 40 J', '48', '300'] as $needle) {
            $this->assertTrue(in_array($needle, $rows, true), sprintf('Wert "%s" fehlt.', $needle));
        }
    }

    /** Test 3: CRT-D – zusaetzlich LV-Sonde und deren Programmierung. */
    public function testCrtDeviceHasLeftVentricularSection(): void
    {
        $appendix = $this->appendix($this->content('crt_d', [
            'values' => [
                'lv.sensing' => '8,5',
                'lv.threshold' => '1,2',
                'lv.impedance' => '780',
                'lv.output' => '3,0/0,5',
                'lv.sensitivity' => '1,0',
                'lv.sense_polarity' => 'bipolar',
                'lv.pace_polarity' => 'LV1 – RV',
                'tachy.vf.rate' => '250',
            ],
            'leads' => [[
                'model' => 'LEAD-LV-1',
                'location' => 'LV',
                'impedance' => '780',
                'shock_impedance' => '52',
            ]],
        ]));

        $labels = $this->labels($appendix);
        foreach ([
            'LV (linksventrikuläre Sonde)',
            'Wahrnehmung (mV)',
            'Reizschwelle (V/ms)',
            'Output (V/ms)',
            'Wahrnehmungspolarität',
            'Stimulationspolarität',
            'Sonde 1 – Schockimpedanz (Ohm)',
            'Tachykardie',
        ] as $needle) {
            $this->assertTrue(in_array($needle, $labels, true), sprintf('"%s" fehlt beim CRT-D.', $needle));
        }

        $rows = $this->values($appendix);
        foreach (['8,5', '1,2', '780', '3,0/0,5', '1,0', 'LV1 – RV', '52'] as $needle) {
            $this->assertTrue(in_array($needle, $rows, true), sprintf('Wert "%s" fehlt.', $needle));
        }
    }

    /** Test 4: CRT-P hat keine Tachykardie, aber die LV-Sonde. */
    public function testCrtPacemakerHasLeftVentricularSectionWithoutTachycardia(): void
    {
        $appendix = $this->appendix($this->content('crt_p', [
            'values' => ['lv.threshold' => '1,5'],
        ]));

        $labels = $this->labels($appendix);
        $this->assertTrue(in_array('LV (linksventrikuläre Sonde)', $labels, true));
        $this->assertTrue(!in_array('Tachykardie', $labels, true), 'Ein CRT-P hat keinen Defibrillator.');
        $this->assertTrue(!in_array('Sonde 1 – Schockimpedanz (Ohm)', $labels, true));
    }

    /** Test 5: Die MRT-Angabe des Ausweises ueberschreibt die Abfrage und nennt ihre Quelle. */
    public function testMrtFromCardIsReadOnlyAndLabelled(): void
    {
        $appendix = $this->appendix(
            $this->content('pacemaker', ['values' => ['device.mrt_compatibility' => 'nicht MRT-tauglich']]),
            ['value' => 'MRT-bedingt tauglich', 'note' => 'Nur Thorax ohne MRT.', 'source_label' => 'Patientenausweis Nr. 3'],
        );

        $rows = [];
        foreach ($appendix['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                $rows[$row['label']] = $row['value'];
            }
        }

        $this->assertSame('MRT-bedingt tauglich', $rows['MRT-Tauglichkeit (Patientenausweis Nr. 3)']);
        $this->assertSame('Nur Thorax ohne MRT.', $rows['MRT-Tauglichkeit: Zusatzangabe (Patientenausweis Nr. 3)']);
        $this->assertTrue(!in_array('nicht MRT-tauglich', $this->values($appendix), true), 'Der Ausweis hat Vorrang vor der Abfrage.');
    }

    /** Test 6: Ohne Ausweisangabe bleibt die MRT-Tauglichkeit der Abfrage unveraendert. */
    public function testMrtWithoutCardKeepsDeviceCheckValue(): void
    {
        $appendix = $this->appendix($this->content('pacemaker', [
            'values' => ['device.mrt_compatibility' => 'MRT-tauglich'],
        ]));

        $this->assertTrue(in_array('MRT-tauglich', $this->values($appendix), true));
        $this->assertTrue(in_array('MRT-Tauglichkeit', $this->labels($appendix), true));
    }

    /** Test 7: Ohne Geraeteart bleibt der Anhang leer, statt falsche Angaben zu erfinden. */
    public function testUnknownDeviceTypeProducesEmptyAppendix(): void
    {
        foreach (['', 'unbekannt'] as $deviceType) {
            $appendix = $this->appendix($this->content($deviceType, ['values' => ['battery.status' => 'ERI']]));

            $this->assertSame($deviceType, $appendix['device_type']);
            $this->assertSame('', $appendix['device_type_label']);
            $this->assertSame([], $appendix['sections']);
            $this->assertSame('', $appendix['notes']);
            $this->assertSame(0, $appendix['filled']);
        }
    }

    /** Test 8: Leere Felder werden weggelassen, "filled" zaehlt nur die angezeigten Zeilen. */
    public function testEmptyValuesAreOmitted(): void
    {
        $appendix = $this->appendix($this->content('pacemaker', [
            'values' => ['battery.status' => 'ERI', 'battery.longevity' => '   ', 'brady.mode' => ''],
        ]));

        $labels = $this->labels($appendix);
        $this->assertTrue(in_array('Status', $labels, true));
        $this->assertTrue(!in_array('Verbleibende Laufzeit', $labels, true));
        $this->assertTrue(!in_array('Betriebsart', $labels, true));

        $rows = 0;
        foreach ($appendix['sections'] as $section) {
            $rows += count($section['rows']);
        }
        $this->assertSame($rows, $appendix['filled']);
        $this->assertSame(1, $appendix['filled']);
    }

    /** Test 9: Die Textfassung (Browseranzeige) folgt derselben Tabelle. */
    public function testLinesRenderSectionsRowsAndNotes(): void
    {
        $appendix = $this->appendix($this->content('pacemaker', [
            'values' => ['battery.status' => 'ERI'],
            'notes' => "Kontrolle in 3 Monaten.\nSondenmessung ohne Auffälligkeit.",
        ]));

        $lines = DeviceCheckAppendix::lines($appendix);
        $this->assertTrue(in_array('Batterie', $lines, true));
        $this->assertTrue(in_array('  Status: ERI', $lines, true));
        $this->assertTrue(in_array('Bemerkungen', $lines, true));
        $this->assertTrue(in_array('  Kontrolle in 3 Monaten.', $lines, true));
        $this->assertTrue(in_array('  Sondenmessung ohne Auffälligkeit.', $lines, true));
        $this->assertSame(
            '  Kontrolle in 3 Monaten.' . "\n" . '  Sondenmessung ohne Auffälligkeit.',
            implode("\n", array_slice($lines, -2)),
        );
    }
}
