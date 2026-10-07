<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Bereinigt vom Benutzer gelieferte Dateinamen. Diese werden nur angezeigt und
 * niemals als Pfad verwendet.
 */
final class FileName
{
    public static function sanitize(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
        }
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, " .\t");
        if ($name === '') {
            return 'upload.txt';
        }
        return mb_substr($name, 0, 200);
    }

    /**
     * Sicherer Dateiname fuer Downloads (ASCII).
     */
    public static function downloadName(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $ascii = trim($ascii, '._');
        return $ascii === '' ? 'download' : substr($ascii, 0, 120);
    }
}
