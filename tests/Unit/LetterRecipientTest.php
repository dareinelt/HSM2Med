<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Letter\LetterInput;
use App\Letter\LetterPdfGenerator;
use App\Letter\LetterRecipient;
use App\Letter\LetterTemplate;
use DateTimeImmutable;
use Tests\Support\LetterFactory;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * Briefempfaenger: Patient, Hausarzt und ueberweisender Arzt mit Anschrift aus den Stammdaten.
 */
final class LetterRecipientTest extends TestCase
{
    /** @var array<string, string> */
    private const array PATIENT = ['first_name' => 'Erika', 'last_name' => 'Mustermann', 'patient_name' => 'Mustermann, Erika'];

    /** @var array<string, string> */
    private const array MASTER = [
        'street' => 'Musterstraße 12',
        'postal_code' => '12345',
        'city' => 'Beispielstadt',
        'physician_name' => 'Dr. med. Anna Weber',
        'physician_practice' => 'Hausarztpraxis am Markt',
        'physician_street' => 'Marktplatz 3',
        'physician_postal_code' => '54321',
        'physician_city' => 'Hausarztstadt',
        'referrer_name' => 'Dr. med. Jonas Klein',
        'referrer_postal_code' => '50667',
        'referrer_city' => '',
    ];

    public function testResolvesAddressLinesInDinOrder(): void
    {
        $all = LetterRecipient::all(self::PATIENT, self::MASTER);
        $this->assertSame(LetterRecipient::TYPES, array_keys($all));

        $this->assertSame(['Erika Mustermann', 'Musterstraße 12', '12345 Beispielstadt'], $all['patient']['lines']);
        $this->assertTrue($all['patient']['available']);

        $doctor = $all['family_doctor'];
        $this->assertSame('Hausarzt', $doctor['label']);
        $this->assertSame(['Hausarztpraxis am Markt', 'Dr. med. Anna Weber', 'Marktplatz 3', '54321 Hausarztstadt'], $doctor['lines']);
        $this->assertTrue($doctor['available']);
        $this->assertSame('Hausarzt: Dr. med. Anna Weber, Hausarztpraxis am Markt', LetterRecipient::listLabel('family_doctor', LetterRecipient::displayName($doctor)));
    }

    public function testIncompleteAddressIsNotAvailable(): void
    {
        $referrer = LetterRecipient::resolve('referring_physician', self::PATIENT, self::MASTER);
        $this->assertFalse($referrer['available']);
        $this->assertSame(['Ort'], $referrer['missing']);

        $empty = LetterRecipient::resolve('family_doctor', self::PATIENT, []);
        $this->assertSame(['Name oder Praxis', 'Postleitzahl', 'Ort'], $empty['missing']);
        $this->assertSame('laut Vorlage', LetterRecipient::listLabel(null, null));
    }

    public function testInputKeepsKnownRecipientsInFixedOrder(): void
    {
        $input = LetterInput::fromPost([
            'patient_id' => '7',
            'recipients' => ['referring_physician', 'unbekannt', 'patient', ['x']],
        ]);
        $this->assertSame(['patient', 'referring_physician'], $input->recipients);
        $this->assertSame([], LetterInput::fromPost(['patient_id' => '7', 'recipients' => 'patient'])->recipients);
    }

    /** Der eingefrorene Empfaenger ersetzt den festen Empfaengertext der Vorlage. */
    public function testPdfPrintsFrozenRecipient(): void
    {
        $template = LetterTemplate::default();
        $snapshot = LetterFactory::snapshot(['letter_version' => 2, 'letter_template_version' => '1']);
        $snapshot['template'] = ['version_id' => 1, 'version_no' => 1, 'name' => $template['name'], 'content_sha256' => '', 'content' => $template];
        $at = new DateTimeImmutable('2026-10-07 09:15:00', new \DateTimeZone('Europe/Berlin'));

        $fallback = PdfText::text((new LetterPdfGenerator())->generate($snapshot, null, $at));
        $this->assertContains('weiterbehandelnden', $fallback, 'Ohne Empfaenger gilt die Vorlage.');

        $snapshot['recipient'] = LetterRecipient::snapshotPart(LetterRecipient::resolve('family_doctor', self::PATIENT, self::MASTER));
        $text = PdfText::text((new LetterPdfGenerator())->generate($snapshot, null, $at));
        foreach (['Hausarztpraxis am Markt', 'Dr. med. Anna Weber', 'Marktplatz 3', '54321 Hausarztstadt'] as $needle) {
            $this->assertContains($needle, $text);
        }
        $this->assertNotContains('weiterbehandelnden', $text);
    }
}
