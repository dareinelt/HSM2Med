<?php

declare(strict_types=1);

namespace App\Patient;

use App\Support\DateInput;

/**
 * Gepruefte und normalisierte Eingaben eines Aktenbausteins.
 *
 * Aufbau des Inhalts (JSON):
 *  * Freitextbausteine (Anamnese, Epikrise, Notiz): {"text": "..."}
 *  * Vormedikation: {"text": "Ergaenzungen", "entries": [{substance, dose, unit, schedule,
 *    reason, from, to}, ...]}
 *
 * Ein Baustein wird nie leer gespeichert: ohne mindestens eine Angabe wird die Eingabe
 * abgelehnt. Fruehere Fassungen bleiben erhalten, Korrekturen erzeugen eine neue Fassung.
 */
final class PatientRecordInput
{
    public const int MAX_TEXT = 20000;
    public const int MAX_AUTHOR = 255;
    public const int MAX_ENTRIES = 50;

    public const array ENTRY_FIELDS = [
        'substance' => 255,
        'dose' => 64,
        'unit' => 32,
        'schedule' => 128,
        'reason' => 255,
        'from' => 10,
        'to' => 10,
    ];

    /**
     * @param list<array<string, string>> $entries
     */
    private function __construct(
        public readonly string $text,
        public readonly array $entries,
        public readonly ?string $authorName,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromPost(PatientRecordType $type, array $post): self
    {
        $errors = [];
        $text = $post['text'] ?? '';
        $text = is_string($text) ? $text : '';
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $text));
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT) {
            $errors['text'] = sprintf('Höchstens %d Zeichen erlaubt (aktuell %d).', self::MAX_TEXT, mb_strlen($text));
        }

        $author = $post['author_name'] ?? '';
        $author = is_string($author) ? trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $author)) : '';
        if (mb_strlen($author) > self::MAX_AUTHOR) {
            $errors['author_name'] = sprintf('Höchstens %d Zeichen erlaubt.', self::MAX_AUTHOR);
        }

        $entries = $type->isStructured() ? self::entries($post, $errors) : [];

        if ($errors === []) {
            if ($text === '' && $entries === []) {
                $errors['text'] = $type->isStructured()
                    ? 'Bitte mindestens ein Arzneimittel oder eine Ergänzung angeben.'
                    : 'Bitte den Inhalt des Bausteins angeben.';
            }
        }

        if ($errors !== []) {
            throw PatientException::validation($errors);
        }

        return new self($text, $entries, $author === '' ? null : $author);
    }

    /**
     * Liest die Medikamentenzeilen. Vollstaendig leere Zeilen werden verworfen.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return list<array<string, string>>
     */
    private static function entries(array $post, array &$errors): array
    {
        $raw = $post['medication'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        if (count($raw) > self::MAX_ENTRIES) {
            $errors['medication'] = sprintf('Höchstens %d Arzneimittel je Fassung.', self::MAX_ENTRIES);
            $raw = array_slice($raw, 0, self::MAX_ENTRIES, true);
        }

        $entries = [];
        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $position = is_int($index) ? $index + 1 : (string) $index;
            $entry = [];
            foreach (self::ENTRY_FIELDS as $field => $maxLength) {
                $value = $row[$field] ?? '';
                $value = is_string($value) ? trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value)) : '';
                if (mb_strlen($value) > $maxLength) {
                    $errors['medication'] = sprintf('Zeile %s: Höchstens %d Zeichen je Feld.', $position, $maxLength);
                    $value = mb_substr($value, 0, $maxLength);
                }
                $entry[$field] = $value;
            }

            if (implode('', $entry) === '') {
                continue;
            }

            foreach (['from', 'to'] as $field) {
                if ($entry[$field] === '') {
                    continue;
                }
                $iso = DateInput::parse($entry[$field]);
                if ($iso === null) {
                    $errors['medication'] = sprintf('Zeile %s: "%s" ist kein gültiges Datum (erwartet TT.MM.JJJJ).', $position, $field === 'from' ? 'von' : 'bis');
                    continue;
                }
                $entry[$field] = $iso;
            }
            if ($entry['substance'] === '') {
                $errors['medication'] = sprintf('Zeile %s: Der Wirkstoff ist erforderlich.', $position);
            }
            if ($entry['from'] !== '' && $entry['to'] !== '' && $entry['to'] < $entry['from']) {
                $errors['medication'] = sprintf('Zeile %s: "bis" darf nicht vor "von" liegen.', $position);
            }

            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Inhaltsstruktur fuer die unveraenderliche Fassung.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->entries === [] ? ['text' => $this->text] : ['text' => $this->text, 'entries' => $this->entries];
    }

    /**
     * Textfassung des Inhalts (Anzeige, spaetere Verwendung im Brief).
     */
    public function contentText(): string
    {
        $lines = [];
        foreach ($this->entries as $entry) {
            $lines[] = self::entryLine($entry);
        }
        if ($this->text !== '') {
            $lines[] = $this->text;
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $entry
     */
    public static function entryLine(array $entry): string
    {
        $parts = [($entry['substance'] ?? '')];
        $dose = trim(($entry['dose'] ?? '') . ' ' . ($entry['unit'] ?? ''));
        if ($dose !== '') {
            $parts[] = $dose;
        }
        if (($entry['schedule'] ?? '') !== '') {
            $parts[] = $entry['schedule'];
        }
        $period = '';
        if (($entry['from'] ?? '') !== '' || ($entry['to'] ?? '') !== '') {
            $period = 'von ' . (($entry['from'] ?? '') === '' ? 'unbekannt' : DateInput::format($entry['from']))
                . ' bis ' . (($entry['to'] ?? '') === '' ? 'fortlaufend' : DateInput::format($entry['to']));
        }
        if ($period !== '') {
            $parts[] = $period;
        }
        if (($entry['reason'] ?? '') !== '') {
            $parts[] = 'Grund: ' . $entry['reason'];
        }
        return implode(' · ', $parts);
    }

    public function authorName(): ?string
    {
        return $this->authorName;
    }

    /**
     * Inhalt einer gespeicherten Fassung als Text (fuer die Anzeige).
     *
     * @param array<string, mixed> $content
     */
    public static function renderText(array $content): string
    {
        $lines = [];
        $entries = $content['entries'] ?? [];
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, string> $entry */
                    $lines[] = self::entryLine($entry);
                }
            }
        }
        $text = $content['text'] ?? '';
        if (is_string($text) && trim($text) !== '') {
            $lines[] = trim($text);
        }
        return implode("\n", $lines);
    }
}
