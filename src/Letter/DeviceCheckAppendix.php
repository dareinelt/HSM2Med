<?php

declare(strict_types=1);

namespace App\Letter;

use App\Patient\DeviceCheckInput;
use App\Patient\DeviceCheckTemplate;

/**
 * Baut die Anhangstabelle des Briefes aus einer Fassung der Schrittmacher-/ICD-Abfrage.
 *
 * Die Tabelle ist die vollstaendige Fassung des Wunschkatalogs: alle Abschnitte, die fuer die
 * gewaehlte Geraeteart vorgesehen sind und tatsaechlich Werte tragen. Nicht zutreffende
 * Abschnitte und leere Felder werden nicht ausgegeben - es wird nie etwas erfunden.
 *
 * Quelle der Beschriftungen und der Abschnittsauswahl ist ausschliesslich
 * config/device_check_template.php (ueber DeviceCheckTemplate), damit Formular, Akte und Brief
 * dieselbe Vorlage verwenden. Die Reihenfolge der Abschnitte entspricht der Vorlage.
 *
 * Die Angabe zur MRT-Tauglichkeit wird - abweichend von allen anderen Werten - aus dem
 * neuesten Patientenausweis uebernommen und dort gepflegt (eine Quelle). Sie wird im Brief
 * nur lesend gezeigt und mit der Herkunft gekennzeichnet.
 */
final class DeviceCheckAppendix
{
    /** Feldpfad der MRT-Tauglichkeit in der Vorlage (Abschnitt "device"). */
    public const string MRT_KEY = 'device.mrt_compatibility';

    /** Feldpfad der Zusatzangabe zur MRT-Tauglichkeit. */
    public const string MRT_NOTE_KEY = 'device.mrt_compatibility_note';

    public function __construct(private readonly DeviceCheckTemplate $template)
    {
    }

    /**
     * Anhangstabelle einer Abfrage.
     *
     * @param array<string, mixed> $described Ergebnis von DeviceCheckInput::describe()
     * @param array{value: string, note: string, source_label: string}|null $mrt Angabe aus dem
     *        neuesten Patientenausweis; ueberschreibt die Werte der Abfrage nur lesend
     * @return array{
     *     device_type: string,
     *     device_type_label: string,
     *     sections: list<array{key: string, label: string, rows: list<array{label: string, value: string}>}>,
     *     notes: string,
     *     filled: int
     * }
     */
    public function build(array $described, ?array $mrt = null): array
    {
        $deviceType = is_string($described['device_type'] ?? null) ? (string) $described['device_type'] : '';
        if (!$this->template->hasDeviceType($deviceType)) {
            return [
                'device_type' => $deviceType,
                'device_type_label' => '',
                'sections' => [],
                'notes' => '',
                'filled' => 0,
            ];
        }

        $values = [];
        foreach (is_array($described['values'] ?? null) ? $described['values'] : [] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $values[(string) $key] = $value;
            }
        }
        $labels = [];
        if ($mrt !== null) {
            $source = trim((string) $mrt['source_label']);
            $suffix = $source === '' ? '' : ' (' . $source . ')';
            if (trim((string) $mrt['value']) !== '') {
                $values[self::MRT_KEY] = trim((string) $mrt['value']);
                $labels[self::MRT_KEY] = 'MRT-Tauglichkeit' . $suffix;
            }
            if (trim((string) $mrt['note']) !== '') {
                $values[self::MRT_NOTE_KEY] = trim((string) $mrt['note']);
                $labels[self::MRT_NOTE_KEY] = 'MRT-Tauglichkeit: Zusatzangabe' . $suffix;
            }
        }

        $leads = [];
        foreach (is_array($described['leads'] ?? null) ? $described['leads'] : [] as $lead) {
            if (is_array($lead)) {
                $leads[] = $lead;
            }
        }

        $sections = [];
        $filled = 0;
        foreach ($this->template->sectionsFor($deviceType) as $section) {
            $rows = [];
            if (($section['repeat'] ?? null) === 'leads') {
                foreach ($leads as $index => $lead) {
                    foreach ($this->template->leadFields($deviceType) as $field) {
                        $value = trim((string) ($lead[(string) $field['name']] ?? ''));
                        if ($value !== '') {
                            $rows[] = ['label' => sprintf('Sonde %d – %s', $index + 1, (string) $field['label']), 'value' => $value];
                        }
                    }
                }
            } else {
                foreach ((array) ($section['fields'] ?? []) as $field) {
                    $key = $section['key'] . '.' . $field['name'];
                    $value = trim((string) ($values[$key] ?? ''));
                    if ($value !== '') {
                        $rows[] = ['label' => $labels[$key] ?? (string) $field['label'], 'value' => $value];
                    }
                }
                foreach ((array) ($section['groups'] ?? []) as $group) {
                    foreach ((array) ($group['fields'] ?? []) as $field) {
                        $key = $section['key'] . '.' . $group['key'] . '.' . $field['name'];
                        $value = trim((string) ($values[$key] ?? ''));
                        if ($value !== '') {
                            $rows[] = [
                                'label' => sprintf('%s – %s', (string) $group['label'], (string) $field['label']),
                                'value' => $value,
                            ];
                        }
                    }
                }
            }
            if ($rows === []) {
                continue;
            }
            $sections[] = ['key' => (string) $section['key'], 'label' => (string) $section['label'], 'rows' => $rows];
            $filled += count($rows);
        }

        $notes = is_string($described['notes'] ?? null) ? trim((string) $described['notes']) : '';

        return [
            'device_type' => $deviceType,
            'device_type_label' => $this->template->deviceTypeLabel($deviceType),
            'sections' => $sections,
            'notes' => $notes,
            'filled' => $filled,
        ];
    }

    /**
     * Textfassung des Anhangs (fuer die Anzeige im Browser; das PDF setzt die Tabelle selbst).
     *
     * @param array<string, mixed> $appendix
     * @return list<string>
     */
    public static function lines(array $appendix): array
    {
        $lines = [];
        foreach (is_array($appendix['sections'] ?? null) ? $appendix['sections'] : [] as $section) {
            $lines[] = (string) $section['label'];
            foreach (is_array($section['rows'] ?? null) ? $section['rows'] : [] as $row) {
                $lines[] = sprintf('  %s: %s', (string) $row['label'], (string) $row['value']);
            }
        }
        $notes = trim((string) ($appendix['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = 'Bemerkungen';
            foreach (explode("\n", $notes) as $line) {
                $lines[] = '  ' . $line;
            }
        }
        return $lines;
    }
}
