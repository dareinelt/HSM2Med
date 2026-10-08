<?php

declare(strict_types=1);

namespace App\Letter;

use App\PatientCard\PatientName;

/**
 * Anrede eines Briefes.
 *
 * Die Anrede wird nicht in der Vorlage gepflegt, sondern in den Stammdaten des Patienten –
 * je Empfaengerart getrennt (Patient, Hausarzt, ueberweisender Arzt). Beim Erzeugen eines
 * Briefes wird der fertige Anredetext berechnet und im Snapshot eingefroren; die Vorlage
 * enthaelt im Baustein "Anrede" nur den Platzhalter {salutation}.
 *
 * Aerzte werden ohne Nachnamen angeredet ("Sehr geehrter Herr Kollege,"), weil der Nachname
 * im Anschriftfeld steht. Praxen und Kliniken erhalten die unpersoenliche Anrede.
 */
final class LetterSalutation
{
    public const string HERR = 'herr';
    public const string FRAU = 'frau';
    public const string DIVERS = 'divers';
    public const string KOLLEGE = 'kollege';
    public const string KOLLEGIN = 'kollegin';
    public const string UNPERSOENLICH = 'unpersoenlich';

    /** Anrede ohne Angabe in den Stammdaten (auch fuer Briefe ohne Empfaenger). */
    public const string FALLBACK = 'Sehr geehrte Damen und Herren,';

    /** Stammdatenfeld je Empfaengerart. */
    private const array FIELDS = [
        LetterRecipient::PATIENT => 'salutation',
        LetterRecipient::FAMILY_DOCTOR => 'physician_salutation',
        LetterRecipient::REFERRING_PHYSICIAN => 'referrer_salutation',
    ];

    /** Auswahl je Empfaengerart (Wert => Beschriftung im Formular). */
    private const array CHOICES = [
        LetterRecipient::PATIENT => [
            self::HERR => 'Herr',
            self::FRAU => 'Frau',
            self::DIVERS => 'Divers',
        ],
        LetterRecipient::FAMILY_DOCTOR => [
            self::KOLLEGE => 'Kollege',
            self::KOLLEGIN => 'Kollegin',
            self::UNPERSOENLICH => 'Unpersönlich (Praxis/Klinik)',
        ],
        LetterRecipient::REFERRING_PHYSICIAN => [
            self::KOLLEGE => 'Kollege',
            self::KOLLEGIN => 'Kollegin',
            self::UNPERSOENLICH => 'Unpersönlich (Praxis/Klinik)',
        ],
    ];

    /** Hinweis zum Stammdatenfeld je Empfaengerart. */
    private const array HINTS = [
        LetterRecipient::PATIENT => 'Erscheint als Anrede im Brief. „Divers“ wird mit vollem Namen angeredet.',
        LetterRecipient::FAMILY_DOCTOR => '„Unpersönlich“ für Praxen und Kliniken: „Sehr geehrte Damen und Herren,“',
        LetterRecipient::REFERRING_PHYSICIAN => '„Unpersönlich“ für Praxen und Kliniken: „Sehr geehrte Damen und Herren,“',
    ];

    /**
     * Name des Stammdatenfeldes.
     */
    public static function field(string $type): string
    {
        return self::FIELDS[$type] ?? self::FIELDS[LetterRecipient::PATIENT];
    }

    /**
     * Auswahlmoeglichkeiten der Empfaengerart (Wert => Beschriftung).
     *
     * @return array<string, string>
     */
    public static function choices(string $type): array
    {
        return self::CHOICES[$type] ?? self::CHOICES[LetterRecipient::PATIENT];
    }

    /**
     * Erlaubte Werte der Empfaengerart.
     *
     * @return list<string>
     */
    public static function values(string $type): array
    {
        return array_keys(self::choices($type));
    }

    public static function hint(string $type): string
    {
        return self::HINTS[$type] ?? self::HINTS[LetterRecipient::PATIENT];
    }

    /**
     * Wert aus den Stammdaten lesen; unbekannte oder fehlende Angaben ergeben einen Leerstring.
     *
     * @param array<string, mixed> $master
     */
    public static function fromMaster(string $type, array $master): string
    {
        $value = $master[self::field($type)] ?? '';
        $value = is_string($value) ? trim($value) : '';
        return in_array($value, self::values($type), true) ? $value : '';
    }

    /**
     * Fertiger Anredetext einschliesslich abschliessendem Komma.
     *
     * @param string $value     Wert aus den Stammdaten (ggf. leer)
     * @param string $lastName  Nachname des Patienten (nur fuer Patientenbriefe)
     * @param string $firstName Vorname des Patienten (nur fuer die Anrede "Divers")
     */
    public static function text(string $type, string $value, string $lastName = '', string $firstName = ''): string
    {
        $last = (string) PatientName::normalize($lastName);
        $first = (string) PatientName::normalize($firstName);

        if ($type === LetterRecipient::PATIENT) {
            return match ($value) {
                self::HERR => $last === '' ? self::FALLBACK : 'Sehr geehrter Herr ' . $last . ',',
                self::FRAU => $last === '' ? self::FALLBACK : 'Sehr geehrte Frau ' . $last . ',',
                self::DIVERS => self::diverseText($last, $first),
                default => self::FALLBACK,
            };
        }

        return match ($value) {
            self::KOLLEGE => 'Sehr geehrter Herr Kollege,',
            self::KOLLEGIN => 'Sehr geehrte Frau Kollegin,',
            default => self::FALLBACK,
        };
    }

    /**
     * Anrede fuer die Angabe "Divers": "Guten Tag Mustermann, Erika," – nur mit Nachnamen
     * "Guten Tag Mustermann,".
     */
    private static function diverseText(string $last, string $first): string
    {
        if ($last === '') {
            return self::FALLBACK;
        }
        return $first === '' ? 'Guten Tag ' . $last . ',' : 'Guten Tag ' . $last . ', ' . $first . ',';
    }
}
