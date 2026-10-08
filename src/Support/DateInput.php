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
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        return null;
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
