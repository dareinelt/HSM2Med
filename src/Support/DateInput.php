<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Einheitliche Behandlung von Datumseingaben (TT.MM.JJJJ, JJJJ-MM-TT, TT/MM/JJJJ, TT-MM-JJJJ).
 *
 * Gueltige Eingaben werden als ISO-Datum (JJJJ-MM-TT) gespeichert; das Original wird bei
 * abweichender Schreibweise zusaetzlich als Rohtext gefuehrt (Analogie zu date_of_birth_raw).
 *
 * Fuer Zeitraeume (z. B. Vormedikation) akzeptiert parseExtended() zusaetzlich kompakte
 * Schreibweisen und monatsgenaue Angaben; fehlt der Tag, gilt der Monatserste.
 */
final class DateInput
{
    /**
     * Liefert das ISO-Datum (JJJJ-MM-TT) oder null, wenn die Eingabe leer oder ungueltig ist.
     */
    public static function parse(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $iso = self::fromFormat($format, $value);
            if ($iso !== null) {
                return $iso;
            }
        }
        return null;
    }

    /**
     * Wie parse(), akzeptiert zusaetzlich kompakte und monatsgenaue Angaben:
     * TTMMJJJJ, JJJJMMTT, MM/JJJJ, MM.JJJJ, MM-JJJJ, JJJJ-MM, JJJJ/MM und JJJJ.MM.
     * Ohne Tagesangabe gilt der erste des Monats.
     */
    public static function parseExtended(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $iso = self::parse($value);
        if ($iso !== null) {
            return $iso;
        }
        if (preg_match('/^\d{8}$/', $value) === 1) {
            return self::fromFormat('dmY', $value) ?? self::fromFormat('Ymd', $value);
        }
        if (preg_match('/^(\d{1,2})\s*[.\/-]\s*(\d{4})$/', $value, $match) === 1) {
            return self::monthFirst((int) $match[1], (int) $match[2]);
        }
        if (preg_match('/^(\d{4})\s*[.\/-]\s*(\d{1,2})$/', $value, $match) === 1) {
            return self::monthFirst((int) $match[2], (int) $match[1]);
        }
        return null;
    }

    private static function fromFormat(string $format, string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    /**
     * Monatsgenaue Angabe: der Tag ist immer der Monatserste.
     */
    private static function monthFirst(int $month, int $year): ?string
    {
        if ($month < 1 || $month > 12 || $year < 1) {
            return null;
        }
        return sprintf('%04d-%02d-01', $year, $month);
    }

    /**
     * Anzeigeform TT.MM.JJJJ; unveraendert zurueckgegeben wird alles, was kein Datum ist.
     */
    public static function format(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $date = self::parse($value);
        if ($date === null) {
            return $value;
        }
        return (new DateTimeImmutable($date))->format('d.m.Y');
    }

    /**
     * Prueft, ob ein ISO-Datum im plausiblen Bereich liegt (ab 1900, nicht in der Zukunft).
     */
    public static function isPlausible(string $isoDate, bool $allowFuture = false): bool
    {
        if ($isoDate < '1900-01-01') {
            return false;
        }
        return $allowFuture || $isoDate <= self::today();
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }
}
