<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Protokolleintrag zu einem Datensatz oder zur gesamten Datei.
 * severity "error": Datensatz wurde NICHT uebernommen. severity "warning": Datensatz wurde uebernommen.
 */
final readonly class ImportIssue
{
    public const string ERROR = 'error';
    public const string WARNING = 'warning';

    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?int $position = null,
        public ?string $rawRecord = null,
    ) {
    }

    public static function error(string $code, string $message, ?int $position = null, ?string $rawRecord = null): self
    {
        return new self(self::ERROR, $code, $message, $position, $rawRecord);
    }

    public static function warning(string $code, string $message, ?int $position = null, ?string $rawRecord = null): self
    {
        return new self(self::WARNING, $code, $message, $position, $rawRecord);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }
}
