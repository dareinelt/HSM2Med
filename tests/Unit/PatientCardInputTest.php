<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\PatientCard\PatientCardException;
use App\PatientCard\PatientCardInput;
use Tests\TestCase;

/**
 * Serverseitige Pruefung der Assistenteneingaben.
 */
final class PatientCardInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'last_name' => 'LASTNAME',
            'first_name' => 'FIRSTNAME',
            'date_of_birth' => '21.10.1938',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '+49 (0)1234/56789',
            'indication' => 'Bradykardie',
            'device_implant_location' => 'links pektoral',
            'emergency_contact_name' => 'Angehörige Beispielperson',
            'emergency_contact_phone' => '0170 1234567',
            'physician_name' => 'Dr. med. Hausarzt',
            'physician_practice' => 'Gemeinschaftspraxis am Markt',
            'physician_postal_code' => '12345',
            'physician_city' => 'Beispielstadt',
            'physician_phone' => '01234-11111',
            'control_physician' => 'Dr. med. Kontrolle',
            'next_control_date' => '07.04.2027',
            'confirm_patient' => '1',
            'confirm_merge' => '1',
        ], $overrides);
    }

    public function testAcceptsCompleteInput(): void
    {
        $input = PatientCardInput::fromPost($this->post());

        $this->assertSame('LASTNAME', $input->lastName);
        $this->assertSame('FIRSTNAME', $input->firstName);
        $this->assertSame('1938-10-21', $input->dateOfBirth);
        $this->assertSame('21.10.1938', $input->dateOfBirthRaw, 'Das eingegebene Rohformat bleibt erhalten');
        $this->assertSame('2027-04-07', $input->nextControlDate);
        $this->assertSame('LASTNAME, FIRSTNAME', $input->displayName());
        $this->assertSame('lastname|firstname|1938-10-21', $input->identityKey());
        $this->assertSame('Musterstraße 12', $input->value('street'));
        $this->assertSame(['patient' => true, 'merge' => true], $input->confirmations());
        $this->assertSame('2027-04-07', $input->allValues()['next_control_date']);
    }

    public function testRequiresLastNameFirstNameAndDateOfBirth(): void
    {
        foreach ([
            'last_name' => 'last_name',
            'first_name' => 'first_name',
            'date_of_birth' => 'date_of_birth',
        ] as $postKey => $field) {
            $exception = $this->assertThrows(
                PatientCardException::class,
                fn () => PatientCardInput::fromPost($this->post([$postKey => '   '])),
            );
            $this->assertTrue(isset($exception->fieldErrors()[$field]), "Feldfehler fuer {$field} erwartet");
        }
    }

    public function testRejectsInvalidDateOfBirth(): void
    {
        foreach (['31.02.1938', '1938-13-01', 'unbekannt', '21/10/1938 00:00:00'] as $value) {
            $exception = $this->assertThrows(
                PatientCardException::class,
                fn () => PatientCardInput::fromPost($this->post(['date_of_birth' => $value])),
                "Ungueltiges Datum {$value}",
            );
            $this->assertContains('ungueltig', $exception->fieldErrors()['date_of_birth']);
        }
    }

    public function testRejectsFutureAndImplausibleDateOfBirth(): void
    {
        $future = (new \DateTimeImmutable('+2 days'))->format('d.m.Y');
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['date_of_birth' => $future])),
        );
        $this->assertContains('Zukunft', $exception->fieldErrors()['date_of_birth']);

        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['date_of_birth' => '01.01.1899'])),
        );
        $this->assertContains('1900', $exception->fieldErrors()['date_of_birth']);
    }

    public function testAcceptsAlternativeDateFormats(): void
    {
        foreach (['1938-10-21', '21/10/1938', '21-10-1938'] as $value) {
            $input = PatientCardInput::fromPost($this->post(['date_of_birth' => $value]));
            $this->assertSame('1938-10-21', $input->dateOfBirth, "Format {$value}");
            $this->assertSame($value === '1938-10-21' ? null : $value, $input->dateOfBirthRaw);
        }
    }

    public function testRejectsOverlongValues(): void
    {
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['city' => str_repeat('a', 256)])),
        );
        $this->assertContains('255', $exception->fieldErrors()['city']);

        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['indication' => str_repeat('a', 2001)])),
        );
        $this->assertContains('2000', $exception->fieldErrors()['indication']);
    }

    public function testRejectsInvalidPhoneAndPostalCode(): void
    {
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['phone' => 'kein Telefon #1'])),
        );
        $this->assertTrue(isset($exception->fieldErrors()['phone']));

        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['physician_postal_code' => '12§45'])),
        );
        $this->assertTrue(isset($exception->fieldErrors()['physician_postal_code']));

        // Internationale Schreibweisen bleiben erlaubt
        $input = PatientCardInput::fromPost($this->post(['phone' => '+43 1 234 56 78-9/0']));
        $this->assertSame('+43 1 234 56 78-9/0', $input->value('phone'));
    }

    public function testRejectsInvalidControlDate(): void
    {
        $exception = $this->assertThrows(
            PatientCardException::class,
            fn () => PatientCardInput::fromPost($this->post(['next_control_date' => 'irgendwann'])),
        );
        $this->assertTrue(isset($exception->fieldErrors()['next_control_date']));

        $input = PatientCardInput::fromPost($this->post(['next_control_date' => '']));
        $this->assertNull($input->nextControlDate);
        $this->assertSame('', $input->allValues()['next_control_date']);
    }

    public function testStripsControlCharacters(): void
    {
        $input = PatientCardInput::fromPost($this->post(['street' => "Muster\x00straße\x1F 12"]));
        $this->assertSame('Musterstraße 12', $input->value('street'));
    }

    public function testFiltersConflictChoices(): void
    {
        $input = PatientCardInput::fromPost($this->post(['conflict' => [
            'street' => 'stored',
            'city' => 'new',
            'unbekannt' => 'stored',
            'phone' => 'geloescht',
        ]]));
        $this->assertSame(['street' => 'stored', 'city' => 'new'], $input->conflictChoices);

        $input = PatientCardInput::fromPost($this->post(['conflict' => 'kein array']));
        $this->assertSame([], $input->conflictChoices);
    }

    public function testParsesSelectedPatientId(): void
    {
        $this->assertSame(42, PatientCardInput::fromPost($this->post(['patient_id' => '42']))->selectedPatientId);
        $this->assertNull(PatientCardInput::fromPost($this->post(['patient_id' => '0']))->selectedPatientId);
        $this->assertNull(PatientCardInput::fromPost($this->post(['patient_id' => '-3']))->selectedPatientId);
        $this->assertNull(PatientCardInput::fromPost($this->post(['patient_id' => 'abc']))->selectedPatientId);
        $this->assertNull(PatientCardInput::fromPost($this->post(['patient_id' => '']))->selectedPatientId);
    }

    public function testConfirmationsDefaultToFalse(): void
    {
        $input = PatientCardInput::fromPost($this->post(['confirm_patient' => '', 'confirm_merge' => '0']));
        $this->assertSame(['patient' => false, 'merge' => false], $input->confirmations());
    }

    public function testDateHelpers(): void
    {
        $this->assertSame('1938-10-21', PatientCardInput::parseDate('21.10.1938'));
        $this->assertNull(PatientCardInput::parseDate(''));
        $this->assertNull(PatientCardInput::parseDate('32.01.2020'));
        $this->assertSame('21.10.1938', PatientCardInput::formatDate('1938-10-21'));
        $this->assertSame('21.10.1938', PatientCardInput::formatDate('21.10.1938'));
        $this->assertSame('', PatientCardInput::formatDate(null));
        $this->assertSame('', PatientCardInput::formatDate(''));
        $this->assertSame('unbekannt', PatientCardInput::formatDate('unbekannt'));
    }

    public function testFieldLabelsCoverAllMergeFields(): void
    {
        $labels = \App\PatientCard\PatientCardService::fieldLabels();
        foreach (PatientCardInput::MERGE_FIELDS as $field) {
            $this->assertTrue(isset($labels[$field]), "Bezeichnung fuer {$field} fehlt");
        }
        foreach (PatientCardInput::TEXT_FIELDS as $field => $max) {
            $this->assertTrue($max > 0, "Maximallaenge fuer {$field} fehlt");
        }
    }
}
