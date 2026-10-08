<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Patient\DeviceCheckTemplate;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Vorlage der Schrittmacher-/ICD-Abfrage: Geraetearten, Abschnitte, Felder und Quellen.
 *
 * Die Vorlage ist die einzige Stelle, an der der Wunschkatalog des aerztlichen Dienstes steht.
 * Die Tests sichern, dass die geforderten Angaben vorhanden sind und dass die Abschnitte je
 * Geraeteart korrekt eingeschraenkt werden.
 */
final class DeviceCheckTemplateTest extends TestCase
{
    private DeviceCheckTemplate $template;

    public function setUp(): void
    {
        parent::setUp();
        $this->template = DeviceCheckTemplate::default(dirname(__DIR__, 2));
    }

    /** Die vier Geraetearten des Wunschkatalogs sind vorhanden. */
    public function testDeviceTypes(): void
    {
        $this->assertSame(
            ['pacemaker' => 'Schrittmacher', 'icd' => 'ICD', 'crt_p' => 'CRT-P', 'crt_d' => 'CRT-D'],
            $this->template->deviceTypes(),
        );
        $this->assertTrue($this->template->hasDeviceType('crt_d'));
        $this->assertFalse($this->template->hasDeviceType('unbekannt'));
        $this->assertSame('CRT-D', $this->template->deviceTypeLabel('crt_d'));
        $this->assertSame('unbekannt', $this->template->deviceTypeLabel('unbekannt'));
        $this->assertSame('1.0.0', $this->template->version());
    }

    /** Grenzen der Vorlage. */
    public function testLimits(): void
    {
        $this->assertSame(12, $this->template->maxLeads());
        $this->assertSame(4000, $this->template->maxNotes());
        $this->assertTrue($this->template->maxValue() > 0);
    }

    /** Die Abschnitte des Wunschkatalogs sind in der vorgegebenen Reihenfolge vorhanden. */
    public function testSectionOrder(): void
    {
        $keys = array_column($this->template->sectionsFor('crt_d'), 'key');

        $this->assertSame(
            ['device', 'leads', 'battery', 'brady', 'ra', 'rv', 'av', 'lv', 'tachy'],
            $keys,
        );
    }

    /** Abschnitte, die nur fuer bestimmte Geraetearten gelten, werden eingeschraenkt. */
    public function testSectionsAreLimitedByDeviceType(): void
    {
        $pacemaker = array_column($this->template->sectionsFor('pacemaker'), 'key');
        $icd = array_column($this->template->sectionsFor('icd'), 'key');
        $crtD = array_column($this->template->sectionsFor('crt_d'), 'key');

        $this->assertTrue(!in_array('tachy', $pacemaker, true), 'Tachykardie gehoert nicht zum Schrittmacher.');
        $this->assertTrue(!in_array('lv', $pacemaker, true), 'Die LV-Sonde gehoert nicht zum Schrittmacher.');
        $this->assertTrue(in_array('tachy', $icd, true), 'Tachykardie fehlt beim ICD.');
        $this->assertTrue(!in_array('lv', $icd, true), 'Der ICD hat keine LV-Sonde.');
        $this->assertTrue(in_array('lv', $crtD, true), 'Die LV-Sonde fehlt beim CRT-D.');
        $this->assertTrue(in_array('tachy', $crtD, true), 'Tachykardie fehlt beim CRT-D.');
    }

    /** Der Wunschkatalog der Bradykardie-Programmierung ist vollstaendig. */
    public function testBradyFields(): void
    {
        $fields = $this->template->fields('pacemaker');
        $labels = array_map(static fn (array $field): string => $field['label'], $fields);

        foreach ([
            'brady.mode' => 'Betriebsart',
            'brady.lower_rate' => 'Untere Grenzfrequenz (1/min)',
            'brady.hysteresis_rate' => 'Hysteresefrequenz (1/min)',
            'brady.max_sync_rate' => 'max. Synch.frequenz (1/min)',
            'brady.max_sensor_rate' => 'max. Sensorfrequenz (1/min)',
            'brady.pmt' => 'PMT-Intervention',
            'brady.rate_response' => 'R-Funktion',
        ] as $key => $label) {
            $this->assertTrue(isset($fields[$key]), 'Feld fehlt: ' . $key);
            $this->assertSame($label, $fields[$key]['label']);
        }
        foreach (['Refraktärzeit (PVARP) (ms)', 'Stim. AV-Intervall (ms)', 'ModeSwitch Frequenz (1/min)'] as $label) {
            $this->assertTrue(in_array($label, $labels, true), 'Beschriftung fehlt: ' . $label);
        }
    }

    /** Erkennung und Therapie sind je Tachykardie-Zone vorhanden. */
    public function testTachyGroups(): void
    {
        $fields = $this->template->fields('icd');

        foreach (['vt1', 'vt2', 'vf'] as $zone) {
            foreach (['rate', 'cycle_length', 'therapy'] as $name) {
                $this->assertTrue(isset($fields['tachy.' . $zone . '.' . $name]), 'Feld fehlt: tachy.' . $zone . '.' . $name);
            }
        }
        $this->assertSame('Erkennung: Frequenz (1/min)', $fields['tachy.vf.rate']['label']);
        $this->assertSame('Therapie: Maßnahmen', $fields['tachy.vf.therapy']['label']);
    }

