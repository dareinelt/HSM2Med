<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Gepruefte Patientenzuordnung eines Imports (Anzeige in der Vorschau und Absicherung beim Speichern).
 */
final readonly class PatientResolution
{
    /**
     * @param list<PatientResolutionOption> $options
     * @param array{id: int, name: string, date_of_birth: ?string, identifier: ?string}|null $targetPatient
     * @param array{id: int, name: string, date_of_birth: ?string, identifier: ?string}|null $activePatient
     * @param array{id: int, name: string, date_of_birth: ?string, identifier: ?string}|null $conflictingPatient
     */
    public function __construct(
        public PatientResolutionMode $mode,
        public ?string $defaultTarget,
        public string $summary,
        public string $reason,
        public array $options = [],
        public ?array $targetPatient = null,
        public ?array $activePatient = null,
        public ?array $conflictingPatient = null,
        public ?string $notice = null,
    ) {
    }

    public function requiresDecision(): bool
    {
        return $this->mode->requiresDecision();
    }

    public function accepts(string $target): bool
    {
        if ($target === '') {
            return false;
        }
        if ($this->requiresDecision()) {
            foreach ($this->options as $option) {
                if ($option->target === $target) {
                    return true;
                }
            }
            return false;
        }
        return $this->defaultTarget === $target;
    }

    public static function unassigned(): self
    {
        return new self(
            mode: PatientResolutionMode::NoPatient,
            defaultTarget: 'none',
            summary: 'Ohne Patientenzuordnung',
            reason: 'Keine Patientendaten in der Datei und kein aktiver Patient ausgewählt',
            options: [new PatientResolutionOption(target: 'none', label: 'Ohne Patientenzuordnung speichern')],
        );
    }
}
