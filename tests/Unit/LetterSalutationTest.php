<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterRecipient;
use App\Letter\LetterSalutation;
use Tests\TestCase;

/**
 * Anrede je Empfaengerart: Auswahl, Stammdatenfeld und fertiger Anredetext.
 */
final class LetterSalutationTest extends TestCase
{
    public function testPatientSalutationUsesLastName(): void
    {
        $this->assertSame('Sehr geehrter Herr Mustermann,', LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::HERR, 'Mustermann'));
        $this->assertSame('Sehr geehrte Frau Mustermann,', LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::FRAU, 'Mustermann'));
        $this->assertSame(
            'Guten Tag Mustermann, Erika,',
            LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::DIVERS, 'Mustermann', 'Erika'),
        );
        $this->assertSame('Guten Tag Mustermann,', LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::DIVERS, 'Mustermann'));
    }

    public function testPhysicianSalutationWithoutLastName(): void
    {
        foreach ([LetterRecipient::FAMILY_DOCTOR, LetterRecipient::REFERRING_PHYSICIAN] as $type) {
            $this->assertSame('Sehr geehrter Herr Kollege,', LetterSalutation::text($type, LetterSalutation::KOLLEGE));
            $this->assertSame('Sehr geehrte Frau Kollegin,', LetterSalutation::text($type, LetterSalutation::KOLLEGIN));
            $this->assertSame(
                LetterSalutation::FALLBACK,
                LetterSalutation::text($type, LetterSalutation::UNPERSOENLICH),
                'Praxen und Kliniken werden unpersoenlich angesprochen.',
            );
            $this->assertSame(LetterSalutation::FALLBACK, LetterSalutation::text($type, ''), 'Ohne Angabe gilt der Rueckfall.');
            $this->assertSame(LetterSalutation::FALLBACK, LetterSalutation::text($type, 'unbekannt'));
        }
    }

    public function testMissingNameFallsBackToGenericSalutation(): void
    {
        $this->assertSame(LetterSalutation::FALLBACK, LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::FRAU));
        $this->assertSame(LetterSalutation::FALLBACK, LetterSalutation::text(LetterRecipient::PATIENT, ''));
        $this->assertSame(LetterSalutation::FALLBACK, LetterSalutation::text(LetterRecipient::PATIENT, LetterSalutation::DIVERS));
    }

    public function testFieldAndChoicesPerRecipientType(): void
    {
        $this->assertSame('salutation', LetterSalutation::field(LetterRecipient::PATIENT));
        $this->assertSame('physician_salutation', LetterSalutation::field(LetterRecipient::FAMILY_DOCTOR));
        $this->assertSame('referrer_salutation', LetterSalutation::field(LetterRecipient::REFERRING_PHYSICIAN));
        $this->assertSame('salutation', LetterSalutation::field('unbekannt'), 'Unbekannte Arten verwenden die Patientenfelder.');

        $this->assertSame(['herr', 'frau', 'divers'], LetterSalutation::values(LetterRecipient::PATIENT));
        $this->assertSame(['kollege', 'kollegin', 'unpersoenlich'], LetterSalutation::values(LetterRecipient::FAMILY_DOCTOR));
        $this->assertSame(LetterSalutation::values(LetterRecipient::FAMILY_DOCTOR), LetterSalutation::values(LetterRecipient::REFERRING_PHYSICIAN));
        $this->assertFalse(in_array('unpersoenlich', LetterSalutation::values(LetterRecipient::PATIENT), true), 'Unpersoenlich gibt es nur bei Praxen und Kliniken.');
        $this->assertFalse(in_array('', LetterSalutation::values(LetterRecipient::PATIENT), true));
        foreach (LetterRecipient::TYPES as $type) {
            $this->assertFalse(in_array('', LetterSalutation::choices($type), true));
            $this->assertTrue(LetterSalutation::hint($type) !== '');
        }
    }

    public function testFromMasterReadsOnlyKnownValues(): void
    {
        $this->assertSame('frau', LetterSalutation::fromMaster(LetterRecipient::PATIENT, ['salutation' => 'frau']));
        $this->assertSame('frau', LetterSalutation::fromMaster(LetterRecipient::PATIENT, ['salutation' => '  frau ']));
        $this->assertSame('', LetterSalutation::fromMaster(LetterRecipient::PATIENT, ['salutation' => 'kollegin']), 'Werte der anderen Art gelten nicht.');
        $this->assertSame('', LetterSalutation::fromMaster(LetterRecipient::PATIENT, []));
        $this->assertSame('', LetterSalutation::fromMaster(LetterRecipient::PATIENT, ['salutation' => null]));
        $this->assertSame('unpersoenlich', LetterSalutation::fromMaster(LetterRecipient::FAMILY_DOCTOR, ['physician_salutation' => 'unpersoenlich']));
    }

    /**
     * Der generische Arztbrief hat keine Anrede in den Stammdaten: Feld, Auswahl und Anredetext
     * sind fest vorgegeben.
     */
    public function testGenericLetterHasAFixedSalutation(): void
    {
        $this->assertSame('Sehr geehrte Kollegin, sehr geehrter Kollege,', LetterSalutation::GENERIC);
        $this->assertSame(LetterSalutation::GENERIC, LetterSalutation::text(LetterRecipient::GENERIC, ''));
        $this->assertSame(
            LetterSalutation::GENERIC,
            LetterSalutation::text(LetterRecipient::GENERIC, LetterSalutation::KOLLEGE, 'Mustermann', 'Erika'),
            'Die Stammdaten der Aerzte und der Name des Patienten bleiben unberuecksichtigt.',
        );

        $this->assertSame('', LetterSalutation::field(LetterRecipient::GENERIC));
        $this->assertSame([], LetterSalutation::choices(LetterRecipient::GENERIC));
        $this->assertSame([], LetterSalutation::values(LetterRecipient::GENERIC));
        $this->assertSame('', LetterSalutation::fromMaster(LetterRecipient::GENERIC, ['referrer_salutation' => 'kollege']));
        $this->assertContains('Sehr geehrte Kollegin, sehr geehrter Kollege,', LetterSalutation::hint(LetterRecipient::GENERIC));
    }
}
