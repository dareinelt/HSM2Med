<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Vollstaendiger, aus der Datenbank geladener Bericht-Snapshot.
 * Grundlage fuer Webansicht und PDF – die Originaldatei wird nicht benoetigt.
 */
final readonly class ReportData
{
    /**
     * @param array<string, mixed> $report Zeile aus reports
     * @param array<string, mixed> $import Zeile aus imports
     * @param array<string, mixed> $summary dekodierter summary_snapshot
     * @param list<array<string, mixed>> $parameters report_parameters in Originalreihenfolge
     * @param list<array<string, mixed>> $issues import_errors
     */
    public function __construct(
        public array $report,
        public array $import,
        public array $summary,
        public array $parameters,
        public array $issues,
    ) {
    }

    public function id(): int
    {
        return (int) $this->report['id'];
    }

    public function reportVersion(): int
    {
        return (int) $this->report['report_version'];
    }

    /**
     * Parameter gruppiert nach gespeicherter Kategorie (Sortierung aus dem Snapshot).
     *
     * @return list<array{key: string, label: string, sort: int, parameters: list<array<string, mixed>>}>
     */
    public function categories(): array
    {
        $groups = [];
        foreach ($this->parameters as $parameter) {
            $key = (string) $parameter['category'];
            $groups[$key] ??= [
                'key' => $key,
                'label' => (string) $parameter['category_label'],
                'sort' => (int) $parameter['category_sort'],
                'parameters' => [],
            ];
            $groups[$key]['parameters'][] = $parameter;
        }
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]);
        return $groups;
    }

    /**
     * Alle Datensaetze (gueltige Parameter und fehlerhafte Rohdatensaetze) in Originalreihenfolge.
     *
     * @return list<array{position: int, valid: bool, parameter: ?array<string, mixed>, raw: string}>
     */
    public function recordsInOriginalOrder(): array
    {
        $records = [];
        foreach ($this->parameters as $parameter) {
            $records[] = ['position' => (int) $parameter['original_position'], 'valid' => true, 'parameter' => $parameter, 'raw' => (string) $parameter['raw_record']];
        }
        foreach ($this->issues as $issue) {
            if ($issue['severity'] === 'error' && $issue['record_position'] !== null) {
                $records[] = ['position' => (int) $issue['record_position'], 'valid' => false, 'parameter' => null, 'raw' => (string) $issue['raw_record']];
            }
        }
        usort($records, static fn (array $a, array $b): int => [$a['position'], $a['valid'] ? 0 : 1] <=> [$b['position'], $b['valid'] ? 0 : 1]);
        return $records;
    }

    /**
     * @return list<array{label: string, value: ?string, unit: string, original: ?string, source_parameter_id: ?string}>
     */
    public function summarySection(string $key): array
    {
        foreach ($this->summary['sections'] ?? [] as $section) {
            if (($section['key'] ?? null) === $key) {
                return $section['rows'];
            }
        }
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function leads(): array
    {
        return $this->summary['leads'] ?? [];
    }
}
