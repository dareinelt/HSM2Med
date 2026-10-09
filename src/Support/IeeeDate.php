<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Datumswerte im Format IEEE 11073-10103 (Biotronik-Export, Creator "BioICSConverter"):
 *
 *   YYYYMMDD                 z.B. 19280425 (nur Datum, z.B. Geburtsdatum)
 *   YYYYMMDDThhmmss          z.B. 20231211T000000
 *   YYYYMMDDThhmmss+hhmm     z.B. 20261008T123829+0200
 *
 * Die Zeitzonenangabe wird NICHT umgerechnet: uebernommen wird die angegebene Ortszeit.
 * Die Normalisierung ist rein ergaenzend; der Originalwert bleibt immer erhalten.
 */
final class IeeeDate
{
    /** Reihenfolge: spezifischstes Format zuerst. */
    private const array FORMATS = ['!Ymd\THisO', '!Ymd\THis', '!Ymd'];

    public static function parse(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (self::FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            if ($date === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            // Ueberlaeufe wie 20261345 ausschliessen
            if ($date->format('Ymd') !== substr($raw, 0, 8)) {
                continue;
            }
            return $date;
        }
        return null;
    }

    /**
     * Darstellung "TT.MM.JJJJ" bzw. "TT.MM.JJJJ HH:MM:SS"; bei nicht erkennbarem Format unveraendert.
     */
    public static function display(?string $raw, bool $dateOnly): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        $date = self::parse($raw);
        if ($date === null) {
            return $raw;
        }
        if (!str_contains($raw, 'T') || ($dateOnly && $date->format('His') === '000000')) {
            return $date->format('d.m.Y');
        }
        return $date->format('d.m.Y H:i:s');
    }
}
