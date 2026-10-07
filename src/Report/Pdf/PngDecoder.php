<?php

declare(strict_types=1);

namespace App\Report\Pdf;

use InvalidArgumentException;

/**
 * Minimaler PNG-Dekoder fuer die PDF-Einbettung (keine externen Bibliotheken).
 *
 * Es werden ausschliesslich die fuer ein Logo noetigen Varianten unterstuetzt:
 * Farbtypen 0 (Graustufen), 2 (RGB), 3 (Palette), 4 (Graustufen + Alpha), 6 (RGBA),
 * Bittiefen 1/2/4/8/16, nicht interlaced. Die Abtastwerte werden auf 8 Bit reduziert und
 * mit dem PNG-Praediktor (Predictor 15) an den PDF-Writer uebergeben.
 */
final class PngDecoder
{
    public const string SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const array CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    public static function decode(string $bytes): ImageData
    {
        $chunks = self::chunks($bytes);
        $header = $chunks['IHDR'] ?? throw new InvalidArgumentException('PNG ohne IHDR-Kopf.');
        if (strlen($header) < 13) {
            throw new InvalidArgumentException('PNG-Kopf ist unvollstaendig.');
        }
        $width = (int) unpack('N', substr($header, 0, 4))[1];
        $height = (int) unpack('N', substr($header, 4, 4))[1];
        $bitDepth = ord($header[8]);
        $colorType = ord($header[9]);
        $compression = ord($header[10]);
        $filterMethod = ord($header[11]);
        $interlace = ord($header[12]);

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('PNG hat ungueltige Abmessungen.');
        }
        if (!isset(self::CHANNELS[$colorType])) {
            throw new InvalidArgumentException('PNG-Farbtyp ' . $colorType . ' wird nicht unterstuetzt.');
        }
        if (!in_array($bitDepth, [1, 2, 4, 8, 16], true)) {
            throw new InvalidArgumentException('PNG-Bittiefe ' . $bitDepth . ' wird nicht unterstuetzt.');
        }
        if ($compression !== 0 || $filterMethod !== 0) {
            throw new InvalidArgumentException('PNG-Komprimierung wird nicht unterstuetzt.');
        }
        if ($interlace !== 0) {
            throw new InvalidArgumentException('Interlaced PNG wird nicht unterstuetzt.');
        }
        if ($chunks['IDAT'] === '') {
            throw new InvalidArgumentException('PNG enthaelt keine Bilddaten.');
        }

        $inflated = @zlib_decode($chunks['IDAT']);
        if ($inflated === false || $inflated === '') {
            throw new InvalidArgumentException('PNG-Bilddaten sind nicht lesbar.');
        }

        $channels = self::CHANNELS[$colorType];
        $bytesPerPixel = max(1, (int) intdiv($channels * $bitDepth + 7, 8));
        $stride = (int) intdiv($width * $channels * $bitDepth + 7, 8);
        $rows = self::unfilter($inflated, $height, $stride, $bytesPerPixel);

