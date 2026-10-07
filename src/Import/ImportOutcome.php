<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ImportOutcome
{
    public function __construct(
        public int $importId,
        public int $reportId,
        public int $parameterCount,
        public string $status,
        public ?string $archiveFilename,
    ) {
    }
}
