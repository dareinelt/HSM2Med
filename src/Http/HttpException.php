<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Die angeforderte Seite wurde nicht gefunden.'): self
    {
        return new self(404, $message);
    }
}
