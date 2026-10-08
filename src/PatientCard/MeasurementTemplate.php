<?php

declare(strict_types=1);

namespace App\PatientCard;

use InvalidArgumentException;

/**
 * Vorlage der Messwerttabelle auf Seite 2 des Ausweises (config/patient_card_measurements.php).
 *
 * Die Klasse kennt nur die Vorlage und die Zuordnung der Zeilen zu den Quellparametern des
 * Merlin-Exports. Sie liefert die fertig aufgeloeste Tabelle, die im Snapshot des Ausweises
 * abgelegt wird; das PDF-Layout arbeitet danach ausschliesslich mit dieser Aufloesung.
 *
 * Zeilen ohne Quelle und Zellen ohne Wert bleiben leer. Es werden keine Werte erfunden und
 * keine medizinischen Bewertungen abgeleitet.
 */
final class MeasurementTemplate
{
    public const string FILE = '/config/patient_card_measurements.php';

    private string $version;
    private int $columns;
    /** @var list<array{label: string, groups: list<array{label: string, rows: list<array{label: string, chamber: string, glue: string, sources: list<array{ids: list<string>, names: list<string>}>}>}>}> */
    private array $sections = [];
    /** @var array<string, true> */
    private array $ids = [];
    /** @var array<string, true> */
    private array $names = [];
    /** @var list<string> */
    private array $parameterIds = [];
    /** @var list<string> */
    private array $parameterNames = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->version = (string) ($config['version'] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+$/', $this->version) !== 1) {
            throw new InvalidArgumentException('Version der Messwertvorlage fehlt oder ist ungueltig.');
        }

        $this->columns = (int) ($config['columns'] ?? 0);
        if ($this->columns < 2) {
            throw new InvalidArgumentException('Die Messwertvorlage benoetigt mindestens zwei Spalten.');
        }

