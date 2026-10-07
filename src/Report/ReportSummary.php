<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Kopfdaten eines Berichts (Patient, Geraet, Sonden), abgeleitet aus den Quelldaten.
 * Werte sind Originalwerte; null = in der Quelldatei nicht vorhanden.
 */
final readonly class ReportSummary
{
    /**
     * @param array<string, array{value: string, unit: string, parameter_id: string, position: int}|null> $fields
     * @param list<array<string, mixed>> $leads
     */
    public function __construct(
        public array $fields,
        public array $leads,
    ) {
    }

    /**
     * Originalwert oder null, wenn nicht vorhanden.
     */
    public function value(string $key): ?string
    {
        return $this->fields[$key]['value'] ?? null;
    }

    /**
     * Wert fuer Stammdaten: leere Werte gelten als "nicht angegeben".
     */
    public function nonEmpty(string $key): ?string
    {
        $value = $this->value($key);
        return $value === null || trim($value) === '' ? null : $value;
    }

    public function unit(string $key): ?string
    {
        return $this->fields[$key]['unit'] ?? null;
    }

    public function sourceId(string $key): ?string
    {
        return $this->fields[$key]['parameter_id'] ?? null;
    }
}
