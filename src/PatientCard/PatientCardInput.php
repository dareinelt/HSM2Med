<?php

declare(strict_types=1);

namespace App\PatientCard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Gepruefte und normalisierte Eingaben des Patientenausweis-Assistenten.
 *
 * Alle Felder werden serverseitig validiert; die Anzeige der Fehler erfolgt feldbezogen.
 * Leere Werte sind erlaubt (die Datenbank speichert sie als ''), Pflichtfelder sind
 * Nachname, Vorname und Geburtsdatum sowie die beiden Bestaetigungen vor dem Erzeugen.
 */
final class PatientCardInput
{
    /** Erlaubte Laengen der Freitextfelder des Assistenten. */
    public const array TEXT_FIELDS = [
        'street' => 255,
        'postal_code' => 32,
        'city' => 255,
        'phone' => 64,
        'indication' => 2000,
        'device_implant_location' => 255,
        'emergency_contact_name' => 255,
        'emergency_contact_phone' => 64,
        'physician_name' => 255,
        'physician_practice' => 255,
        'physician_postal_code' => 32,
        'physician_city' => 255,
        'physician_phone' => 64,
        'control_physician' => 255,
    ];

    /** Felder, die im Vergleich "bisheriger Wert / neuer Wert" erscheinen koennen. */
    public const array MERGE_FIELDS = [
        'street',
        'postal_code',
        'city',
        'phone',
        'indication',
        'device_implant_location',
        'emergency_contact_name',
        'emergency_contact_phone',
        'physician_name',
        'physician_practice',
        'physician_postal_code',
        'physician_city',
        'physician_phone',
        'control_physician',
        'next_control_date',
    ];

    /**
     * @param array<string, string> $values Freitextfelder (leer = nicht angegeben)
     * @param array<string, string> $conflictChoices Feld => 'new' | 'stored'
     */
    private function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly string $dateOfBirth,
        public readonly ?string $dateOfBirthRaw,
        public readonly array $values,
        public readonly ?string $nextControlDate,
        public readonly ?string $nextControlRaw,
        public readonly bool $confirmPatient,
        public readonly bool $confirmMerge,
        public readonly array $conflictChoices,
        public readonly ?int $selectedPatientId,
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
        } elseif (mb_strlen($lastName) > 255) {
            $errors['last_name'] = 'Der Nachname darf hoechstens 255 Zeichen enthalten.';
        }
        if ($firstName === '') {
            $errors['first_name'] = 'Der Vorname ist erforderlich.';
        } elseif (mb_strlen($firstName) > 255) {
            $errors['first_name'] = 'Der Vorname darf hoechstens 255 Zeichen enthalten.';
        }

        $dobRaw = trim($text('date_of_birth'));
        $dob = self::parseDate($dobRaw);
        if ($dobRaw === '') {
            $errors['date_of_birth'] = 'Das Geburtsdatum ist erforderlich.';
        } elseif ($dob === null) {
            $errors['date_of_birth'] = 'Das Geburtsdatum ist ungueltig (erwartet TT.MM.JJJJ).';
        } elseif ($dob > self::today()) {
            $errors['date_of_birth'] = 'Das Geburtsdatum darf nicht in der Zukunft liegen.';
        } elseif ($dob < '1900-01-01') {
            $errors['date_of_birth'] = 'Das Geburtsdatum ist unplausibel (vor 1900).';
        }

        $values = [];
        foreach (self::TEXT_FIELDS as $field => $maxLength) {
            $value = trim($text($field));
            if (mb_strlen($value) > $maxLength) {
                $errors[$field] = sprintf('Hoechstens %d Zeichen erlaubt.', $maxLength);
                $value = mb_substr($value, 0, $maxLength);
            }
            if (in_array($field, ['phone', 'emergency_contact_phone', 'physician_phone'], true)
                && $value !== ''
                && preg_match('/^[0-9+()\/.\-\s]+$/u', $value) !== 1) {
                $errors[$field] = 'Erlaubt sind Ziffern sowie + ( ) / - . und Leerzeichen.';
            }
            if (str_ends_with($field, 'postal_code') && $value !== ''
                && preg_match('/^[0-9A-Za-z][0-9A-Za-z\s\-]{0,31}$/u', $value) !== 1) {
                $errors[$field] = 'Die Postleitzahl enthaelt ungueltige Zeichen.';
            }
            $values[$field] = $value;
        }

        $controlRaw = trim($text('next_control_date'));
        $controlDate = $controlRaw === '' ? null : self::parseDate($controlRaw);
        if ($controlRaw !== '' && $controlDate === null) {
            $errors['next_control_date'] = 'Der Kontrolltermin ist ungueltig (erwartet TT.MM.JJJJ).';
        }

        $choices = [];
        $rawChoices = $post['conflict'] ?? [];
        if (is_array($rawChoices)) {
            foreach ($rawChoices as $field => $choice) {
                if (is_string($field) && in_array($field, self::MERGE_FIELDS, true)
                    && is_string($choice) && in_array($choice, ['new', 'stored'], true)) {
                    $choices[$field] = $choice;
                }
            }
        }

        $selected = null;
        $rawSelected = $text('patient_id');
        if ($rawSelected !== '' && ctype_digit($rawSelected) && (int) $rawSelected > 0) {
            $selected = (int) $rawSelected;
        }

        if ($errors !== []) {
            throw PatientCardException::validation($errors);
        }

        return new self(
            lastName: $lastName,
            firstName: $firstName,
            dateOfBirth: (string) $dob,
            dateOfBirthRaw: $dobRaw === $dob ? null : $dobRaw,
            values: $values,
            nextControlDate: $controlDate,
            nextControlRaw: $controlRaw !== '' && $controlRaw !== $controlDate ? $controlRaw : null,
            confirmPatient: ($post['confirm_patient'] ?? '') === '1',
            confirmMerge: ($post['confirm_merge'] ?? '') === '1',
            conflictChoices: $choices,
            selectedPatientId: $selected,
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
     * Alle Assistentenwerte inklusive Identitaet und Kontrolltermin als flache Liste.
     *
     * @return array<string, string>
     */
    public function allValues(): array
    {
        return $this->values + [
            'next_control_date' => $this->nextControlDate ?? '',
        ];
    }

    /**
     * Bestaetigungen fuer die Anzeige (Assistentenschritt 6).
     *
     * @return array{patient: bool, merge: bool}
     */
    public function confirmations(): array
    {
        return ['patient' => $this->confirmPatient, 'merge' => $this->confirmMerge];
    }

    /**
     * Akzeptiert TT.MM.JJJJ, JJJJ-MM-TT und TT/MM/JJJJ sowie TT-MM-JJJJ.
     */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $formats = ['d.m.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    public static function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = self::parseDate($value);
        if ($date === null) {
            return $value;
        }
        return (new DateTimeImmutable($date))->format('d.m.Y');
    }

    private static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }
}
