<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Eine auswaehlbare Zieloption fuer die Patientenzuordnung beim Import.
 */
final readonly class PatientResolutionOption
{
    public function __construct(
        public string $target,
        public string $label,
        public string $detail = '',
        public ?int $patientId = null,
        public bool $isActivePatient = false,
    ) {
    }
}
