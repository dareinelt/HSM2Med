<?php

declare(strict_types=1);

namespace App\Letter;

use App\PatientCard\MeasurementTemplate;
use App\PatientCard\PatientCardInput;
use App\PatientCard\PatientCardRepository;
use App\Report\ReportData;

/**
 * Messwerttabelle des Befundbriefes (letzte Seite).
 *
 * Zeilen sind die Messwertbezeichner des festen Katalogs (config/patient_card_measurements.php,
 * derselbe Katalog wie auf Seite 2 des Patientenausweises). Spalten sind die Untersuchungen:
 * die aktuelle Untersuchung steht ganz rechts, davor die letzten frueheren Untersuchungen
 * desselben Patienten. Wie viele fruehere Untersuchungen hoechstens dargestellt werden, stellt
 * der Administrator in der Briefvorlage ein (Standard 4).
 *
 * Welche Messwerte im Brief erscheinen, waehlt der Anwender im Brief-Assistenten per
 * Checkbox-Liste aus den Werten, die in den Untersuchungen tatsaechlich vorliegen. Die Auswahl
 * wird beim Erzeugen aufgeloest und im Snapshot eingefroren; eine geaenderte Auswahl ergibt
 * einen neuen Brief (neue Briefnummer, neue Fassung). Das PDF arbeitet ausschliesslich mit der
 * eingefrorenen Aufloesung; es werden keine Werte erfunden und keine Werte bewertet.
 */
final class LetterMeasurements
{
    /** Standardanzahl frueherer Untersuchungen (Spalten links der aktuellen Untersuchung). */
    public const int DEFAULT_PREVIOUS = 4;

    /** Obergrenze der in der Briefvorlage waehlbaren frueheren Untersuchungen. */
    public const int MAX_PREVIOUS = 10;

    public function __construct(
        private readonly MeasurementTemplate $template,
        private readonly PatientCardRepository $reports,
    ) {
    }

    /**
     * In der Briefvorlage eingestellte Anzahl frueherer Untersuchungen. Ungueltige oder fehlende
     * Angaben fallen auf den Standard zurueck (die Vorlage selbst wird separat geprueft).
     */
    public static function previousCount(mixed $templateContent): int
    {
        $raw = is_array($templateContent)
            ? ($templateContent['zones']['measurement_table']['options']['previous'] ?? null)
            : null;
        $value = is_int($raw) || is_string($raw) ? trim((string) $raw) : '';
        if (preg_match('/^\d{1,2}$/', $value) !== 1) {
            return self::DEFAULT_PREVIOUS;
        }
        return max(0, min(self::MAX_PREVIOUS, (int) $value));
    }

    /**
     * Loest den Katalog mit den Werten der Untersuchungen auf. Gelesen werden bis zu
     * MAX_PREVIOUS fruehere Untersuchungen; die Spalten werden erst beim Einfrieren des Briefes
     * auf die in der Vorlage eingestellte Anzahl gekuerzt (freeze()).
     *
     * @return array<string, mixed> Snapshot-Teil "measurements"
     */
    public function resolve(int $patientId, ?ReportData $report): array
    {
        $currentId = $report?->id();
        $history = $this->reports->pastReports($patientId, $currentId ?? 0, self::MAX_PREVIOUS);

        $columns = [];
        foreach (array_reverse($history) as $row) {
            $columns[] = self::column((int) $row['id'], $row, false);
        }
        if ($report !== null) {
            $columns[] = self::column($currentId ?? 0, $report->report, true);
        }
        if ($columns === []) {
            return self::emptyPart($this->template->version(), self::MAX_PREVIOUS);
        }

        $values = $this->reports->measurementValues(
            array_map(static fn (array $column): int => $column['report_id'], $columns),
            $this->template->parameterIds(),
            $this->template->parameterNames(),
        );

        $available = [];
        $sections = [];
        foreach ($this->template->resolve($columns, $values) as $sectionIndex => $section) {
            $groups = [];
            foreach ($section['groups'] as $groupIndex => $group) {
                $rows = [];
                foreach ($group['rows'] as $rowIndex => $row) {
                    $key = $sectionIndex . '.' . $groupIndex . '.' . $rowIndex;
                    $hasValue = false;
                    foreach ($row['values'] as $value) {
                        if (trim((string) $value) !== '') {
                            $hasValue = true;
                            break;
                        }
                    }
                    $rows[] = [
                        'key' => $key,
                        'label' => (string) $row['label'],
                        'chamber' => (string) $row['chamber'],
                        'values' => array_values(array_map('strval', $row['values'])),
                        'available' => $hasValue,
                    ];
                    if ($hasValue) {
                        $available[] = $key;
                    }
                }
                $groups[] = [
                    'key' => $sectionIndex . '.' . $groupIndex,
                    'label' => (string) $group['label'],
                    'rows' => $rows,
                ];
            }
            $sections[] = [
                'key' => (string) $sectionIndex,
                'label' => (string) $section['label'],
                'groups' => $groups,
            ];
        }

        return [
            'include' => true,
            'present' => $available !== [],
            'template_version' => $this->template->version(),
            'previous_count' => self::MAX_PREVIOUS,
            'column_count' => count($columns),
            'columns' => $columns,
            'sections' => $sections,
            'available' => $available,
            'selected' => $available,
        ];
    }

