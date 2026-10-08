<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Patient\PatientException;
use App\Patient\PatientInput;
use App\Support\DateInput;
use Tests\TestCase;

/**
 * Validierung der Patientenstammdaten (Patientenakte).
 */
final class PatientInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function post(array $overrides = []): array
    {
        return array_replace([
            'last_name' => 'Mustermann',
            'first_name' => 'Erika',
            'date_of_birth' => '21.10.1938',
            'patient_identifier' => 'P-123',
            'street' => 'Musterstraße 12',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'phone' => '01234/56789',
            'indication' => 'Bradykardie',
        ], $overrides);
    }

    public function testValidInputNormalizesDateAndName(): void
    {
        $input = PatientInput::fromPost($this->post());

        $this->assertSame('1938-10-21', $input->dateOfBirth);
        $this->assertSame('21.10.1938', $input->dateOfBirthRaw, 'Abweichende Schreibweise wird als Rohtext gefuehrt.');
        $this->assertSame('Mustermann, Erika', $input->displayName());
        $this->assertSame('P-123', $input->patientIdentifier);
        $this->assertSame('12345', $input->value('postal_code'));
        $this->assertSame('mustermann|erika|1938-10-21', $input->identityKey());
    }

    public function testIsoDateNeedsNoRawValue(): void
    {
        $input = PatientInput::fromPost($this->post(['date_of_birth' => '1938-10-21']));

        $this->assertSame('1938-10-21', $input->dateOfBirth);
        $this->assertNull($input->dateOfBirthRaw, 'ISO-Schreibweise erzeugt keinen Rohtext.');
    }

    public function testRawDateIsKeptWhenNotIso(): void
    {
        $input = PatientInput::fromPost($this->post(['date_of_birth' => '21/10/1938']));

        $this->assertSame('1938-10-21', $input->dateOfBirth);
        $this->assertSame('21/10/1938', $input->dateOfBirthRaw);
        $this->assertSame('21.10.1938', $input->allValues()['date_of_birth']);
    }

    public function testRequiredFieldsAreRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientInput::fromPost($this->post(['last_name' => '  ', 'first_name' => '', 'date_of_birth' => ''])),
        );
        $errors = $e->fieldErrors();

        $this->assertTrue(isset($errors['last_name']), 'Nachname muss bemängelt werden.');
        $this->assertTrue(isset($errors['first_name']), 'Vorname muss bemängelt werden.');
        $this->assertTrue(isset($errors['date_of_birth']), 'Geburtsdatum muss bemängelt werden.');
    }

    public function testInvalidDateIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientInput::fromPost($this->post(['date_of_birth' => '31.02.1938'])),
        );

        $this->assertTrue(isset($e->fieldErrors()['date_of_birth']));
    }

    public function testFutureDateIsRejected(): void
    {
        $future = (new \DateTimeImmutable('+1 day'))->format('d.m.Y');
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientInput::fromPost($this->post(['date_of_birth' => $future])),
        );

        $this->assertContains('Zukunft', (string) $e->fieldErrors()['date_of_birth']);
    }

    public function testTooLongIdentifierIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientInput::fromPost($this->post(['patient_identifier' => str_repeat('A', PatientInput::MAX_IDENTIFIER + 1)])),
        );

        $this->assertTrue(isset($e->fieldErrors()['patient_identifier']));
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $e = $this->assertThrows(
            PatientException::class,
            fn () => PatientInput::fromPost($this->post(['phone' => 'kein Telefon'])),
        );

        $this->assertTrue(isset($e->fieldErrors()['phone']));
    }

    public function testControlCharactersAreRemoved(): void
    {
        $input = PatientInput::fromPost($this->post(['city' => "Beispiel\u{0007}stadt"]));

        $this->assertSame('Beispielstadt', $input->value('city'));
    }

    public function testConfirmDuplicateFlagIsReadFromPost(): void
    {
        $this->assertFalse(PatientInput::fromPost($this->post())->confirmDuplicate);
        $this->assertTrue(PatientInput::fromPost($this->post(['confirm_duplicate' => '1']))->confirmDuplicate);
        $this->assertFalse(PatientInput::fromPost($this->post(['confirm_duplicate' => 'yes']))->confirmDuplicate);
    }

    public function testEmptyOptionalValuesStayEmpty(): void
    {
        $input = PatientInput::fromPost($this->post(['street' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'indication' => '', 'patient_identifier' => '']));

        $this->assertNull($input->patientIdentifier, 'Leere Patienten-ID wird nicht gespeichert.');
        $this->assertSame(
            ['street' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'indication' => ''],
            $input->masterValues(),
        );
    }

    public function testDateInputRoundTrip(): void
    {
        $this->assertSame('2026-10-07', DateInput::parse('07.10.2026'));
        $this->assertSame('07.10.2026', DateInput::format('2026-10-07'));
        $this->assertNull(DateInput::parse(''));
        $this->assertFalse(DateInput::isPlausible('1899-12-31'));
        $this->assertTrue(DateInput::isPlausible('1900-01-01'));
    }
}
