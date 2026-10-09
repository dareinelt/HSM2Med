<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Datumswerte der Quellformate. Es wird zuerst das Merlin-Format (US-Notation) und danach
 * das IEEE-11073-10103-Format (Biotronik) versucht. Nicht erkannte Werte bleiben unveraendert.
 */
final class SourceDate
{
    public static function parse(?string $raw): ?DateTimeImmutable
    {
        return MerlinDate::parse($raw) ?? IeeeDate::parse($raw);
    }

    /**
     * Darstellung "TT.MM.JJJJ" bzw. "TT.MM.JJJJ HH:MM:SS"; bei nicht erkennbarem Format unveraendert.
     */
    public static function display(?string $raw, bool $dateOnly): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }
        return MerlinDate::parse($raw) === null
            ? IeeeDate::display($raw, $dateOnly)
            : MerlinDate::display($raw, $dateOnly);
    }
}
