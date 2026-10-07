<?php

declare(strict_types=1);

namespace App\Import;

final readonly class ValidationResult
{
    /**
     * @param list<string> $blockingErrors Gruende, die einen Import verhindern
     * @param list<ImportIssue> $warnings
     */
    public function __construct(
        public array $blockingErrors,
        public array $warnings,
    ) {
    }

    public function isValid(): bool
    {
        return $this->blockingErrors === [];
    }
}
