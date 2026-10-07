<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Ein unveraendert aus der Quelldatei gelesener Datensatz.
 * value/unit sind '' bei leeren Feldern (niemals NULL oder 0).
 */
final readonly class ParsedRecord
{
    public function __construct(
        public string $parameterId,
        public string $name,
        public ?string $value,
        public ?string $unit,
        public string $rawRecord,
        public int $position,
    ) {
    }

    /**
     * @return array{parameter_id: string, name: string, value: ?string, unit: ?string, raw_record: string, position: int}
     */
    public function toArray(): array
    {
        return [
            'parameter_id' => $this->parameterId,
            'name' => $this->name,
            'value' => $this->value,
            'unit' => $this->unit,
            'raw_record' => $this->rawRecord,
            'position' => $this->position,
        ];
    }
}
