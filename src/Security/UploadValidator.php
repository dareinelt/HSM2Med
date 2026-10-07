<?php

declare(strict_types=1);

namespace App\Security;

use finfo;

/**
 * Prueft hochgeladene Dateien: Groesse, Endung (Whitelist), MIME-Typ und Inhalt.
 * Die Datei wird nur gelesen – nie ausgefuehrt oder unter dem Originalnamen gespeichert.
 */
final class UploadValidator
{
    public const array ALLOWED_EXTENSIONS = ['txt', 'log'];
    public const array ALLOWED_MIME_TYPES = ['text/plain', 'application/octet-stream', 'text/x-c', 'text/x-asm', 'text/csv'];

    /** Bekannte Signaturen von Binaer-/ausfuehrbaren Formaten */
    private const array FORBIDDEN_SIGNATURES = [
        "%PDF", "PK\x03\x04", "\x7FELF", "MZ", "<?php", "<?=", "\x89PNG", "\xFF\xD8\xFF", "GIF8", "\x1F\x8B", "Rar!", "7z\xBC\xAF",
    ];

    public function __construct(private readonly int $maxBytes)
    {
    }

    /**
     * @param array{name?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file Eintrag aus $_FILES
     * @return array{bytes: string, filename: string}
     * @throws UploadException
     */
    public function validateUpload(?array $file): array
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw new UploadException('Bitte eine Datei auswählen.');
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException($this->sizeMessage());
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Bitte eine Datei auswählen.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('Die Datei konnte nicht hochgeladen werden.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new UploadException('Ungültiger Upload.');
        }
        $size = filesize($tmp);
        if ($size === false || $size > $this->maxBytes) {
            throw new UploadException($this->sizeMessage());
        }
        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new UploadException('Die Datei konnte nicht gelesen werden.');
        }
        $filename = FileName::sanitize((string) ($file['name'] ?? ''));
        $this->validateContent($bytes, $filename);
        return ['bytes' => $bytes, 'filename' => $filename];
    }

    /**
     * Inhaltspruefung (auch fuer CLI-Import verwendet).
     *
     * @throws UploadException
     */
    public function validateContent(string $bytes, string $filename): void
    {
        if (strlen($bytes) > $this->maxBytes) {
            throw new UploadException($this->sizeMessage());
        }
        if ($bytes === '') {
            throw new UploadException('Die Datei ist leer.');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new UploadException('Nur Dateien mit der Endung .txt oder .log sind erlaubt.');
        }
        foreach (self::FORBIDDEN_SIGNATURES as $signature) {
            if (str_starts_with(ltrim(substr($bytes, 0, 64)), $signature)) {
                throw new UploadException('Der Dateiinhalt ist kein Merlin-Textexport.');
            }
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mime) || (!str_starts_with($mime, 'text/') && !in_array($mime, self::ALLOWED_MIME_TYPES, true))) {
            throw new UploadException('Der Dateityp wird nicht unterstützt.');
        }
        $isUtf16 = str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF");
        if (!str_contains($bytes, "\x1C")) {
            throw new UploadException('Die Datei enthält keine Merlin-Feldtrennzeichen (0x1C).');
        }
        if (!$isUtf16 && str_contains($bytes, "\x00")) {
            // NUL-Bytes sind in Textdateien unerwartet; der Parser meldet sie je Feld als Warnung.
            if (substr_count($bytes, "\x00") > strlen($bytes) / 10) {
                throw new UploadException('Der Dateiinhalt ist kein Merlin-Textexport.');
            }
        }
    }

    private function sizeMessage(): string
    {
        return sprintf('Die Datei ist zu groß (maximal %s).', self::formatBytes($this->maxBytes));
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 2) {
            return number_format($bytes / 1024 ** 2, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        return $bytes . ' Byte';
    }
}
