<?php

declare(strict_types=1);

namespace App\Patient;

use App\Support\DateInput;

/**
 * Gepruefte und normalisierte Eingaben des Bausteins "Schrittmacher-/ICD-Abfrage".
 *
 * Aufbau des Inhalts (JSON):
 * {
 *   "template": "1.0.0",
 *   "device_type": "pacemaker",
 *   "values": {"device.manufacturer": "Medtronic", "brady.mode": "DDD", ...},
 *   "leads": [{"model": "...", "location": "RA", "implant_date": "2020-01-15", ...}],
 *   "notes": "Freitext"
 * }
 *
 * Regeln:
 *  * Der Geraetetyp bestimmt, welche Abschnitte, Felder und Sondenfelder zulaessig sind.
 *    Nicht zutreffende Angaben werden verworfen - es wird nie etwas erfunden.
 *  * Feldwerte sind Freitext (Einheit steht in der Beschriftung), Datumsfelder werden als
 *    ISO-Datum gespeichert, Auswahlfelder muessen einen der vorgesehenen Werte tragen.
 *  * Vollstaendig leere Sondenzeilen werden verworfen, eine Abfrage wird nie leer gespeichert.
 *  * Es findet keine Zahleninterpretation und keine medizinische Bewertung statt.
 */
final class DeviceCheckInput
{
    public const string KEY_DEVICE_TYPE = 'device_type';
    public const string KEY_VALUES = 'values';
    public const string KEY_LEADS = 'leads';
    public const string KEY_NOTES = 'notes';
    public const string KEY_TEMPLATE = 'template';

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $errors
     * @return array<string, mixed>|null null, wenn kein gueltiger Geraetetyp vorliegt
     */
    public static function normalize(array $post, DeviceCheckTemplate $template, array &$errors): ?array
    {
        $local = [];

        $deviceType = $post[self::KEY_DEVICE_TYPE] ?? '';
        $deviceType = is_string($deviceType) ? trim($deviceType) : '';
        if (!$template->hasDeviceType($deviceType)) {
            $errors[self::KEY_DEVICE_TYPE] = 'Bitte die Art des Geräts wählen.';
            return null;
        }

        $values = self::values($post[self::KEY_VALUES] ?? [], $template->fields($deviceType), $local);
        $leads = self::leads($post[self::KEY_LEADS] ?? [], $template, $deviceType, $local);

        // Angaben, die zur gewaehlten Geraeteart nicht gehoeren, werden nicht stillschweigend
        // verworfen: sie werden benannt, damit nichts unbemerkt verloren geht.
        $unexpected = self::unexpected($post, $template, $deviceType);
        if ($unexpected !== []) {
            $local[self::KEY_DEVICE_TYPE] = sprintf(
                'Für %s nicht vorgesehen: %s. Bitte die Art des Geräts prüfen.',
                $template->deviceTypeLabel($deviceType),
                implode(', ', $unexpected),
            );
        }

        $notes = $post[self::KEY_NOTES] ?? '';
        $notes = is_string($notes) ? self::clean($notes) : '';
        if (mb_strlen($notes) > $template->maxNotes()) {
            $local[self::KEY_NOTES] = sprintf('Höchstens %d Zeichen erlaubt.', $template->maxNotes());
            $notes = mb_substr($notes, 0, $template->maxNotes());
        }

        if ($local === [] && $values === [] && $leads === [] && $notes === '') {
            $local[self::KEY_VALUES] = 'Bitte mindestens eine Angabe zur Abfrage erfassen.';
        }

        $errors += $local;

        return [
            self::KEY_TEMPLATE => $template->version(),
            self::KEY_DEVICE_TYPE => $deviceType,
            self::KEY_VALUES => $values,
            self::KEY_LEADS => $leads,
            self::KEY_NOTES => $notes,
        ];
    }

