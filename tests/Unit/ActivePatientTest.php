<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use App\Config\Config;
use App\Patient\ActivePatient;
use Tests\TestCase;

/**
 * Der Patientenvorgang ist fuehrend: der aktive Patient wird je Sitzung gehalten.
 *
 * Geprueft wird die Sitzungslogik ohne Datenbank – die Aufloesung des Patienten
 * selbst deckt der Integrationstest der Patientenakte ab.
 */
final class ActivePatientTest extends TestCase
{
    public function setUp(): void
    {
        $_SESSION = [];
    }

    public function tearDown(): void
    {
        $_SESSION = [];
    }

    /** Ohne Auswahl ist kein Patient aktiv. */
    public function testStartsWithoutSelection(): void
    {
        $active = new ActivePatient();

        $this->assertNull($active->id());
        $this->assertFalse($active->isSelected());
    }

    /** Auswahl und Aufheben wirken auf die Sitzung. */
    public function testSelectAndClear(): void
    {
        $active = new ActivePatient();

        $active->select(42);
        $this->assertSame(42, $active->id());
        $this->assertTrue($active->isSelected());

        // Eine zweite Instanz liest denselben Sitzungswert.
        $this->assertSame(42, (new ActivePatient())->id());

        $active->clear();
        $this->assertNull($active->id());
        $this->assertFalse($active->isSelected());
    }

    /** Ungueltige Kennungen werden nicht uebernommen. */
    public function testRejectsNonPositiveIds(): void
    {
        $active = new ActivePatient();

        foreach ([0, -1] as $invalid) {
            $active->select($invalid);
            $this->assertNull($active->id());
        }
    }

    /** Beschaedigte Sitzungswerte gelten als "kein Patient gewaehlt". */
    public function testIgnoresInvalidStoredValues(): void
    {
        $active = new ActivePatient();

        foreach (['', 'abc', '0', '-5', '1.5', [], null] as $invalid) {
            $_SESSION['active_patient_id'] = $invalid;
            $this->assertNull($active->id());
            $this->assertFalse($active->isSelected());
        }
    }

    /** Numerische Zeichenketten (z. B. nach session_decode) werden akzeptiert. */
    public function testAcceptsNumericString(): void
    {
        $_SESSION['active_patient_id'] = '7';

        $this->assertSame(7, (new ActivePatient())->id());
    }

    /** Der Service-Container haelt genau eine Instanz je Anfrage. */
    public function testApplicationProvidesSingleInstance(): void
    {
        $app = new Application(Config::fromEnvironment([
            'DB_HOST' => 'localhost',
            'DB_DATABASE' => 'hsm2med_test',
        ]), dirname(__DIR__, 2));

        $this->assertSame($app->activePatient(), $app->activePatient());

        $app->activePatient()->select(5);
        $this->assertSame(5, $app->activePatient()->id());
    }
}
