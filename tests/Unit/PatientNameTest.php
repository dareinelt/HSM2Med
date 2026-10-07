<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\PatientCard\PatientName;
use Tests\TestCase;

/**
 * Patientenidentitaet: Nachname + Vorname + Geburtsdatum.
 */
final class PatientNameTest extends TestCase
{
    public function testNormalize(): void
    {
        $this->assertNull(PatientName::normalize(null));
        $this->assertNull(PatientName::normalize(''));
        $this->assertNull(PatientName::normalize('   '));
        $this->assertNull(PatientName::normalize("\xC2\xA0"));
        $this->assertSame('LASTNAME', PatientName::normalize('  LASTNAME  '));
        $this->assertSame('Müller-Lüdenscheidt', PatientName::normalize('Müller-Lüdenscheidt'));
        // Mehrfachleerzeichen und geschuetzte Leerzeichen werden vereinheitlicht
        $this->assertSame('Anna Maria', PatientName::normalize("Anna\xC2\xA0 \t Maria"));
        $this->assertSame('Anna Maria', PatientName::normalize("Anna\xE2\x80\x8BMaria"));
    }

    public function testSplit(): void
    {
        $this->assertSame(['last' => 'LASTNAME', 'first' => 'FIRSTNAME'], PatientName::split('LASTNAME, FIRSTNAME'));
        $this->assertSame(['last' => 'LASTNAME', 'first' => 'FIRSTNAME'], PatientName::split('  LASTNAME ,   FIRSTNAME  '));
        $this->assertSame(['last' => 'LASTNAME', 'first' => 'ANNA MARIA'], PatientName::split('LASTNAME,ANNA MARIA'));
        // Ohne Komma gilt der gesamte Wert als Nachname; der Vorname fehlt und muss ergaenzt werden
        $this->assertSame(['last' => 'LASTNAME', 'first' => null], PatientName::split('LASTNAME'));
        $this->assertSame(['last' => 'LASTNAME', 'first' => null], PatientName::split('LASTNAME,'));
        $this->assertSame(['last' => null, 'first' => null], PatientName::split(null));
        $this->assertSame(['last' => null, 'first' => null], PatientName::split('   '));
    }

    public function testDisplay(): void
    {
        $this->assertSame('LASTNAME, FIRSTNAME', PatientName::display('LASTNAME', 'FIRSTNAME'));
        $this->assertSame('LASTNAME, FIRSTNAME', PatientName::display(' LASTNAME ', ' FIRSTNAME '));
        $this->assertSame('LASTNAME', PatientName::display('LASTNAME', null));
        $this->assertSame('LASTNAME', PatientName::display('LASTNAME', ''));
        $this->assertSame('FIRSTNAME', PatientName::display(null, 'FIRSTNAME'));
        $this->assertSame('', PatientName::display(null, null));
    }

    public function testIdentityKeyIgnoresCaseAndWhitespace(): void
    {
        $key = PatientName::identityKey('LASTNAME', 'FIRSTNAME', '1938-10-21');
        $this->assertSame('lastname|firstname|1938-10-21', $key);
        $this->assertSame($key, PatientName::identityKey('lastname', 'firstname', '1938-10-21'));
        $this->assertSame($key, PatientName::identityKey('  LastName ', ' FirstName ', '1938-10-21'), 'Gross-/Kleinschreibung und Leerzeichen sind unerheblich');
        $this->assertNotSame($key, PatientName::identityKey('LASTNAME', 'FIRSTNAME', '1938-10-22'), 'Geburtsdatum ist Teil der Identitaet');
        $this->assertNotSame($key, PatientName::identityKey('LASTNAMES', 'FIRSTNAME', '1938-10-21'));
        $this->assertNotSame($key, PatientName::identityKey('LASTNAME', 'FIRSTNAME2', '1938-10-21'));
    }

    public function testIdentityKeyRequiresAllThreeParts(): void
    {
        $this->assertNull(PatientName::identityKey(null, 'FIRSTNAME', '1938-10-21'));
        $this->assertNull(PatientName::identityKey('LASTNAME', null, '1938-10-21'));
        $this->assertNull(PatientName::identityKey('LASTNAME', 'FIRSTNAME', null));
        $this->assertNull(PatientName::identityKey('LASTNAME', 'FIRSTNAME', ''));
        $this->assertNull(PatientName::identityKey('', '', ''));
    }
}