        return self::build($rows, $width, $height, $bitDepth, $colorType, $channels, $chunks);
    }

    /**
     * @return array<string, string> Chunk-Name => Nutzdaten (IDAT zusammengefasst)
     */
    private static function chunks(string $bytes): array
    {
        $offset = strlen(self::SIGNATURE);
        $length = strlen($bytes);
        $chunks = ['IDAT' => ''];
        while ($offset + 8 <= $length) {
            $size = (int) unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $size);
            if (strlen($data) !== $size) {
                throw new InvalidArgumentException('PNG-Datei ist beschaedigt.');
            }
            if ($type === 'IDAT') {
                $chunks['IDAT'] .= $data;
            } elseif ($type === 'IEND') {
                break;
            } else {
                $chunks[$type] = $data;
            }
            $offset += 12 + $size;
        }
        return $chunks;
    }

    /**
     * Entfernt die PNG-Zeilenfilter (0 None, 1 Sub, 2 Up, 3 Average, 4 Paeth).
     *
     * @return list<string> eine Zeile je Bildzeile
     */
    private static function unfilter(string $data, int $height, int $stride, int $bytesPerPixel): array
    {
        $rows = [];
        $previous = str_repeat("\x00", $stride);
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            if ($offset + 1 + $stride > strlen($data)) {
                throw new InvalidArgumentException('PNG-Bilddaten sind unvollstaendig.');
            }
            $filter = ord($data[$offset]);
            $offset++;
            $row = substr($data, $offset, $stride);
            $offset += $stride;
            $row = self::unfilterRow($filter, $row, $previous, $stride, $bytesPerPixel);
            $rows[] = $row;
            $previous = $row;
        }
        return $rows;
    }

    private static function unfilterRow(int $filter, string $row, string $previous, int $stride, int $bytesPerPixel): string
    {
        if ($filter === 0) {
            return $row;
        }
        $out = '';
        for ($i = 0; $i < $stride; $i++) {
            $raw = ord($row[$i]);
            $left = $i >= $bytesPerPixel ? ord($out[$i - $bytesPerPixel]) : 0;
            $up = ord($previous[$i]);
            $upLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel]) : 0;
            $value = match ($filter) {
                1 => $raw + $left,
                2 => $raw + $up,
                3 => $raw + intdiv($left + $up, 2),
                4 => $raw + self::paeth($left, $up, $upLeft),
                default => throw new InvalidArgumentException('Unbekannter PNG-Zeilenfilter ' . $filter . '.'),
            };
            $out .= chr($value & 0xFF);
        }
        return $out;
    }

    private static function paeth(int $left, int $up, int $upLeft): int
    {
        $estimate = $left + $up - $upLeft;
        $distanceLeft = abs($estimate - $left);
        $distanceUp = abs($estimate - $up);
        $distanceUpLeft = abs($estimate - $upLeft);
        if ($distanceLeft <= $distanceUp && $distanceLeft <= $distanceUpLeft) {
            return $left;
        }
        return $distanceUp <= $distanceUpLeft ? $up : $upLeft;
    }

    /**
     * @param list<string> $rows
     * @param array<string, string> $chunks
     */
    private static function build(array $rows, int $width, int $height, int $bitDepth, int $colorType, int $channels, array $chunks): ImageData
    {
        $palette = $chunks['PLTE'] ?? '';
        if ($colorType === 3 && strlen($palette) < 3) {
            throw new InvalidArgumentException('Paletten-PNG ohne Farbtabelle.');
        }
        $transparency = $chunks['tRNS'] ?? '';
        $transparentGray = $colorType === 0 && strlen($transparency) >= 2 ? (int) unpack('n', substr($transparency, 0, 2))[1] : null;
        $transparentRgb = $colorType === 2 && strlen($transparency) >= 6 ? array_values(unpack('n3', substr($transparency, 0, 6))) : null;
        $maxValue = (1 << $bitDepth) - 1;
        $scale = static fn (int $value): int => $bitDepth >= 8 ? $value : (int) round($value * 255 / $maxValue);

        $pixels = '';
        $alpha = '';
        $hasAlpha = false;
        foreach ($rows as $row) {
            $samples = self::samples($row, $width * $channels, $bitDepth);
            for ($x = 0; $x < $width; $x++) {
                $base = $x * $channels;
                $opacity = 255;
                switch ($colorType) {
                    case 0:
                        $gray = $samples[$base];
                        if ($transparentGray !== null && $gray === $transparentGray) {
                            $opacity = 0;
                        }
                        $pixels .= chr($scale($gray));
                        break;
                    case 2:
                        $rgb = [$samples[$base], $samples[$base + 1], $samples[$base + 2]];
                        if ($transparentRgb !== null && $rgb === $transparentRgb) {
                            $opacity = 0;
                        }
                        $pixels .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
                        break;
                    case 3:
                        $index = $samples[$base];
                        $offset = $index * 3;
                        if ($offset + 2 >= strlen($palette)) {
                            throw new InvalidArgumentException('PNG-Palettenindex ist ungueltig.');
                        }
                        $pixels .= substr($palette, $offset, 3);
                        if ($index < strlen($transparency)) {
                            $opacity = ord($transparency[$index]);
                        }
                        break;
                    case 4:
                        $pixels .= chr($samples[$base]);
                        $opacity = $samples[$base + 1];
                        break;
                    default:
                        $pixels .= chr($samples[$base]) . chr($samples[$base + 1]) . chr($samples[$base + 2]);
                        $opacity = $samples[$base + 3];
                        break;
                }
                if ($opacity !== 255) {
                    $hasAlpha = true;
                }
                $alpha .= chr($opacity);
            }
        }

        $grayScale = $colorType === 0 || $colorType === 4;
        $colors = $grayScale ? 1 : 3;
        return new ImageData(
            $width,
            $height,
            $grayScale ? 'DeviceGray' : 'DeviceRGB',
            8,
            'FlateDecode',
            self::pack($pixels, $width * $colors, $height),
            ['Predictor' => 15, 'Colors' => $colors, 'BitsPerComponent' => 8, 'Columns' => $width],
            $hasAlpha ? self::pack($alpha, $width, $height) : null,
        );
    }

    /**
     * Wandelt eine entfilterte Zeile in einzelne Abtastwerte (16 Bit auf das hoehere Byte reduziert).
     *
     * @return list<int>
     */
    private static function samples(string $row, int $count, int $bitDepth): array
    {
        $values = [];
        if ($bitDepth === 16) {
            for ($i = 0; $i < $count; $i++) {
                $values[] = ord($row[$i * 2]);
            }
            return $values;
        }
        if ($bitDepth === 8) {
            for ($i = 0; $i < $count; $i++) {
                $values[] = ord($row[$i] ?? "\x00");
            }
            return $values;
        }
        $perByte = intdiv(8, $bitDepth);
        $mask = (1 << $bitDepth) - 1;
        for ($i = 0; $i < $count; $i++) {
            $byte = ord($row[intdiv($i, $perByte)] ?? "\x00");
            $shift = 8 - $bitDepth * (($i % $perByte) + 1);
            $values[] = ($byte >> $shift) & $mask;
        }
        return $values;
    }

    /**
     * Packt Abtastwerte zeilenweise mit PNG-Filter 0 und komprimiert sie (Predictor 15).
     */
    private static function pack(string $samples, int $bytesPerRow, int $height): string
    {
        $out = '';
        for ($y = 0; $y < $height; $y++) {
            $out .= "\x00" . substr($samples, $y * $bytesPerRow, $bytesPerRow);
        }
        $compressed = gzcompress($out, 6);
        if ($compressed === false) {
            throw new InvalidArgumentException('Bilddaten konnten nicht komprimiert werden.');
        }
        return $compressed;
    }
}