    /** Die Schockimpedanz gibt es nur bei ICD und CRT-D. */
    public function testShockImpedanceOnlyForIcd(): void
    {
        $pacemaker = array_column($this->template->leadFields('pacemaker'), 'name');
        $icd = array_column($this->template->leadFields('icd'), 'name');
        $crtD = array_column($this->template->leadFields('crt_d'), 'name');

        $this->assertTrue(!in_array('shock_impedance', $pacemaker, true), 'Der Schrittmacher kennt keine Schockimpedanz.');
        $this->assertTrue(in_array('shock_impedance', $icd, true), 'Schockimpedanz fehlt beim ICD.');
        $this->assertTrue(in_array('shock_impedance', $crtD, true), 'Schockimpedanz fehlt beim CRT-D.');
        foreach (['model', 'location', 'implant_date', 'impedance', 'sensing', 'threshold'] as $name) {
            $this->assertTrue(in_array($name, $pacemaker, true), 'Sondenfeld fehlt: ' . $name);
        }
    }

    /** Auswahlfelder und Datumsfelder sind als solche gekennzeichnet. */
    public function testFieldTypesAndOptions(): void
    {
        $fields = $this->template->fields('icd');

        $this->assertSame('date', $fields['device.implant_date']['type']);
        $this->assertSame(['MRT-tauglich', 'MRT-bedingt tauglich', 'nicht MRT-tauglich', 'unbekannt'], $fields['device.mrt_compatibility']['options']);
        $this->assertSame('mrt_compatibility', $fields['device.mrt_compatibility']['from_card']);
        $this->assertSame('device.Seriennummer', $fields['device.serial']['from_summary']);
        $this->assertSame(120, $fields['device.mrt_compatibility_note']['maxlength'], 'Die Zusatzangabe entspricht der Grenze des Ausweises.');
        $this->assertSame('mrt_compatibility_note', $fields['device.mrt_compatibility_note']['from_card']);
    }

    /** Das Formular erhaelt je Feld den vollstaendigen Pfad im Inhalt der Fassung. */
    public function testFormSectionsCarryFullPaths(): void
    {
        $sections = $this->template->formSections();
        $paths = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] as $field) {
                $paths[] = $field['path'];
            }
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $paths[] = $field['path'];
                }
            }
        }

        foreach (['device.manufacturer', 'brady.mode', 'tachy.vf.therapy'] as $path) {
            $this->assertTrue(in_array($path, $paths, true), 'Feldpfad fehlt im Formular: ' . $path);
        }
        $this->assertSame(array_values(array_unique($paths)), $paths, 'Pfade sind eindeutig.');

        $all = $this->template->allFields();
        foreach ($paths as $path) {
            $this->assertTrue(isset($all[$path]), 'Unbekannter Feldpfad im Formular: ' . $path);
        }
    }

    /** Alle Abschnitte des Formulars sind ohne JavaScript sichtbar (Ausblenden erfolgt im Browser). */
    public function testFormSectionsContainAllDeviceTypes(): void
    {
        $keys = array_column($this->template->formSections(), 'key');

        $this->assertSame(['device', 'leads', 'battery', 'brady', 'ra', 'rv', 'av', 'lv', 'tachy'], $keys);
        $lv = array_values(array_filter($this->template->formSections(), static fn (array $s): bool => $s['key'] === 'lv'))[0];
        $this->assertSame(['crt_p', 'crt_d'], $lv['devices']);
    }

    /** Quellen fuer die Vorbelegung sind gebuendelt abfragbar. */
    public function testParameterSources(): void
    {
        $this->assertTrue(in_array('301', array_map('strval', $this->template->parameterIds()), true));
        $this->assertTrue(in_array('mode', $this->template->parameterNames(), true));
        $this->assertTrue(in_array('longevity estimate', $this->template->parameterNames(), true), 'Bezeichnungen werden kleingeschrieben gesucht.');
        $this->assertSame(array_values(array_unique($this->template->parameterIds())), $this->template->parameterIds());
        $this->assertSame(array_values(array_unique($this->template->parameterNames())), $this->template->parameterNames());
    }

    /** Fehlerhafte Vorlagen werden beim Laden abgelehnt. */
    public function testInvalidTemplateIsRejected(): void
    {
        foreach ([
            ['version' => '1.0.0'],
            ['version' => '1.0', 'device_types' => ['pacemaker' => 'Schrittmacher']],
            ['version' => '1.0.0', 'device_types' => ['pacemaker' => ''], 'lead_fields' => [['key' => 'model', 'label' => 'Modell']], 'sections' => [['key' => 'device', 'label' => 'Gerät', 'fields' => [['key' => 'model', 'label' => 'Modell']]]]],
            ['version' => '1.0.0', 'device_types' => ['pacemaker' => 'Schrittmacher'], 'lead_fields' => [], 'sections' => [['key' => 'device', 'label' => 'Gerät', 'fields' => [['key' => 'model', 'label' => 'Modell']]]]],
            ['version' => '1.0.0', 'device_types' => ['pacemaker' => 'Schrittmacher'], 'lead_fields' => [['key' => 'model', 'label' => 'Modell']], 'sections' => [['key' => 'device', 'label' => 'Gerät', 'fields' => []]]],
            ['version' => '1.0.0', 'device_types' => ['pacemaker' => 'Schrittmacher'], 'lead_fields' => [['key' => 'model', 'label' => 'Modell']], 'sections' => [['key' => 'device', 'label' => 'Gerät', 'fields' => [['key' => 'model', 'label' => 'Modell']]], ['key' => 'tachy', 'label' => 'Tachykardie', 'devices' => ['icd'], 'fields' => [['key' => 'rate', 'label' => 'Frequenz']]]]],
        ] as $index => $config) {
            $this->assertThrows(
                InvalidArgumentException::class,
                static fn (): DeviceCheckTemplate => new DeviceCheckTemplate($config),
                'Vorlage ' . $index . ' wurde nicht abgelehnt.',
            );
        }
    }
}