    /**
     * Anzeige- und Formularwerte eines gespeicherten Inhalts (Daten als TT.MM.JJJJ).
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public static function describe(array $content, DeviceCheckTemplate $template): array
    {
        $deviceType = $content[self::KEY_DEVICE_TYPE] ?? '';
        $deviceType = is_string($deviceType) ? $deviceType : '';
        $fields = $template->hasDeviceType($deviceType) ? $template->fields($deviceType) : [];
        $leadFields = $template->hasDeviceType($deviceType) ? $template->leadFieldMap($deviceType) : [];

        $values = [];
        $raw = $content[self::KEY_VALUES] ?? [];
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            // Nur Felder, die fuer die Geraeteart vorgesehen sind; aeltere Fassungen einer
            // geaenderten Vorlage sollen keine Angaben zeigen, die nicht mehr gelten.
            $field = $fields[(string) $key] ?? null;
            if ($field === null) {
                continue;
            }
            $values[(string) $key] = $field['type'] === 'date' ? self::date($value) : $value;
        }

        $leads = [];
        $rawLeads = $content[self::KEY_LEADS] ?? [];
        foreach (is_array($rawLeads) ? $rawLeads : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lead = [];
            foreach ($leadFields as $name => $field) {
                $name = (string) $field['name'];
                $value = $row[$name] ?? '';
                $value = is_string($value) ? trim($value) : '';
                if ($value === '') {
                    continue;
                }
                $lead[$name] = $field['type'] === 'date' ? self::date($value) : $value;
            }
            if ($lead !== []) {
                $leads[] = $lead;
            }
        }

        $notes = $content[self::KEY_NOTES] ?? '';

        return [
            self::KEY_DEVICE_TYPE => $deviceType,
            'device_type_label' => $template->hasDeviceType($deviceType) ? $template->deviceTypeLabel($deviceType) : '',
            self::KEY_VALUES => $values,
            self::KEY_LEADS => $leads,
            self::KEY_NOTES => is_string($notes) ? $notes : '',
            'filled' => count($values) + array_sum(array_map('count', $leads)),
        ];
    }

    /**
     * Textfassung des Inhalts (Anzeige, Suche, spaetere Verwendung im Brief).
     *
     * @param array<string, mixed> $content
     */
    public static function renderText(array $content, DeviceCheckTemplate $template): string
    {
        $deviceType = $content[self::KEY_DEVICE_TYPE] ?? '';
        $deviceType = is_string($deviceType) ? $deviceType : '';
        if (!$template->hasDeviceType($deviceType)) {
            return '';
        }

        $described = self::describe($content, $template);
        $lines = ['Abfrage: ' . $template->deviceTypeLabel($deviceType)];

        foreach ($template->sectionsFor($deviceType) as $section) {
            if ($section['repeat'] === 'leads') {
                foreach ($described[self::KEY_LEADS] as $index => $lead) {
                    foreach ($template->leadFields($deviceType) as $field) {
                        $value = $lead[$field['name']] ?? '';
                        if ($value !== '') {
                            $lines[] = sprintf('%s · Sonde %d · %s: %s', $section['label'], $index + 1, $field['label'], $value);
                        }
                    }
                }
                continue;
            }
            foreach ($section['fields'] as $field) {
                $key = $section['key'] . '.' . $field['name'];
                $value = $described[self::KEY_VALUES][$key] ?? '';
                if ($value !== '') {
                    $lines[] = sprintf('%s · %s: %s', $section['label'], $field['label'], $value);
                }
            }
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $key = $section['key'] . '.' . $group['key'] . '.' . $field['name'];
                    $value = $described[self::KEY_VALUES][$key] ?? '';
                    if ($value !== '') {
                        $lines[] = sprintf('%s %s · %s: %s', $section['label'], $group['label'], $field['label'], $value);
                    }
                }
            }
        }

        $notes = $described[self::KEY_NOTES];
        if ($notes !== '') {
            $lines[] = 'Bemerkungen: ' . str_replace("\n", ' ', $notes);
        }

        return implode("\n", $lines);
    }

    /**
     * Beschriftungen der uebergebenen Felder, die fuer die Geraeteart nicht vorgesehen sind,
     * aber Werte tragen.
     *
     * @param array<string, mixed> $post
     * @return list<string>
     */
    private static function unexpected(array $post, DeviceCheckTemplate $template, string $deviceType): array
    {
        $allowed = $template->fields($deviceType);
        $labels = [];
        $values = $post[self::KEY_VALUES] ?? [];
        foreach (is_array($values) ? $values : [] as $key => $value) {
            $key = (string) $key;
            if (isset($allowed[$key]) || !is_string($value) || self::clean($value) === '') {
                continue;
            }
            $labels[$key] = self::label($key, $template);
        }

        $allowedLead = $template->leadFieldMap($deviceType);
        foreach (is_array($post[self::KEY_LEADS] ?? null) ? $post[self::KEY_LEADS] : [] as $row) {
            foreach (is_array($row) ? $row : [] as $name => $value) {
                $name = (string) $name;
                if (isset($allowedLead['leads.' . $name]) || !is_string($value) || self::clean($value) === '') {
                    continue;
                }
                $labels['leads.' . $name] = self::label('leads.' . $name, $template);
            }
        }

        return array_values($labels);
    }

    /**
     * Beschriftung eines Feldpfades, notfalls der Pfad selbst.
     */
    private static function label(string $key, DeviceCheckTemplate $template): string
    {
        if (str_starts_with($key, 'leads.')) {
            $name = substr($key, strlen('leads.'));
            foreach ($template->allLeadFields() as $field) {
                if ($field['name'] === $name) {
                    return (string) $field['label'];
                }
            }
            return $key;
        }
        $field = $template->allFields()[$key] ?? null;
        return $field === null ? $key : (string) $field['label'];
    }

    /**
     * @param mixed $raw
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private static function values(mixed $raw, array $fields, array &$errors): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            if (!is_array($raw) || !array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (!is_string($value)) {
                continue;
            }
            $value = self::clean($value);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $field['maxlength']) {
                $errors[self::KEY_VALUES] = sprintf('%s: Höchstens %d Zeichen erlaubt.', $field['label'], $field['maxlength']);
                $value = mb_substr($value, 0, $field['maxlength']);
            }
            if ($field['options'] !== null && !in_array($value, $field['options'], true)) {
                $errors[self::KEY_VALUES] = sprintf('%s: "%s" ist keine vorgesehene Auswahl.', $field['label'], $value);
                continue;
            }
            if ($field['type'] === 'date') {
                $iso = DateInput::parse($value);
                if ($iso === null) {
                    $errors[self::KEY_VALUES] = sprintf('%s: "%s" ist kein gültiges Datum (erwartet TT.MM.JJJJ).', $field['label'], $value);
                    continue;
                }
                $value = $iso;
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * @param mixed $raw
     * @param array<string, string> $errors
     * @return list<array<string, string>>
     */
    private static function leads(mixed $raw, DeviceCheckTemplate $template, string $deviceType, array &$errors): array
    {
        $rows = is_array($raw) ? $raw : [];
        if (count($rows) > $template->maxLeads()) {
            $errors[self::KEY_LEADS] = sprintf('Höchstens %d Sonden je Fassung.', $template->maxLeads());
            $rows = array_slice($rows, 0, $template->maxLeads(), true);
        }

        $leadFields = $template->leadFields($deviceType);
        $leads = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $position = is_int($index) ? $index + 1 : (string) $index;
            $lead = [];
            foreach ($leadFields as $field) {
                $name = (string) $field['name'];
                $value = $row[$name] ?? '';
                $value = is_string($value) ? self::clean($value) : '';
                if (mb_strlen($value) > $field['maxlength']) {
                    $errors[self::KEY_LEADS] = sprintf('Sonde %s: %s – höchstens %d Zeichen erlaubt.', $position, $field['label'], $field['maxlength']);
                    $value = mb_substr($value, 0, $field['maxlength']);
                }
                if ($value !== '' && $field['options'] !== null && !in_array($value, $field['options'], true)) {
                    $errors[self::KEY_LEADS] = sprintf('Sonde %s: %s – "%s" ist keine vorgesehene Auswahl.', $position, $field['label'], $value);
                    $value = '';
                }
                if ($value !== '' && $field['type'] === 'date') {
                    $iso = DateInput::parse($value);
                    if ($iso === null) {
                        $errors[self::KEY_LEADS] = sprintf('Sonde %s: %s – "%s" ist kein gültiges Datum (erwartet TT.MM.JJJJ).', $position, $field['label'], $value);
                        $value = '';
                    } else {
                        $value = $iso;
                    }
                }
                $lead[$name] = $value;
            }
            if (implode('', $lead) === '') {
                continue;
            }
            $leads[] = $lead;
        }
        return $leads;
    }

    private static function clean(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $value));
        return trim($value);
    }

    private static function date(string $iso): string
    {
        return DateInput::format($iso);
    }
}
