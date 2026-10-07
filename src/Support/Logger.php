<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Technisches Fehlerprotokoll (JSON-Zeilen). Es werden bewusst keine Patientendaten protokolliert.
 */
final class Logger
{
    public function __construct(private readonly ?string $logFile)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $message, array $context = [], ?Throwable $exception = null): string
    {
        return $this->write('error', $message, $context, $exception);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function warning(string $message, array $context = []): string
    {
        return $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function info(string $message, array $context = []): string
    {
        return $this->write('info', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function write(string $level, string $message, array $context, ?Throwable $exception = null): string
    {
        $reference = bin2hex(random_bytes(6));
        $entry = [
            'time' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'ref' => $reference,
            'message' => $message,
            'context' => $context,
        ];
        if ($exception !== null) {
            $entry['exception'] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log('[hsm2med] ' . $line);
        if ($this->logFile !== null) {
            @file_put_contents($this->logFile, $line . "\n", FILE_APPEND | LOCK_EX);
        }
        return $reference;
    }
}
