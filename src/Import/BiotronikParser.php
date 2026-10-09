<?php

declare(strict_types=1);

namespace App\Import;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Parser fuer XML-Exporte nach IEEE 11073-10103 (Biotronik, Creator "BioICSConverter").
 *
 * Aufbau: <biotronik-ieee11073-export> -> <dataset> -> verschachtelte <section name="...">
 * mit <value code="..." name="..." type="..." [unit="..."]>Wert</value>.
 *
 * - Als Quell-ID dient das Attribut "code" (z.B. 730880). Fehlt es (z.B. in ATTR/PT oder BIO),
 *   wird eine stabile Ersatz-ID aus dem Abschnittspfad gebildet ("MDC.ATTR.PT.DOB"). Diese
 *   Ersatz-ID ist KEINE standardisierte Kodierung, sondern nur ein eindeutiger Schluessel der
 *   Quelldatei.
 * - Werte, Einheiten und Bezeichnungen werden unveraendert uebernommen; leere Werte bleiben ''.
 * - Umschliessende Formatierungs-Leerzeichen des XML-Textknotens werden entfernt (XML-Semantik).
 * - Die eingebettete XML-Signatur (xmldsig) wird nicht ausgewertet und nicht uebernommen.
 * - Unvollstaendige Wertknoten werden protokolliert und uebersprungen, der Import laeuft weiter.
 * - NUL-Zeichen sind in XML nicht zulaessig; solche Dateien werden als "invalid_xml" gemeldet.
 */
final class BiotronikParser implements ParserInterface
{
    public const string VERSION = '1.0.0';
    public const string NAME = 'Biotronik IEEE 11073-10103';
    public const string ROOT_ELEMENT = 'biotronik-ieee11073-export';
    /** Namenspraefix der Exportdateien (Biotronik-Konvention). */
    public const string FILENAME_PREFIX = 'BIOIEEE_';
    public const int MAX_PARAMETER_ID_LENGTH = 32;

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
        // Namenskonvention der Biotronik-Exporte (z.B. BIOIEEE_ANN.xml).
        if (str_starts_with(strtoupper(basename($filename)), self::FILENAME_PREFIX)) {
            return true;
        }
        $head = ltrim(substr($bytes, 0, 512), " \t\r\n\xEF\xBB\xBF");
        return str_starts_with($head, '<?xml') || str_contains($head, '<' . self::ROOT_ELEMENT);
    }

    public function parse(string $bytes): ParseResult
    {
        [$text, $encoding, $issues] = SourceEncoding::decode($bytes);

        if (trim($text) === '') {
            $issues[] = ImportIssue::error('empty_file', 'Die Datei enthaelt keine Daten.');
            return new ParseResult($encoding, [], $issues, 0);
        }

        [$document, $xmlError] = $this->load($text);
        if ($document === null) {
            $issues[] = ImportIssue::error(
                'invalid_xml',
                'Die Datei ist kein gueltiges XML' . ($xmlError === '' ? '' : ': ' . $xmlError) . '.',
            );
            return new ParseResult($encoding, [], $issues, 0);
        }

        $root = $document->documentElement;
        if ($root->localName !== self::ROOT_ELEMENT) {
            $issues[] = ImportIssue::error(
                'unexpected_root',
                sprintf('Unerwartetes Wurzelelement <%s>; erwartet wird <%s>.', $root->nodeName, self::ROOT_ELEMENT),
            );
            return new ParseResult($encoding, [], $issues, 0);
        }

        $records = [];
        $position = 0;
        $withoutCode = 0;
        $this->collect($root, [], $records, $position, $withoutCode, $issues);

        if ($records === [] && $issues === []) {
            $issues[] = ImportIssue::error('no_values', 'Die Datei enthaelt keine <value>-Knoten.');
        }
        if ($withoutCode > 0) {
            $issues[] = ImportIssue::warning(
                'missing_value_code',
                sprintf(
                    '%d Wertknoten ohne Attribut "code" (z.B. Patientendaten); es wurden Ersatz-IDs aus dem Abschnittspfad gebildet.',
                    $withoutCode,
                ),
            );
        }

        return new ParseResult($encoding, $records, $issues, $position);
    }

    /**
     * Laedt das XML ohne Netzwerkzugriff und ohne Fehlerausgabe an den Aufrufer.
     *
     * @return array{0: ?DOMDocument, 1: string} Dokument oder null sowie die libxml-Meldung
     */
    private function load(string $text): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadXML($text, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded && $document->documentElement !== null) {
            return [$document, ''];
        }
        return [null, $error === false ? '' : trim($error->message)];
    }

    /**
     * Durchsucht den Baum rekursiv nach <value>-Knoten und merkt sich den Abschnittspfad.
     * Gleichnamige Geschwisterabschnitte (z.B. mehrere <section name="LEAD">) werden mit
     * einer laufenden Nummer versehen, damit sie als getrennte Sonden erkannt werden.
     *
     * @param list<string> $path
     * @param list<ParsedRecord> $records
     * @param list<ImportIssue> $issues
     */
    private function collect(DOMNode $node, array $path, array &$records, int &$position, int &$withoutCode, array &$issues): void
    {
        $occurrences = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'section') {
                $name = trim($child->getAttribute('name'));
                $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
            }
        }
        $seen = [];

        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'section') {
                $name = trim($child->getAttribute('name'));
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                $segment = $occurrences[$name] > 1 ? $name . '#' . $seen[$name] : $name;
                $this->collect($child, [...$path, $segment], $records, $position, $withoutCode, $issues);
                continue;
            }
            if ($child->localName === 'value') {
                $position++;
                $record = $this->record($child, $path, $position, $withoutCode, $issues);
                if ($record !== null) {
                    $records[] = $record;
                }
                continue;
            }
            // Container wie <dataset> oder die Signatur werden ohne Pfadsegment durchsucht.
            $this->collect($child, $path, $records, $position, $withoutCode, $issues);
        }
    }

    /**
     * @param list<string> $path
     * @param list<ImportIssue> $issues
     */
    private function record(DOMElement $element, array $path, int $position, int &$withoutCode, array &$issues): ?ParsedRecord
    {
        $rawRecord = $element->ownerDocument?->saveXML($element) ?: '';
        $name = trim($element->getAttribute('name'));
        if ($name === '') {
            $issues[] = ImportIssue::error(
                'missing_value_name',
                'Wertknoten ohne Attribut "name" wurde uebersprungen.',
                $position,
                $rawRecord,
            );
            return null;
        }

        $section = implode('/', $path);
        $code = trim($element->getAttribute('code'));
        if ($code === '') {
            $withoutCode++;
            $parameterId = $this->syntheticId($section === '' ? $name : $section . '/' . $name);
        } else {
            $parameterId = $code;
        }

        $value = trim($element->textContent);

        return new ParsedRecord(
            $parameterId,
            $name,
            $value,
            trim($element->getAttribute('unit')),
            $rawRecord,
            $position,
            $section,
        );
    }

    /**
     * Ersatz-ID aus dem Abschnittspfad; bei Ueberlaenge mit Pruefsumme, damit sie eindeutig bleibt.
     */
    private function syntheticId(string $path): string
    {
        $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '.', $path));
        $slug = trim($slug, '.');
        if (strlen($slug) <= self::MAX_PARAMETER_ID_LENGTH) {
            return $slug;
        }
        return substr($slug, 0, 19) . '~' . substr(md5($slug), 0, 8);
    }
}
