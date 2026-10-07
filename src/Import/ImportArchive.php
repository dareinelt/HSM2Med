<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;
use RuntimeException;

/**
 * Archiv der Originaldateien: <verzeichnis>/<sha256>.txt (inhaltsadressiert, nie ausfuehrbar).
 * Optional – Berichte werden immer aus der Datenbank erzeugt.
 */
final class ImportArchive
{
    public function __construct(private readonly string $directory)
    {
    }

    public static function filenameFor(string $hash): string
    {
        self::assertHash($hash);
        return $hash . '.txt';
    }

    public function store(string $hash, string $bytes): string
    {
        self::assertHash($hash);
        if (!hash_equals($hash, hash('sha256', $bytes))) {
            throw new InvalidArgumentException('Hash passt nicht zum Dateiinhalt.');
        }
        $filename = self::filenameFor($hash);
        $target = $this->directory . '/' . $filename;
        if (is_file($target) && hash_equals($hash, (string) hash_file('sha256', $target))) {
            return $filename;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Archivverzeichnis kann nicht angelegt werden.');
        }
        $tmp = tempnam($this->directory, '.upload-');
        if ($tmp === false || file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new RuntimeException('Originaldatei konnte nicht archiviert werden.');
        }
        chmod($tmp, 0440);
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Originaldatei konnte nicht archiviert werden.');
        }
        return $filename;
    }

    public function exists(string $hash): bool
    {
        return is_file($this->directory . '/' . self::filenameFor($hash));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Ungueltiger SHA-256-Hash.');
        }
    }
}
