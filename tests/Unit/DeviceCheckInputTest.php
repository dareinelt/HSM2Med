<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Patient\DeviceCheckInput;
use App\Patient\DeviceCheckTemplate;
use Tests\TestCase;

/**
 * Pruefung und Normalisierung der Eingaben des Bausteins "Schrittmacher-/ICD-Abfrage".
 *
 * Geprueft wird, dass der Geraetetyp den zulaessigen Umfang bestimmt, dass nichts erfunden
 * oder stillschweigend verworfen wird und dass gespeicherte Fassungen eine Textfassung haben.
 */
final class DeviceCheckInputTest extends TestCase
{
    private DeviceCheckTemplate $template;

    public function setUp(): void
    {
        parent::setUp();
        $this->template = DeviceCheckTemplate::default(dirname(__DIR__, 2));
    }

    /** Vollstaendige Eingabe wird uebernommen und mit der Vorlagenfassung gespeichert. */
    public function testNormalizeStoresValuesLeadsAndNotes(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => [
                'device.manufacturer' => ' Medtronic ',
                'device.mrt_compatibility' => 'MRT-tauglich',
                'brady.mode' => 'DDD',
                'ra.output' => '1,5/0,4',
            ],
            'leads' => [
                ['model' => '5076-52', 'location' => 'RA', 'implant_date' => '15.01.2020', 'impedance' => '620'],
            ],
            'notes' => "Kontrolle ohne Auffälligkeit.",
        ], $this->template, $errors);

        $this->assertSame([], $errors);
        $this->assertSame('1.0.0', $content['template']);
        $this->assertSame('pacemaker', $content['device_type']);
        $this->assertSame('Medtronic', $content['values']['device.manufacturer'], 'Werte werden von Leerraum befreit.');
        $this->assertSame('DDD', $content['values']['brady.mode']);
        $this->assertSame('2020-01-15', $content['leads'][0]['implant_date'], 'Datumsangaben werden als ISO-Datum gespeichert.');
        $this->assertSame('620', $content['leads'][0]['impedance']);
        $this->assertSame('Kontrolle ohne Auffälligkeit.', $content['notes']);
    }

    /** Ohne gueltige Geraeteart gibt es keinen Inhalt. */
    public function testDeviceTypeIsRequired(): void
    {
        $errors = [];
        $this->assertNull(DeviceCheckInput::normalize(['values' => ['brady.mode' => 'DDD']], $this->template, $errors));
        $this->assertSame('Bitte die Art des Geräts wählen.', $errors['device_type']);

        $errors = [];
        $this->assertNull(DeviceCheckInput::normalize(['device_type' => 'sonstiges'], $this->template, $errors));
        $this->assertTrue(isset($errors['device_type']));
    }

    /** Angaben, die zur Geraeteart nicht gehoeren, werden benannt und nicht gespeichert. */
    public function testUnexpectedValuesAreReported(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => [
                'brady.mode' => 'DDD',
                'tachy.vt1.rate' => '180',
                'lv.impedance' => '700',
            ],
            'leads' => [['model' => 'Sonde', 'shock_impedance' => '45']],
        ], $this->template, $errors);

        $this->assertTrue(isset($errors['device_type']));
        $this->assertContains('Erkennung: Frequenz (1/min)', $errors['device_type']);
        $this->assertContains('Impedanz (Ohm)', $errors['device_type']);
        $this->assertContains('Schockimpedanz (Ohm)', $errors['device_type']);
        $this->assertTrue(!isset($content['values']['tachy.vt1.rate']), 'Nicht vorgesehene Werte werden nicht gespeichert.');
        $this->assertTrue(!isset($content['values']['lv.impedance']));
        $this->assertTrue(!isset($content['leads'][0]['shock_impedance']));
        $this->assertSame('DDD', $content['values']['brady.mode']);
    }

    /** Beim ICD sind Tachykardie und Schockimpedanz zulaessig. */
    public function testIcdAcceptsTachyAndShockImpedance(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'icd',
            'values' => ['tachy.vf.therapy' => 'Schock 35 J'],
            'leads' => [['model' => 'Sprint Quattro', 'shock_impedance' => '45']],
        ], $this->template, $errors);

        $this->assertSame([], $errors);
        $this->assertSame('Schock 35 J', $content['values']['tachy.vf.therapy']);
        $this->assertSame('45', $content['leads'][0]['shock_impedance']);
    }

    /** Auswahlfelder akzeptieren nur die vorgesehenen Werte. */
    public function testOptionsAreValidated(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => ['brady.mode' => 'DDD', 'device.mrt_compatibility' => 'vielleicht'],
        ], $this->template, $errors);

        $this->assertContains('MRT-Tauglichkeit', $errors['values']);
        $this->assertContains('vielleicht', $errors['values']);
        $this->assertTrue(!isset($content['values']['device.mrt_compatibility']));
        $this->assertSame('DDD', $content['values']['brady.mode']);

        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'leads' => [['model' => 'Sonde', 'location' => 'XX']],
        ], $this->template, $errors);
        $this->assertContains('Lokalisation', $errors['leads']);
        $this->assertSame('', $content['leads'][0]['location'], 'Der unzulaessige Wert wird nicht gespeichert.');
    }

    /** Ungueltige Datumsangaben werden benannt und nicht gespeichert. */
    public function testInvalidDatesAreReported(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => ['device.implant_date' => 'kein Datum', 'brady.mode' => 'DDD'],
        ], $this->template, $errors);

        $this->assertContains('Implantationsdatum', $errors['values']);
        $this->assertTrue(!isset($content['values']['device.implant_date']));

        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'leads' => [['model' => 'Sonde', 'implant_date' => '31.02.2020']],
        ], $this->template, $errors);
        $this->assertContains('Implantationsdatum', $errors['leads']);
        $this->assertSame('', $content['leads'][0]['implant_date'], 'Das ungueltige Datum wird nicht gespeichert.');
    }

    /** Feldwerte sind begrenzt; die Ueberlaenge wird gekuerzt und benannt. */
    public function testValueLengthIsLimited(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => ['brady.mode' => str_repeat('A', 150)],
        ], $this->template, $errors);

        $this->assertContains('Betriebsart', $errors['values']);
        $this->assertSame($this->template->maxValue(), mb_strlen($content['values']['brady.mode']));
    }

    /** Leere Sondenzeilen entfallen, die Anzahl ist begrenzt. */
    public function testLeadRows(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'leads' => [
                ['model' => '', 'location' => '', 'impedance' => ''],
                ['model' => '5076-52', 'location' => 'RA'],
            ],
        ], $this->template, $errors);

        $this->assertSame([], $errors);
        $this->assertCount(1, $content['leads'], 'Leere Sondenzeilen werden verworfen.');

        $errors = [];
        $rows = [];
        for ($i = 0; $i < $this->template->maxLeads() + 1; $i++) {
            $rows[] = ['model' => 'Sonde ' . $i];
        }
        $content = DeviceCheckInput::normalize(['device_type' => 'pacemaker', 'leads' => $rows], $this->template, $errors);

        $this->assertContains('Höchstens ' . $this->template->maxLeads() . ' Sonden je Fassung.', $errors['leads']);
        $this->assertCount($this->template->maxLeads(), $content['leads']);
    }

    /** Eine Abfrage ohne jede Angabe wird nicht gespeichert. */
    public function testEmptyInputIsRejected(): void
    {
        $errors = [];
        DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'values' => ['brady.mode' => '   '],
            'leads' => [['model' => '']],
            'notes' => '',
        ], $this->template, $errors);

        $this->assertSame('Bitte mindestens eine Angabe zur Abfrage erfassen.', $errors['values']);
    }

    /** Bemerkungen sind begrenzt und werden auf eine Zeile gebracht. */
    public function testNotesAreLimited(): void
    {
        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'notes' => "Erste Zeile\r\nZweite Zeile",
        ], $this->template, $errors);

        $this->assertSame("Erste Zeile\nZweite Zeile", $content['notes']);

        $errors = [];
        $content = DeviceCheckInput::normalize([
            'device_type' => 'pacemaker',
            'notes' => str_repeat('B', $this->template->maxNotes() + 10),
        ], $this->template, $errors);

        $this->assertContains('Höchstens ' . $this->template->maxNotes() . ' Zeichen erlaubt.', $errors['notes']);
        $this->assertSame($this->template->maxNotes(), mb_strlen($content['notes']));
    }

    /** Anzeigewerte: Datumsangaben in deutscher Schreibweise, Umfang der Abfrage. */
    public function testDescribe(): void
    {
        $described = DeviceCheckInput::describe([
            'device_type' => 'icd',
            'values' => [
                'device.implant_date' => '2020-01-15',
                'brady.mode' => 'VVI',
                'lv.impedance' => '700',
            ],
            'leads' => [['model' => 'Sonde', 'implant_date' => '2019-12-24', 'impedance' => '']],
            'notes' => 'Bemerkung',
        ], $this->template);

        $this->assertSame('ICD', $described['device_type_label']);
        $this->assertSame('15.01.2020', $described['values']['device.implant_date']);
        $this->assertSame('VVI', $described['values']['brady.mode']);
        $this->assertTrue(!isset($described['values']['lv.impedance']), 'Werte der falschen Geraeteart werden nicht angezeigt.');
        $this->assertSame('24.12.2019', $described['leads'][0]['implant_date']);
        $this->assertTrue(!isset($described['leads'][0]['impedance']));
        $this->assertSame(4, $described['filled'], 'Zwei Werte und zwei Sondenangaben.');
        $this->assertSame('Bemerkung', $described['notes']);
    }

    /** Unbekannte Geraeteart: keine Anzeige, keine Textfassung. */
    public function testDescribeWithoutValidDeviceType(): void
    {
        $described = DeviceCheckInput::describe(['device_type' => 'sonstiges', 'values' => ['brady.mode' => 'DDD']], $this->template);

        $this->assertSame('', $described['device_type_label']);
        $this->assertSame([], $described['values']);
        $this->assertSame('', DeviceCheckInput::renderText(['device_type' => 'sonstiges'], $this->template));
    }

    /** Die Textfassung nennt Abschnitte, Beschriftungen und Bemerkungen. */
    public function testRenderText(): void
    {
        $text = DeviceCheckInput::renderText([
            'device_type' => 'crt_d',
            'values' => [
                'device.manufacturer' => 'Biotronik',
                'tachy.vf.therapy' => 'Schock 35 J',
                'lv.output' => '3,0/0,4',
            ],
            'leads' => [
                ['model' => 'Sonde RA', 'location' => 'RA'],
                ['model' => 'Sonde LV', 'location' => 'LV', 'impedance' => '700'],
            ],
            'notes' => "Zeile eins\nZeile zwei",
        ], $this->template);

        $this->assertContains('Abfrage: CRT-D', $text);
        $this->assertContains('Gerät · Hersteller: Biotronik', $text);
        $this->assertContains('Sonden (Elektroden) · Sonde 1 · Lokalisation: RA', $text);
        $this->assertContains('Sonden (Elektroden) · Sonde 2 · Impedanz (Ohm): 700', $text);
        $this->assertContains('Tachykardie VF · Therapie: Maßnahmen: Schock 35 J', $text);
        $this->assertContains('LV (linksventrikuläre Sonde) · Output (V/ms): 3,0/0,4', $text);
        $this->assertContains('Bemerkungen: Zeile eins Zeile zwei', $text);
    }
}
