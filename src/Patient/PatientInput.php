<?php

declare(strict_types=1);

namespace App\Patient;

use App\Letter\LetterRecipient;
use App\Letter\LetterSalutation;
use App\PatientCard\PatientName;
use App\Support\DateInput;

/**
 * Gepruefte und normalisierte Eingaben der Patientenstammdaten (Patientenakte).
 *
 * Die Identitaet eines Patienten ist Nachname + Vorname + Geburtsdatum – identisch zum
 * Assistenten des Patientenausweises (patients.identity_key). Die Patienten-ID ist eine
 * Zusatzangabe und darf die Identitaet nicht allein bestimmen.
 *
 * Alle Felder werden serverseitig validiert; die Anzeige der Fehler erfolgt feldbezogen.
 * Leere Werte sind erlaubt (die Datenbank speichert sie als ''), Pflichtfelder sind
 * Nachname, Vorname und Geburtsdatum. Hausarzt und ueberweisender Arzt liefern die Anschrift
 * fuer Briefe an diese Empfaenger.
 *
 * Die Anrede des Briefes wird ebenfalls hier gepflegt – je Empfaengerart getrennt (siehe
 * LetterSalutation). Sie ist freiwillig; ohne Angabe druckt der Brief "Sehr geehrte Damen
 * und Herren,".
 */
final class PatientInput
{
    /** Freitextfelder der Stammdaten: Feld => Maximallaenge. */
    public const array TEXT_FIELDS = [
        'street' => 255,
        'postal_code' => 32,
        'city' => 255,
        'phone' => 64,
        'indication' => 2000,
        'physician_name' => 255,
        'physician_practice' => 255,
        'physician_street' => 255,
        'physician_postal_code' => 32,
        'physician_city' => 255,
        'physician_phone' => 64,
        'referrer_name' => 255,
        'referrer_practice' => 255,
        'referrer_street' => 255,
        'referrer_postal_code' => 32,
        'referrer_city' => 255,
        'referrer_phone' => 64,
    ];

    /** Felder mit Telefonnummer bzw. Postleitzahl (gleiche Pruefung wie beim Patienten). */
    private const array PHONE_FIELDS = ['phone', 'physician_phone', 'referrer_phone'];
    private const array POSTAL_FIELDS = ['postal_code', 'physician_postal_code', 'referrer_postal_code'];

    public const int MAX_IDENTIFIER = 191;
    public const int MAX_NAME = 255;

    /**
     * Auswahlfelder der Anrede: Feldname => Empfaengerart. Empfaengerarten ohne Anrede in den
     * Stammdaten (generischer Arztbrief) haben kein Feld und entfallen.
     *
     * @return array<string, string>
     */
    public static function salutationFields(): array
    {
        $fields = [];
        foreach (LetterRecipient::TYPES as $type) {
            $field = LetterSalutation::field($type);
            if ($field !== '') {
                $fields[$field] = $type;
            }
        }
        return $fields;
    }

    /**
     * Alle Felder der Stammdaten in der Reihenfolge des Formulars.
     *
     * @return list<string>
     */
    public static function masterFields(): array
    {
        return array_merge(array_keys(self::TEXT_FIELDS), array_keys(self::salutationFields()));
    }

    /**
     * @param array<string, string> $values Freitextfelder (leer = nicht angegeben)
     */
    private function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly string $dateOfBirth,
        public readonly ?string $dateOfBirthRaw,
        public readonly ?string $patientIdentifier,
        public readonly array $values,
        public readonly bool $confirmDuplicate,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromPost(array $post): self
    {
        $errors = [];
        $text = static function (string $key) use ($post): string {
            $value = $post[$key] ?? '';
            $value = is_string($value) ? $value : '';
            return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value);
        };

        $lastName = PatientName::normalize($text('last_name')) ?? '';
        $firstName = PatientName::normalize($text('first_name')) ?? '';
        if ($lastName === '') {
            $errors['last_name'] = 'Der Nachname ist erforderlich.';
        } elseif (mb_strlen($lastName) > self::MAX_NAME) {
            $errors['last_name'] = sprintf('Der Nachname darf höchstens %d Zeichen enthalten.', self::MAX_NAME);
        }
        if ($firstName === '') {
            $errors['first_name'] = 'Der Vorname ist erforderlich.';
        } elseif (mb_strlen($firstName) > self::MAX_NAME) {
            $errors['first_name'] = sprintf('Der Vorname darf höchstens %d Zeichen enthalten.', self::MAX_NAME);
        }