        $sections = $config['sections'] ?? [];
        if (!is_array($sections) || $sections === []) {
            throw new InvalidArgumentException('Die Messwertvorlage enthaelt keine Abschnitte.');
        }
        foreach ($sections as $section) {
            $this->sections[] = $this->section((array) $section);
        }
        $this->parameterIds = array_keys($this->ids);
        $this->parameterNames = array_keys($this->names);
    }

    public static function fromFile(string $path): self
    {
        $config = require $path;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Vorlage der Messwerttabelle liefert kein Array.');
        }
        return new self($config);
    }

    public static function default(string $rootDir): self
    {
        return self::fromFile($rootDir . self::FILE);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function columnCount(): int
    {
        return $this->columns;
    }

    /**
     * Anzahl der frueheren Untersuchungen, die zusaetzlich zur aktuellen dargestellt werden.
     */
    public function previousCount(): int
    {
        return $this->columns - 1;
    }

    /**
     * @return list<array{label: string, groups: list<array{label: string, rows: list<array{label: string, chamber: string, glue: string, sources: list<array{ids: list<string>, names: list<string>}>}>}>}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * Alle Parameter-IDs der Vorlage (fuer eine gebuendelte Datenbankabfrage).
     *
     * @return list<string>
     */
    public function parameterIds(): array
    {
        return $this->parameterIds;
    }

    /**
     * Alle Parameterbezeichnungen der Vorlage, normalisiert (fuer eine gebuendelte Datenbankabfrage).
     *
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * Loest die Vorlage mit den Werten der Untersuchungen auf.
     *
     * @param list<array{report_id: int, date: ?string, date_display: string, current: bool}> $columns
     * @param array<int, array{ids: array<string, string>, names: array<string, string>}> $values Werte je Bericht
     * @return list<array{label: string, groups: list<array{label: string, rows: list<array{label: string, chamber: string, values: list<string>}>}>}>
     */
    public function resolve(array $columns, array $values): array
    {
        $sections = [];
        foreach ($this->sections as $section) {
            $groups = [];
            foreach ($section['groups'] as $group) {
                $rows = [];
                foreach ($group['rows'] as $row) {
                    $cells = [];
                    foreach ($columns as $column) {
                        $cells[] = $this->cell($row, $values[(int) $column['report_id']] ?? ['ids' => [], 'names' => []]);
                    }
                    $rows[] = [
                        'label' => $row['label'],
                        'chamber' => $row['chamber'],
                        'values' => $cells,
                    ];
                }
                $groups[] = ['label' => $group['label'], 'rows' => $rows];
            }
            $sections[] = ['label' => $section['label'], 'groups' => $groups];
        }
        return $sections;
    }

    /**
     * @param array{ids: array<string, string>, names: array<string, string>} $values
     */
    private function cell(array $row, array $values): string
    {
        $parts = [];
        foreach ($row['sources'] as $source) {
            $value = '';
            foreach ($source['ids'] as $id) {
                $value = trim((string) ($values['ids'][$id] ?? ''));
                if ($value !== '') {
                    break;
                }
            }
            if ($value === '') {
                foreach ($source['names'] as $name) {
                    $value = trim((string) ($values['names'][$name] ?? ''));
                    if ($value !== '') {
                        break;
                    }
                }
            }
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode($row['glue'], $parts);
    }

    /**
     * @param array<string, mixed> $section
     * @return array{label: string, groups: list<array{label: string, rows: list<array{label: string, chamber: string, glue: string, sources: list<array{ids: list<string>, names: list<string>}>}>}>}
     */
    private function section(array $section): array
    {
        $label = trim((string) ($section['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Abschnitt der Messwertvorlage ohne Beschriftung.');
        }
        $groups = [];
        foreach ((array) ($section['groups'] ?? []) as $group) {
            $groups[] = $this->group((array) $group);
        }
        if ($groups === []) {
            throw new InvalidArgumentException('Abschnitt ohne Gruppen: ' . $label);
        }
        return ['label' => $label, 'groups' => $groups];
    }

    /**
     * @param array<string, mixed> $group
     * @return array{label: string, rows: list<array{label: string, chamber: string, glue: string, sources: list<array{ids: list<string>, names: list<string>}>}>}
     */
    private function group(array $group): array
    {
        $label = trim((string) ($group['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Gruppe der Messwertvorlage ohne Beschriftung.');
        }
        $rows = [];
        foreach ((array) ($group['rows'] ?? []) as $row) {
            $rows[] = $this->row((array) $row, $label);
        }
        if ($rows === []) {
            throw new InvalidArgumentException('Gruppe ohne Zeilen: ' . $label);
        }
        return ['label' => $label, 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{label: string, chamber: string, glue: string, sources: list<array{ids: list<string>, names: list<string>}>}
     */
    private function row(array $row, string $group): array
    {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Zeile der Messwertvorlage ohne Beschriftung in Gruppe: ' . $group);
        }
        $sources = [];
        foreach ((array) ($row['sources'] ?? []) as $source) {
            $source = (array) $source;
            $ids = array_values(array_unique(array_map('strval', (array) ($source['ids'] ?? []))));
            $names = [];
            foreach ((array) ($source['names'] ?? []) as $name) {
                $names[] = mb_strtolower(trim((string) $name));
            }
            $names = array_values(array_unique(array_filter($names, static fn (string $n): bool => $n !== '')));
            if ($ids === [] && $names === []) {
                throw new InvalidArgumentException('Quelle ohne ID und Bezeichnung in Zeile: ' . $label);
            }
            foreach ($ids as $id) {
                $this->ids[$id] = true;
            }
            foreach ($names as $name) {
                $this->names[$name] = true;
            }
            $sources[] = ['ids' => $ids, 'names' => $names];
        }
        return [
            'label' => $label,
            'chamber' => trim((string) ($row['chamber'] ?? '')),
            'glue' => (string) ($row['glue'] ?? ''),
            'sources' => $sources,
        ];
    }
}
