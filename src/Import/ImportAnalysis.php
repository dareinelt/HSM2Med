<?php

declare(strict_types=1);

namespace App\Import;

use App\Mapping\CategoryAssignment;
use App\Report\ReportSummary;

/**
 * Ergebnis der Analyse einer hochgeladenen Datei (Grundlage fuer Importuebersicht und Speicherung).
 */
final readonly class ImportAnalysis
{
    /**
     * @param array<int, CategoryAssignment> $assignments Position => Kategorie
     * @param list<array{id: int, imported_at: string, status: string, report_id: ?int}> $previousImports
     */
    public function __construct(
        public string $filename,
        public int $fileSize,
        public string $fileHash,
        public ParseResult $parseResult,
        public array $assignments,
        public ReportSummary $summary,
        public ValidationResult $validation,
        public array $previousImports,
    ) {
    }

    /**
     * @return list<ImportIssue>
     */
    public function issues(): array
    {
        return [...$this->parseResult->issues, ...$this->validation->warnings];
    }

    public function errorCount(): int
    {
        return count(array_filter($this->issues(), static fn (ImportIssue $i): bool => $i->isError()));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->issues(), static fn (ImportIssue $i): bool => !$i->isError()));
    }

    public function isDuplicate(): bool
    {
        return $this->previousImports !== [];
    }

    public function status(): string
    {
        return match (true) {
            $this->errorCount() > 0 => 'completed_with_errors',
            $this->warningCount() > 0 => 'completed_with_warnings',
            default => 'completed',
        };
    }

    /**
     * Anzahl gueltiger Datensaetze je Kategorie (fuer die Vorschau).
     *
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        $counts = [];
        $sorted = $this->assignments;
        uasort($sorted, static fn (CategoryAssignment $a, CategoryAssignment $b): int => $a->sort <=> $b->sort);
        foreach ($sorted as $assignment) {
            $counts[$assignment->label] = ($counts[$assignment->label] ?? 0) + 1;
        }
        return $counts;
    }
}
