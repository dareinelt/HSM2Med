<?php

declare(strict_types=1);

namespace App\Letter;

/**
 * Empfaenger eines Briefes: Patient, Hausarzt oder ueberweisender Arzt.
 *
 * Die Anschrift stammt aus den Stammdaten des Patienten (patient_card_master_data). Beim
 * Erzeugen werden die Anschriftzeilen im Snapshot eingefroren; spaetere Aenderungen der
 * Stammdaten veraendern einen gespeicherten Brief deshalb nicht.
 *
 * Verwendbar ist ein Empfaenger, wenn ein Name (beim Arzt: Name oder Praxis) sowie
 * Postleitzahl und Ort vorliegen. Die Strasse ist empfohlen, aber keine Pflicht.
 *
 * Neben der Anschrift liefert der Empfaenger die Anrede (siehe LetterSalutation). Sie steht
 * in den Stammdaten und wird im Snapshot eingefroren, damit ein gespeicherter Brief seine
 * Anrede behaelt, auch wenn die Stammdaten spaeter geaendert werden.
 */
final class LetterRecipient
{
    public const string PATIENT = 'patient';
    public const string FAMILY_DOCTOR = 'family_doctor';
    public const string REFERRING_PHYSICIAN = 'referring_physician';

    /** Reihenfolge im Assistenten und beim Erzeugen. */
    public const array TYPES = [self::PATIENT, self::FAMILY_DOCTOR, self::REFERRING_PHYSICIAN];

    /** Spaltenpraefix der Arztanschrift in patient_card_master_data. */
    private const array PREFIX = [
        self::FAMILY_DOCTOR => 'physician_',
        self::REFERRING_PHYSICIAN => 'referrer_',
    ];

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function label(?string $type): string
    {
        return match ($type) {
            self::PATIENT => 'Patient',
            self::FAMILY_DOCTOR => 'Hausarzt',
            self::REFERRING_PHYSICIAN => 'Überweisender Arzt',
            default => '',
        };
    }

    /** Bezeichnung im Dateinamen (nur ASCII). */
    public static function fileLabel(?string $type): string
    {
        return match ($type) {
            self::PATIENT => 'Patient',
            self::FAMILY_DOCTOR => 'Hausarzt',
            self::REFERRING_PHYSICIAN => 'Ueberweisender-Arzt',
            default => '',
        };
    }

    /**
     * Empfaenger mit Anschrift und Verwendbarkeit (fuer Assistent und Snapshot).
     *
     * @param array<string, mixed> $patient Patient (first_name, last_name, patient_name)
     * @param array<string, mixed> $master Stammdaten
     * @return array{type: string, label: string, name: string, practice: string, street: string,
     *     postal_code: string, city: string, lines: list<string>, available: bool, missing: list<string>,
     *     salutation: string, salutation_value: string}
     */
    public static function resolve(string $type, array $patient, array $master): array
    {
        $value = static fn (string $key): string => trim((string) ($master[$key] ?? ''));
        if ($type === self::PATIENT) {
            $name = trim(trim((string) ($patient['first_name'] ?? '')) . ' ' . trim((string) ($patient['last_name'] ?? '')));
            if ($name === '') {
                $name = trim((string) ($patient['patient_name'] ?? ''));
            }
            $practice = '';
            $street = $value('street');
            $postalCode = $value('postal_code');
            $city = $value('city');
        } else {
            $prefix = self::PREFIX[$type] ?? throw new \InvalidArgumentException('Unbekannter Empfänger: ' . $type);
            $name = $value($prefix . 'name');
            $practice = $value($prefix . 'practice');
            $street = $value($prefix . 'street');
            $postalCode = $value($prefix . 'postal_code');
            $city = $value($prefix . 'city');
        }

        $missing = [];
        if ($name === '' && $practice === '') {
            $missing[] = $type === self::PATIENT ? 'Name' : 'Name oder Praxis';
        }
        if ($postalCode === '') {
            $missing[] = 'Postleitzahl';
        }
        if ($city === '') {
            $missing[] = 'Ort';
        }

        $lines = array_values(array_filter(
            [$practice, $name, $street, trim($postalCode . ' ' . $city)],
            static fn (string $line): bool => $line !== '',
        ));

        $salutationValue = LetterSalutation::fromMaster($type, $master);

        return [
            'type' => $type,
            'label' => self::label($type),
            'name' => $name,
            'practice' => $practice,
            'street' => $street,
            'postal_code' => $postalCode,
            'city' => $city,
            'lines' => $lines,
            'available' => $missing === [],
            'missing' => $missing,
            'salutation' => LetterSalutation::text(
                $type,
                $salutationValue,
                (string) ($patient['last_name'] ?? ''),
                (string) ($patient['first_name'] ?? ''),
            ),
            'salutation_value' => $salutationValue,
        ];
    }

    /**
     * Alle Empfaenger eines Patienten in fester Reihenfolge.
     *
     * @param array<string, mixed> $patient
     * @param array<string, mixed> $master
     * @return array<string, array<string, mixed>>
     */
    public static function all(array $patient, array $master): array
    {
        $all = [];
        foreach (self::TYPES as $type) {
            $all[$type] = self::resolve($type, $patient, $master);
        }
        return $all;
    }

    /**
     * Kurzbezeichnung fuer Listen, z. B. „Dr. Weber, Praxis am Markt".
     *
     * @param array<string, mixed> $recipient
     */
    public static function displayName(array $recipient): string
    {
        $parts = array_filter(
            [trim((string) ($recipient['name'] ?? '')), trim((string) ($recipient['practice'] ?? ''))],
            static fn (string $part): bool => $part !== '',
        );
        return implode(', ', $parts);
    }

    /**
     * Anzeige in Listen, z. B. „Hausarzt: Dr. Weber, Praxis am Markt". Briefe ohne
     * Empfaenger (vor Migration 008) zeigen den Hinweis auf das Anschriftfeld der Vorlage.
     */
    public static function listLabel(?string $type, ?string $name): string
    {
        $label = self::label($type);
        if ($label === '') {
            return 'laut Vorlage';
        }
        $name = trim((string) $name);
        return $name === '' ? $label : $label . ': ' . $name;
    }

    /**
     * Teil des Snapshots: Typ, Bezeichnung, Anschrift und die gedruckten Anschriftzeilen.
     *
     * @param array<string, mixed> $recipient Ergebnis von resolve()
     * @return array<string, mixed>
     */
    public static function snapshotPart(array $recipient): array
    {
        return [
            'type' => (string) $recipient['type'],
            'label' => (string) $recipient['label'],
            'name' => (string) $recipient['name'],
            'practice' => (string) $recipient['practice'],
            'street' => (string) $recipient['street'],
            'postal_code' => (string) $recipient['postal_code'],
            'city' => (string) $recipient['city'],
            'lines' => array_values(array_map('strval', (array) $recipient['lines'])),
            'salutation' => (string) ($recipient['salutation'] ?? ''),
            'salutation_value' => (string) ($recipient['salutation_value'] ?? ''),
        ];
    }
}
