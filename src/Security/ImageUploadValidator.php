<?php

declare(strict_types=1);

namespace App\Security;

use App\Report\Pdf\ImageData;
use finfo;
use InvalidArgumentException;

/**
 * Prueft hochgeladene Bilddateien (Logo der Stammdaten, Unterschrift der Benutzer).
 *
 * Es wird ausschliesslich der Inhalt geprueft: Signatur, echte Abmessungen (ueber den eigenen
 * Decoder), Dateigroesse und MIME-Typ. PHP-, SVG- oder sonstige Skriptinhalte werden abgelehnt,
 * weil nur die Signatur von PNG/JPEG (und auf Wunsch BMP) akzeptiert wird. Die Datei wird nie
 * ausgefuehrt und nie unter dem Originalnamen gespeichert.
 *
 * BMP ist nur fuer Unterschriften freigegeben ($allowBmp); das Logo der Stammdaten bleibt bei
 * PNG/JPEG, damit bestehende Vorgaben unveraendert gelten.
 */
final class ImageUploadValidator
{
    public const int MAX_BYTES = 1048576;

    public const int MAX_DIMENSION = 2000;

    /** @var array<string, string> */
    private const array ALLOWED_MIME_TYPES = [
        'image/png' => 'image/png',
        'image/jpeg' => 'image/jpeg',
        'image/bmp' => 'image/bmp',
        'image/x-ms-bmp' => 'image/bmp',
    ];

    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly bool $allowBmp = false,
        private readonly string $fallbackFilename = 'logo',
    ) {
    }

    /**
     * @param array{name?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file Eintrag aus $_FILES
     * @return array{bytes: string, filename: string, mime_type: string, width: int, height: int}
     * @throws UploadException
     */
    public function validateUpload(?array $file): array
    {
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            throw new UploadException('Bitte eine Bilddatei auswählen.');
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Bitte eine Bilddatei auswählen.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException($this->sizeMessage());
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
        return $this->validateContent($bytes, $filename);
    }

    /**
     * @return array{bytes: string, filename: string, mime_type: string, width: int, height: int}
     * @throws UploadException
     */
    public function validateContent(string $bytes, string $filename): array
    {
        if ($bytes === '') {
            throw new UploadException('Die Bilddatei ist leer.');
        }
        if (strlen($bytes) > $this->maxBytes) {
            throw new UploadException($this->sizeMessage());
        }
        $expected = $this->expectedMimeType($bytes);
        if ($expected === null) {
            throw new UploadException($this->formatMessage());
        }
        try {
            $image = ImageData::fromBytes($bytes);
        } catch (InvalidArgumentException $e) {
            throw new UploadException($this->formatMessage(), 0, $e);
        }
        if ($image->width > self::MAX_DIMENSION || $image->height > self::MAX_DIMENSION) {
            throw new UploadException(sprintf(
                'Das Bild ist zu groß (maximal %d × %d Pixel).',
                self::MAX_DIMENSION,
                self::MAX_DIMENSION,
            ));
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($mime) ? $mime : '';
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            if ($mime !== $expected) {
                throw new UploadException($this->typeMessage());
            }
            $mime = $expected;
        }
        $mime = self::ALLOWED_MIME_TYPES[$mime];
        if ($mime === 'image/bmp' && !$this->allowBmp) {
            throw new UploadException($this->formatMessage());
        }
        return [
            'bytes' => $bytes,
            'filename' => FileName::sanitize($filename === '' ? $this->fallbackFilename : $filename),
            'mime_type' => $mime,
            'width' => $image->width,
            'height' => $image->height,
        ];
    }

    /**
     * Ermittelt den erwarteten MIME-Typ aus den ersten Bytes (null: kein erlaubtes Format).
     */
    private function expectedMimeType(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if ($this->allowBmp && str_starts_with($bytes, 'BM') && strlen($bytes) > 14) {
            return 'image/bmp';
        }
        return null;
    }

    private function formatMessage(): string
    {
        return $this->allowBmp
            ? 'Nur PNG-, JPEG- und BMP-Dateien sind erlaubt.'
            : 'Nur PNG- und JPEG-Dateien sind erlaubt.';
    }

    private function typeMessage(): string
    {
        return $this->allowBmp
            ? 'Der Dateityp wird nicht unterstützt (erlaubt: PNG, JPEG, BMP).'
            : 'Der Dateityp wird nicht unterstützt (erlaubt: PNG, JPEG).';
    }

    private function sizeMessage(): string
    {
        return sprintf('Das Bild ist zu groß (maximal %s).', UploadValidator::formatBytes($this->maxBytes));
    }
}
