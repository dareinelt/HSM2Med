<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;
use Throwable;

final class ImportFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $failedImportId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