    /**
     * Friert die Tabelle fuer den Brief ein: Spalten auf die in der Vorlage eingestellte Anzahl
     * kuerzen (die aktuelle Untersuchung bleibt ganz rechts), Zeilen auf die Auswahl beschraenken
     * und Zeilen ohne Wert in den verbliebenen Spalten weglassen.
     *
     * @param array<string, mixed> $measurements Ergebnis von resolve()
     * @param list<string>|null $selected Auswahl aus dem Assistenten; null = alle verfuegbaren
     * @return array<string, mixed> Snapshot-Teil "measurements"
     */
    public static function freeze(array $measurements, int $previousCount, ?array $selected, bool $include): array
    {
        $previousCount = max(0, min(self::MAX_PREVIOUS, $previousCount));
        $version = (string) ($measurements['template_version'] ?? '');
        $available = array_values(array_filter(
            array_map('strval', (array) ($measurements['available'] ?? [])),
            static fn (string $key): bool => $key !== '',
        ));
        $chosen = $selected === null
            ? $available
            : array_values(array_intersect($available, array_map('strval', $selected)));
        if (!$include) {
            return self::emptyPart($version, $previousCount, $available, false);
        }
        if ($chosen === []) {
            return self::emptyPart($version, $previousCount, $available);
        }

        $columns = array_values(array_filter((array) ($measurements['columns'] ?? []), 'is_array'));
        $keep = $previousCount + 1;
        if (count($columns) > $keep) {
            $columns = array_slice($columns, -$keep);
        }
        if ($columns === []) {
            return self::emptyPart($version, $previousCount, $available);
        }

        $shown = [];
        $sections = [];
        foreach ((array) ($measurements['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $groups = [];
            foreach ((array) ($section['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $rows = [];
                foreach ((array) ($group['rows'] ?? []) as $row) {
                    if (!is_array($row) || !in_array((string) ($row['key'] ?? ''), $chosen, true)) {
                        continue;
                    }
                    $values = array_slice(array_values(array_map('strval', (array) ($row['values'] ?? []))), -$keep);
                    if (array_filter($values, static fn (string $value): bool => trim($value) !== '') === []) {
                        continue;
                    }
                    $rows[] = [
                        'key' => (string) $row['key'],
                        'label' => (string) ($row['label'] ?? ''),
                        'chamber' => (string) ($row['chamber'] ?? ''),
                        'values' => $values,
                    ];
                    $shown[] = (string) $row['key'];
                }
                if ($rows !== []) {
                    $groups[] = ['key' => (string) ($group['key'] ?? ''), 'label' => (string) ($group['label'] ?? ''), 'rows' => $rows];
                }
            }
            if ($groups !== []) {
                $sections[] = ['key' => (string) ($section['key'] ?? ''), 'label' => (string) ($section['label'] ?? ''), 'groups' => $groups];
            }
        }

        if ($sections === []) {
            return self::emptyPart($version, $previousCount, $available);
        }

        return [
            'include' => true,
            'present' => true,
            'template_version' => $version,
            'previous_count' => $previousCount,
            'column_count' => count($columns),
            'columns' => array_values(array_map(static fn (array $column): array => [
                'report_id' => (int) ($column['report_id'] ?? 0),
                'date' => $column['date'] ?? null,
                'date_display' => (string) ($column['date_display'] ?? ''),
                'current' => ($column['current'] ?? false) === true,
            ], $columns)),
            'sections' => $sections,
            'available' => $available,
            'selected' => $shown,
        ];
    }

    /**
     * Auswahl fuer den Assistenten: alle Zeilen mit Beschriftung und der Angabe, ob in den
     * Untersuchungen ein Wert vorliegt.
     *
     * @param array<string, mixed> $measurements Ergebnis von resolve()
     * @return list<array{key: string, label: string, available: bool, values: list<string>}>
     */
    public static function choices(array $measurements): array
    {
        $choices = [];
        foreach ((array) ($measurements['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ((array) ($section['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach ((array) ($group['rows'] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $choices[] = [
                        'key' => (string) ($row['key'] ?? ''),
                        'label' => (string) ($row['label'] ?? ''),
                        'available' => ($row['available'] ?? false) === true,
                        'values' => array_values(array_map('strval', (array) ($row['values'] ?? []))),
                    ];
                }
            }
        }
        return $choices;
    }

    /**
     * Leere Tabelle (keine Untersuchung, keine Messwerte oder Tabelle abgewaehlt).
     *
     * @param list<string> $available
     * @return array<string, mixed>
     */
    private static function emptyPart(string $version, int $previousCount, array $available = [], bool $include = true): array
    {
        return [
            'include' => $include,
            'present' => false,
            'template_version' => $version,
            'previous_count' => max(0, min(self::MAX_PREVIOUS, $previousCount)),
            'column_count' => 0,
            'columns' => [],
            'sections' => [],
            'available' => $available,
            'selected' => [],
        ];
    }

    /**
     * @param array<string, mixed> $row Bericht oder Untersuchung (session_timestamp, ...)
     * @return array{report_id: int, date: ?string, date_display: string, current: bool}
     */
    private static function column(int $reportId, array $row, bool $current): array
    {
        $date = $row['session_timestamp'] ?? $row['interrogation_timestamp'] ?? $row['created_at'] ?? null;
        $date = $date === null || (string) $date === '' ? null : substr((string) $date, 0, 10);
        return [
            'report_id' => $reportId,
            'date' => $date,
            'date_display' => PatientCardInput::formatDate($date),
            'current' => $current,
        ];
    }
}
