<?php

declare(strict_types=1);

namespace App\Security;

use App\Report\Pdf\ImageData;
use finfo;
use InvalidArgumentException;

/**
 * Prueft hochgeladene Logos (PNG/JPEG) fuer den Patientenausweis.
 *
 * Es wird ausschliesslich der Inhalt geprueft: Signatur, echte Abmessungen (ueber den eigenen
 * Decoder), Dateigroesse und MIME-Typ. PHP-, SVG- oder sonstige Skriptinhalte werden abgelehnt,
 * weil nur die Signatur von PNG/JPEG akzeptiert wird. Die Datei wird nie ausgefuehrt und nie
 * unter dem Originalnamen gespeichert.
 */
final class ImageUploadValidator
{
    public const int MAX_BYTES = 1048576;

    public const int MAX_DIMENSION = 2000;

    /** @var array<string, string> */
    private const array ALLOWED_MIME_TYPES = ['image/png' => 'image/png', 'image/jpeg' => 'image/jpeg'];

    public function __construct(private readonly int $maxBytes = self::MAX_BYTES)
    {
    }

    /**
     * @param array{name?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file Eintrag aus $_FILES
     * @return array{bytes: string, filename: string, mime_type: string, width: int, height: int}
     * @throws UploadException
     */
    public function validateUpload(?array $file): array
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw new UploadException('Bitte eine Logo-Datei auswählen.');
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Bitte eine Logo-Datei auswählen.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException($this->sizeMessage());
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('Das Logo konnte nicht hochgeladen werden.');
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
            throw new UploadException('Das Logo konnte nicht gelesen werden.');
        }
        $filename = FileName::sanitize((string) ($file['name'] ?? ''));
        return $this->validateContent($bytes, $filename);
    }

    /**
     * @return array{bytes: string, filename: string, mime_type: string, width: int, height: int}
     * @throws UploadException
     */
    public function validateContent(string $bytes, string $filename): array
    {
        if ($bytes === '') {
            throw new UploadException('Die Logo-Datei ist leer.');
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new UploadException($this->sizeMessage());
        }
        try {
            $image = ImageData::fromBytes($bytes);
        } catch (InvalidArgumentException $e) {
            throw new UploadException('Nur PNG- und JPEG-Dateien sind als Logo erlaubt.', 0, $e);
        }
        if ($image->width > self::MAX_DIMENSION || $image->height > self::MAX_DIMENSION) {
            throw new UploadException(sprintf(
                'Das Logo ist zu groß (maximal %d × %d Pixel).',
                self::MAX_DIMENSION,
                self::MAX_DIMENSION,
            ));
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            $expected = str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
            if ($mime !== $expected) {
                throw new UploadException('Der Dateityp des Logos wird nicht unterstützt (erlaubt: PNG, JPEG).');
            }
        }
        return [
            'bytes' => $bytes,
            'filename' => FileName::sanitize($filename === '' ? 'logo' : $filename),
            'mime_type' => $mime === '' ? (str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg') : $mime,
            'width' => $image->width,
            'height' => $image->height,
        ];
    }

    private function sizeMessage(): string
    {
        return sprintf('Das Logo ist zu groß (maximal %s).', UploadValidator::formatBytes($this->maxBytes));
    }
}
