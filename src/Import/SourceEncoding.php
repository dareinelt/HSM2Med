<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Ermittelt die Kodierung einer Exportdatei und wandelt sie verlustfrei nach UTF-8 um.
 * UTF-8 wird bevorzugt; sonst kontrollierter Rueckfall auf Windows-1252 bzw. ISO-8859-1.
 */
final class SourceEncoding
{
    /**
     * @return array{0: string, 1: string, 2: list<ImportIssue>} Text, erkannte Kodierung, Meldungen
     */
    public static function decode(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $body = substr($bytes, 3);
            if (mb_check_encoding($body, 'UTF-8')) {
                return [$body, 'UTF-8 (BOM)', []];
            }
            $bytes = $body;
        }

        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $encoding = $bytes[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
            $body = substr($bytes, 2);
            if (strlen($body) % 2 === 0 && mb_check_encoding($body, $encoding)) {
                return [mb_convert_encoding($body, 'UTF-8', $encoding), $encoding, []];
            }
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, 'UTF-8', []];
        }

        // Bytes, die in Windows-1252 undefiniert sind, erzwingen ISO-8859-1 (verlustfrei fuer alle Bytes).
        $encoding = preg_match('/[\x81\x8D\x8F\x90\x9D]/', $bytes) === 1 ? 'ISO-8859-1' : 'Windows-1252';
        $issues = [ImportIssue::warning(
            'encoding_fallback',
            sprintf('Die Datei ist kein gueltiges UTF-8 und wurde als %s dekodiert.', $encoding),
        )];

        return [mb_convert_encoding($bytes, 'UTF-8', $encoding), $encoding, $issues];
    }
}
