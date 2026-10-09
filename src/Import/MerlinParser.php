<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Parser fuer Merlin-Exportdateien (Abbott / St. Jude Medical).
 *
 * Format je Datensatz:  <ID> FS <Bezeichnung> FS <Wert> FS <Einheit> FS   (FS = ASCII 0x1C)
 *
 * - Massgeblich ist ausschliesslich das Trennzeichen 0x1C. Zeilenumbrueche zwischen Datensaetzen
 *   werden toleriert, sind aber nicht erforderlich.
 * - Werte werden weder getrimmt, gerundet noch interpretiert. Leere Felder bleiben ''.
 * - Fehlerhafte Datensaetze werden protokolliert und uebersprungen, der Import laeuft weiter.
 */
final class MerlinParser implements ParserInterface
{
    public const string VERSION = '1.0.0';
    public const string NAME = 'Merlin (Abbott / St. Jude Medical)';
    public const string SEPARATOR = "\x1C";
    public const int FIELDS_PER_RECORD = 4;
    public const int MAX_PARAMETER_ID_LENGTH = 32;

    /** Ein Token, das nach einem Zeilenumbruch nur aus einer Parameter-ID besteht, beginnt einen neuen Datensatz. */
    private const string RECORD_START_PATTERN = '/^(?:\r\n|\r|\n)+[ \t]*\d{1,32}[ \t]*$/D';

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function supports(string $bytes, string $filename = ''): bool
    {
        return str_contains($bytes, self::SEPARATOR);
    }

    public function parse(string $bytes): ParseResult
    {
        [$text, $encoding, $issues] = $this->decode($bytes);
        return $this->parseText($text, $encoding, $issues);
    }

    /**
     * UTF-8 bevorzugt; sonst kontrollierter Rueckfall auf Windows-1252 bzw. ISO-8859-1.
     *
     * @return array{0: string, 1: string, 2: list<ImportIssue>}
     */
    public function decode(string $bytes): array
    {
        return SourceEncoding::decode($bytes);
    }

    /**
     * @param list<ImportIssue> $issues bereits vorhandene Meldungen (z.B. aus decode())
     */
    public function parseText(string $text, string $encoding = 'UTF-8', array $issues = []): ParseResult
    {
        if (trim($text) === '') {
            $issues[] = ImportIssue::error('empty_file', 'Die Datei enthaelt keine Daten.');
            return new ParseResult($encoding, [], $issues, 0);
        }
        if (!str_contains($text, self::SEPARATOR)) {
            $issues[] = ImportIssue::error(
                'no_separator',
                'Kein Feldtrennzeichen 0x1C (File Separator) gefunden – keine Merlin-Exportdatei.',
            );
            return new ParseResult($encoding, [], $issues, 0);
        }

        $tokens = explode(self::SEPARATOR, $text);
        // Text nach dem letzten Trennzeichen ist kein abgeschlossenes Feld.
        $tail = array_pop($tokens);
        $tailHasData = trim($tail, "\r\n\t ") !== '';

        $records = [];
        $tokenCount = count($tokens);
        $position = 0;
        $i = 0;

        while ($i < $tokenCount) {
            $position++;
            $start = $i;
            $fields = [];
            $j = $i + 1;
            while (count($fields) < self::FIELDS_PER_RECORD - 1 && $j < $tokenCount) {
                if (preg_match(self::RECORD_START_PATTERN, $tokens[$j]) === 1) {
                    break;
                }
                $fields[] = $tokens[$j];
                $j++;
            }
            $i = $j;

            $rawParts = array_slice($tokens, $start, $j - $start);
            $rawParts[0] = ltrim($rawParts[0], "\r\n");
            $rawRecord = implode(self::SEPARATOR, $rawParts) . self::SEPARATOR;
            $missingTerminator = false;

            if (count($fields) < self::FIELDS_PER_RECORD - 1 && $j === $tokenCount && $tailHasData) {
                // Letzter Datensatz ohne abschliessendes Trennzeichen
                $lastField = rtrim($tail, "\r\n");
                $rawRecord .= $lastField;
                $fields[] = $lastField;
                $tailHasData = false;
                $missingTerminator = true;
            }

            if (count($fields) < self::FIELDS_PER_RECORD - 1) {
                $issues[] = ImportIssue::error(
                    'incomplete_record',
                    sprintf('Unvollstaendiger Datensatz: %d von %d Feldern.', count($fields) + 1, self::FIELDS_PER_RECORD),
                    $position,
                    $rawRecord,
                );
                continue;
            }

            $parameterId = trim(ltrim($tokens[$start], "\r\n"), " \t");
            if (preg_match('/^\d{1,' . self::MAX_PARAMETER_ID_LENGTH . '}$/D', $parameterId) !== 1) {
                $issues[] = ImportIssue::error(
                    'invalid_parameter_id',
                    'Ungueltige Parameter-ID (erwartet: 1-32 Ziffern).',
                    $position,
                    $rawRecord,
                );
                continue;
            }

            [$name, $value, $unit] = $fields;

            if ($missingTerminator) {
                $issues[] = ImportIssue::warning(
                    'missing_final_separator',
                    'Letzter Datensatz ohne abschliessendes Trennzeichen 0x1C; Einheit bis Dateiende uebernommen.',
                    $position,
                    $rawRecord,
                );
            }
            if ($name === '') {
                $issues[] = ImportIssue::warning('empty_parameter_name', 'Leere Parameterbezeichnung.', $position, $rawRecord);
            }
            foreach (['Bezeichnung' => $name, 'Wert' => $value, 'Einheit' => $unit] as $label => $field) {
                if (strpbrk($field, "\r\n") !== false) {
                    $issues[] = ImportIssue::warning(
                        'line_break_in_field',
                        sprintf('Feld "%s" enthaelt einen Zeilenumbruch (unveraendert uebernommen).', $label),
                        $position,
                        $rawRecord,
                    );
                }
                if (str_contains($field, "\0")) {
                    $issues[] = ImportIssue::warning(
                        'nul_in_field',
                        sprintf('Feld "%s" enthaelt ein NUL-Zeichen (unveraendert uebernommen).', $label),
                        $position,
                        $rawRecord,
                    );
                }
            }

            $records[] = new ParsedRecord($parameterId, $name, $value, $unit, $rawRecord, $position);
        }

        if ($tailHasData) {
            $position++;
            $issues[] = ImportIssue::error(
                'unterminated_record',
                'Daten nach dem letzten Trennzeichen ohne vollstaendigen Datensatz.',
                $position,
                ltrim($tail, "\r\n"),
            );
        }

        return new ParseResult($encoding, $records, $issues, $position);
    }
}
