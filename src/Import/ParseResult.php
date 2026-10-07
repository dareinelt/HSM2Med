<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ParseResult
{
    /**
     * @param list<ParsedRecord> $records gueltige Datensaetze in Originalreihenfolge
     * @param list<ImportIssue> $issues
     * @param int $recordCount Anzahl erkannter Datensaetze (gueltig + fehlerhaft)
     */
    public function __construct(
        public string $encoding,
        public array $records,
        public array $issues,
        public int $recordCount,
    ) {
    }

    public function validRecordCount(): int
    {
        return count($this->records);
    }

    /**
     * Anzahl fehlerhafter (nicht uebernommener) Datensaetze.
     */
    public function invalidRecordCount(): int
    {
        return count(array_filter($this->issues, static fn (ImportIssue $i): bool => $i->isError() && $i->position !== null));
    }

    /**
     * @return list<ImportIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i): bool => $i->isError()));
    }

    /**
     * @return list<ImportIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i): bool => !$i->isError()));
    }
}