        $dobRaw = trim($text('date_of_birth'));
        $dob = DateInput::parse($dobRaw);
        if ($dobRaw === '') {
            $errors['date_of_birth'] = 'Das Geburtsdatum ist erforderlich.';
        } elseif ($dob === null) {
            $errors['date_of_birth'] = 'Das Geburtsdatum ist ungültig (erwartet TT.MM.JJJJ).';
        } elseif (!DateInput::isPlausible($dob)) {
            $errors['date_of_birth'] = $dob > DateInput::today()
                ? 'Das Geburtsdatum darf nicht in der Zukunft liegen.'
                : 'Das Geburtsdatum ist unplausibel (vor 1900).';
        }

        $identifier = trim($text('patient_identifier'));
        if (mb_strlen($identifier) > self::MAX_IDENTIFIER) {
            $errors['patient_identifier'] = sprintf('Die Patienten-ID darf höchstens %d Zeichen enthalten.', self::MAX_IDENTIFIER);
            $identifier = mb_substr($identifier, 0, self::MAX_IDENTIFIER);
        }

        $values = [];
        foreach (self::TEXT_FIELDS as $field => $maxLength) {
            $value = trim($text($field));
            if (mb_strlen($value) > $maxLength) {
                $errors[$field] = sprintf('Höchstens %d Zeichen erlaubt.', $maxLength);
                $value = mb_substr($value, 0, $maxLength);
            }
            if (in_array($field, self::PHONE_FIELDS, true) && $value !== '' && preg_match('/^[0-9+()\/.\-\s]+$/u', $value) !== 1) {
                $errors[$field] = 'Erlaubt sind Ziffern sowie + ( ) / - . und Leerzeichen.';
            }
            if (in_array($field, self::POSTAL_FIELDS, true) && $value !== '' && preg_match('/^[0-9A-Za-z][0-9A-Za-z\s\-]{0,31}$/u', $value) !== 1) {
                $errors[$field] = 'Die Postleitzahl enthält ungültige Zeichen.';
            }
            $values[$field] = $value;
        }

        foreach (self::salutationFields() as $field => $type) {
            $value = trim($text($field));
            if ($value !== '' && !in_array($value, LetterSalutation::values($type), true)) {
                $errors[$field] = 'Bitte eine der angebotenen Anreden wählen.';
                $value = '';
            }
            $values[$field] = $value;
        }

        if ($errors !== []) {
            throw PatientException::validation($errors);
        }

        return new self(
            lastName: $lastName,
            firstName: $firstName,
            dateOfBirth: (string) $dob,
            dateOfBirthRaw: $dobRaw === $dob ? null : $dobRaw,
            patientIdentifier: $identifier === '' ? null : $identifier,
            values: $values,
            confirmDuplicate: ($post['confirm_duplicate'] ?? '') === '1',
        );
    }

    public function value(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function displayName(): string
    {
        return PatientName::display($this->lastName, $this->firstName);
    }

    public function identityKey(): string
    {
        return (string) PatientName::identityKey($this->lastName, $this->firstName, $this->dateOfBirth);
    }

    /**
     * Werte fuer die Anzeige im Formular (leere Felder als '').
     *
     * @return array<string, string>
     */
    public function allValues(): array
    {
        return $this->values + [
            'last_name' => $this->lastName,
            'first_name' => $this->firstName,
            'date_of_birth' => DateInput::format($this->dateOfBirth),
            'patient_identifier' => $this->patientIdentifier ?? '',
        ];
    }

    /**
     * Stammdaten fuer patient_card_master_data (Patientenausweis).
     *
     * @return array<string, string|null>
     */
    public function masterValues(): array
    {
        $data = [];
        foreach (self::masterFields() as $field) {
            $data[$field] = $this->value($field);
        }
        return $data;
    }
}
