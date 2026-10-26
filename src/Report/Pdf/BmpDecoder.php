<?php

declare(strict_types=1);

namespace App\Report\Pdf;

use InvalidArgumentException;

/**
 * Minimaler BMP-Dekoder (Windows-Bitmap) fuer die PDF-Einbettung.
 *
 * Unterstuetzt werden unkomprimierte Dateien (BI_RGB) mit 1, 4, 8, 16, 24 und 32 Bit je Pixel
 * sowie BI_BITFIELDS (16/32 Bit). RLE-komprimierte Dateien und eingebettete JPEG-/PNG-Daten
 * werden abgelehnt, weil sie in Unterschriften nicht vorkommen. Die Abtastwerte werden als
 * DeviceRGB abgelegt; BMP kennt keine Transparenz, deshalb gibt es keine Weichmaske.
 */
final class BmpDecoder
{
    private const int MAX_DIMENSION = 10000;

    private const int BI_RGB = 0;

    private const int BI_BITFIELDS = 3;

    /** @var array<int, int> Vorgabewerte fuer 16-Bit-BMP ohne Farbmasken (5-5-5) */
    private const array DEFAULT_MASKS_16 = [0x7C00, 0x03E0, 0x001F];

    /** @var array<int, int> Vorgabewerte fuer 32-Bit-BMP ohne Farbmasken (8-8-8) */
    private const array DEFAULT_MASKS_32 = [0x00FF0000, 0x0000FF00, 0x000000FF];

    public static function decode(string $bytes): ImageData
    {
        $length = strlen($bytes);
        if ($length < 26 || substr($bytes, 0, 2) !== 'BM') {
            throw new InvalidArgumentException('BMP-Datei ist beschaedigt.');
        }
        $dataOffset = self::uint32($bytes, 10);
        $headerSize = self::uint32($bytes, 14);

        if ($headerSize === 12) {
            $width = self::uint16($bytes, 18);
            $height = self::uint16($bytes, 20);
            $bitCount = self::uint16($bytes, 24);
            $compression = self::BI_RGB;
            $colorsUsed = 0;
            $paletteEntrySize = 3;
            $paletteOffset = 26;
            $topDown = false;
        } else {
            if ($headerSize < 40 || $length < 54) {
                throw new InvalidArgumentException('BMP-Kopf wird nicht unterstuetzt.');
            }
            $width = self::int32($bytes, 18);
            $height = self::int32($bytes, 22);
            $bitCount = self::uint16($bytes, 28);
            $compression = self::uint32($bytes, 30);
            $colorsUsed = self::uint32($bytes, 46);
            $paletteEntrySize = 4;
            $paletteOffset = 14 + $headerSize;
            $topDown = $height < 0;
            $height = abs($height);
        }

        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new InvalidArgumentException('BMP-Abmessungen sind ungueltig.');
        }
        if (!in_array($bitCount, [1, 4, 8, 16, 24, 32], true)) {
            throw new InvalidArgumentException('BMP mit ' . $bitCount . ' Bit je Pixel wird nicht unterstuetzt.');
        }
        if ($compression !== self::BI_RGB && $compression !== self::BI_BITFIELDS) {
            throw new InvalidArgumentException('Komprimierte BMP-Dateien werden nicht unterstuetzt.');
        }
        if ($compression === self::BI_BITFIELDS && $bitCount !== 16 && $bitCount !== 32) {
            throw new InvalidArgumentException('BMP-Farbmasken werden nur bei 16 und 32 Bit unterstuetzt.');
        }

        $palette = '';
        if ($bitCount <= 8) {
            $entries = $colorsUsed > 0 ? min($colorsUsed, 1 << $bitCount) : 1 << $bitCount;
            $palette = substr($bytes, $paletteOffset, $entries * $paletteEntrySize);
            if (strlen($palette) < $entries * $paletteEntrySize) {
                throw new InvalidArgumentException('BMP-Farbtabelle ist unvollstaendig.');
            }
        }

