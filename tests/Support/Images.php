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

    /**
     * BMP mit 24 Bit je Pixel (BI_RGB, von unten nach oben, Zeilen auf 4 Byte ausgerichtet).
     */
    public static function bmp(int $width = 8, int $height = 8): string
    {
        $rowSize = (int) (ceil($width * 3 / 4) * 4);
        $pixels = '';
        for ($y = $height - 1; $y >= 0; $y--) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $row .= chr(($x * 32) % 256) . chr(($y * 32) % 256) . "\x80";
            }
            $pixels .= str_pad($row, $rowSize, "\x00");
        }
        $offset = 14 + 40;
        return 'BM'
            . pack('V', $offset + strlen($pixels))
            . pack('vv', 0, 0)
            . pack('V', $offset)
            . pack('V', 40)
            . pack('VV', $width, $height)
            . pack('vv', 1, 24)
            . pack('V', 0)
            . pack('V', strlen($pixels))
            . pack('VV', 2835, 2835)
            . pack('VV', 0, 0)
            . $pixels;
    }
}
