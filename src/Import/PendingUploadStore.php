<?php

declare(strict_types=1);

namespace App\Import;

use App\Support\Clock;
use RuntimeException;

/**
 * Zwischenspeicher fuer hochgeladene Dateien zwischen Importuebersicht und endgueltigem Speichern.
 *
 * Dateien liegen ausserhalb des DocumentRoots unter zufaelligen Tokens; der vom Benutzer gelieferte
 * Dateiname wird nie als Pfad verwendet. Abgelaufene Eintraege werden automatisch entfernt.
 */
final class PendingUploadStore
{
    public const int TTL_SECONDS = 3600;
    private const string TOKEN_PATTERN = '/^[a-f0-9]{32}$/D';

    public function __construct(
        private readonly string $directory,
        private readonly Clock $clock,
    ) {
    }

    public function store(string $bytes, string $filename): string
    {
        $this->ensureDirectory();
        $this->purgeExpired();
        $token = bin2hex(random_bytes(16));
        $meta = json_encode([
            'filename' => $filename,
            'hash' => hash('sha256', $bytes),
            'size' => strlen($bytes),
            'created' => $this->clock->now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->write($this->path($token, 'bin'), $bytes);
        $this->write($this->path($token, 'json'), $meta);
        return $token;
    }

    /**
     * @return array{bytes: string, filename: string, hash: string, size: int, created: int}|null
     */
    public function load(string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }
        $metaPath = $this->path($token, 'json');
        $binPath = $this->path($token, 'bin');
        if (!is_file($metaPath) || !is_file($binPath)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta) || !isset($meta['filename'], $meta['hash'], $meta['created'])) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - (int) $meta['created'] > self::TTL_SECONDS) {
            $this->delete($token);
            return null;
        }
        $bytes = (string) file_get_contents($binPath);
        if (!hash_equals((string) $meta['hash'], hash('sha256', $bytes))) {
            return null;
        }
        return [
            'bytes' => $bytes,
            'filename' => (string) $meta['filename'],
            'hash' => (string) $meta['hash'],
            'size' => strlen($bytes),
            'created' => (int) $meta['created'],
        ];
    }

    public function delete(string $token): void
    {
        if (!self::isValidToken($token)) {
            return;
        }
        foreach (['bin', 'json'] as $extension) {
            $path = $this->path($token, $extension);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function purgeExpired(): void
    {
        $limit = $this->clock->now()->getTimestamp() - self::TTL_SECONDS;
        foreach (glob($this->directory . '/*.{bin,json}', GLOB_BRACE) ?: [] as $file) {
            if (preg_match('/^[a-f0-9]{32}\.(bin|json)$/D', basename($file)) === 1 && (int) filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    private function path(string $token, string $extension): string
    {
        return $this->directory . '/' . $token . '.' . $extension;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Upload-Zwischenspeicher kann nicht angelegt werden.');
        }
    }

    private function write(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Upload konnte nicht zwischengespeichert werden.');
        }
        @chmod($path, 0600);
    }
}
