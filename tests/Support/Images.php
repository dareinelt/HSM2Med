<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Erzeugt echte Bilddateien (PNG/JPEG) fuer die Tests, ohne externe Bibliotheken und ohne GD.
 */
final class Images
{
    /**
     * Strukturell vollstaendiges PNG (Farbtyp 6 = RGBA oder 2 = RGB), nicht interlaced.
     */
    public static function png(int $width = 8, int $height = 8, bool $alpha = true): string
    {
        $channels = $alpha ? 4 : 3;
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                $raw .= chr(($x * 32) % 256) . chr(($y * 32) % 256) . "\x80";
                if ($alpha) {
                    $raw .= chr($x === 0 ? 0 : 255);
                }
            }
        }
        $header = pack('NNCCCCC', $width, $height, 8, $alpha ? 6 : 2, 0, 0, 0);
        $compressed = gzcompress($raw, 6);
        if ($compressed === false) {
            throw new \RuntimeException('PNG-Testdaten konnten nicht komprimiert werden.');
        }
        return \App\Report\Pdf\PngDecoder::SIGNATURE
            . self::chunk('IHDR', $header)
            . self::chunk('IDAT', $compressed)
            . self::chunk('IEND', '');
    }

    /**
     * Minimales JPEG mit JFIF-Kopf und SOF0-Frame (Graustufen-frei, 3 Komponenten).
     */
    public static function jpeg(int $width = 8, int $height = 8): string
    {
        $app0 = "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00" . pack('nn', 1, 1) . "\x00\x00";
        $frame = "\x08" . pack('nn', $height, $width) . "\x03"
            . "\x01\x11\x00" . "\x02\x11\x00" . "\x03\x11\x00";
        return "\xFF\xD8" . $app0 . "\xFF\xC0" . pack('n', 2 + strlen($frame)) . $frame . "\xFF\xD9";
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
