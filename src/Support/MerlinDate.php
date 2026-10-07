<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Datumswerte im Merlin-Export: "MM/DD/YYYY HH:MM:SS" (US-Format, z.B. "10/21/1938 00:00:00").
 * Die Normalisierung ist rein ergaenzend; der Originalwert bleibt immer erhalten.
 */
final class MerlinDate
{
    public static function parse(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        foreach (['!m/d/Y H:i:s', '!m/d/Y H:i', '!m/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }
            // Ueberlaeufe wie 13/45/2024 ausschliessen
            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $raw, $m)
                || (int) $date->format('m') !== (int) $m[1]
                || (int) $date->format('d') !== (int) $m[2]) {
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
        if ($dateOnly && $date->format('H:i:s') === '00:00:00') {
            return $date->format('d.m.Y');
        }
        return $date->format(str_contains($raw, ':') ? 'd.m.Y H:i:s' : 'd.m.Y');
    }
}