        $masks = null;
        if ($bitCount === 16 || $bitCount === 32) {
            if ($compression === self::BI_BITFIELDS && $length >= 66) {
                $masks = [self::uint32($bytes, 54), self::uint32($bytes, 58), self::uint32($bytes, 62)];
                if ($masks[0] === 0 || $masks[1] === 0 || $masks[2] === 0) {
                    throw new InvalidArgumentException('BMP-Farbmasken sind ungueltig.');
                }
            } else {
                $masks = $bitCount === 16 ? self::DEFAULT_MASKS_16 : self::DEFAULT_MASKS_32;
            }
        }

        $rowBytes = (intdiv($width * $bitCount + 31, 32)) * 4;
        if ($dataOffset < 1 || $dataOffset + $rowBytes * ($height - 1) + $rowBytes > $length) {
            throw new InvalidArgumentException('BMP-Bilddaten sind unvollstaendig.');
        }

        $pixels = '';
        for ($y = 0; $y < $height; $y++) {
            $row = substr($bytes, $dataOffset + ($topDown ? $y : $height - 1 - $y) * $rowBytes, $rowBytes);
            for ($x = 0; $x < $width; $x++) {
                $pixels .= self::pixel($row, $x, $bitCount, $palette, $paletteEntrySize, $masks);
            }
        }

        return new ImageData(
            $width,
            $height,
            'DeviceRGB',
            8,
            'FlateDecode',
            PngDecoder::pack($pixels, $width * 3, $height),
            ['Predictor' => 15, 'Colors' => 3, 'BitsPerComponent' => 8, 'Columns' => $width],
        );
    }

    /**
     * @param array{0: int, 1: int, 2: int}|null $masks
     */
    private static function pixel(string $row, int $x, int $bitCount, string $palette, int $paletteEntrySize, ?array $masks): string
    {
        if ($bitCount === 24) {
            $offset = $x * 3;
            return $row[$offset + 2] . $row[$offset + 1] . $row[$offset];
        }
        if ($bitCount === 32) {
            $offset = $x * 4;
            $value = self::uint32($row, $offset);
            if ($masks === null) {
                return $row[$offset + 2] . $row[$offset + 1] . $row[$offset];
            }
            return chr(self::sample($value, $masks[0]))
                . chr(self::sample($value, $masks[1]))
                . chr(self::sample($value, $masks[2]));
        }
        if ($bitCount === 16) {
            $value = self::uint16($row, $x * 2);
            $masks ??= self::DEFAULT_MASKS_16;
            return chr(self::sample($value, $masks[0]))
                . chr(self::sample($value, $masks[1]))
                . chr(self::sample($value, $masks[2]));
        }

        $index = match ($bitCount) {
            8 => ord($row[$x]),
            4 => (ord($row[intdiv($x, 2)]) >> (($x % 2) === 0 ? 4 : 0)) & 0x0F,
            default => (ord($row[intdiv($x, 8)]) >> (7 - ($x % 8))) & 0x01,
        };
        $offset = $index * $paletteEntrySize;
        if ($offset + 2 >= strlen($palette)) {
            throw new InvalidArgumentException('BMP-Farbtabelle passt nicht zum Bild.');
        }
        return $palette[$offset + 2] . $palette[$offset + 1] . $palette[$offset];
    }

    private static function sample(int $value, int $mask): int
    {
        $shift = 0;
        while ((($mask >> $shift) & 1) === 0) {
            $shift++;
        }
        $bits = 0;
        $rest = $mask >> $shift;
        while (($rest & 1) === 1) {
            $bits++;
            $rest >>= 1;
        }
        $max = (1 << $bits) - 1;
        return $max === 255 ? ($value & $mask) >> $shift : (int) round((($value & $mask) >> $shift) * 255 / $max);
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return (int) unpack('v', substr($bytes, $offset, 2))[1];
    }

    private static function uint32(string $bytes, int $offset): int
    {
        return (int) unpack('V', substr($bytes, $offset, 4))[1];
    }

    private static function int32(string $bytes, int $offset): int
    {
        $value = self::uint32($bytes, $offset);
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
