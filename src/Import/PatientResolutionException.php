<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Der Import erfordert eine ausdrueckliche oder aktualisierte Auswahl des Zielpatienten.
 */
final class PatientResolutionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?PatientResolution $resolution = null,
    ) {
        parent::__construct($message);
    }
}
