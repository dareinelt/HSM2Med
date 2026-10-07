<?php

declare(strict_types=1);

namespace App\Report\Pdf;

use InvalidArgumentException;

/**
 * Einbettbares Rasterbild fuer den PDF-Writer.
 *
 * Unterstuetzt werden PNG (Graustufen, RGB, Palette, mit/ohne Transparenz, nicht interlaced)
 * und JPEG (Graustufen/RGB). Alphakanaele werden als SMask (Weichmaske) eingebettet.
 * Alle Daten werden im Speicher verarbeitet – es werden keine Dateien geschrieben und keine
 * externen Bibliotheken verwendet.
 */
final class ImageData
{
    /**
     * @param string $colorSpace DeviceGray oder DeviceRGB
     * @param string $filter FlateDecode (rohe Abtastwerte) oder DCTDecode (JPEG-Bytes)
     * @param array<string, int>|null $decodeParms PNG-Praediktor (nur bei FlateDecode)
     * @param string|null $smaskData Alphakanal als Graustufenbild (FlateDecode, 8 Bit)
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $colorSpace,
        public readonly int $bitsPerComponent,
        public readonly string $filter,
        public readonly string $data,
        public readonly ?array $decodeParms = null,
        public readonly ?string $smaskData = null,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Bildabmessungen sind ungueltig.');
        }
    }

    public static function fromBytes(string $bytes): self
    {
        if (str_starts_with($bytes, PngDecoder::SIGNATURE)) {
            return PngDecoder::decode($bytes);
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return self::fromJpeg($bytes);
        }
        throw new InvalidArgumentException('Nicht unterstuetztes Bildformat (erlaubt: PNG, JPEG).');
    }

    private static function fromJpeg(string $bytes): self
    {
        $length = strlen($bytes);
        $offset = 2;
        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;
                continue;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xFF) {
                $offset++;
                continue;
            }
            // Marker ohne Nutzdaten
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD9)) {
                $offset += 2;
                continue;
            }
            $segmentLength = (int) unpack('n', substr($bytes, $offset + 2, 2))[1];
            if ($segmentLength < 2) {
                throw new InvalidArgumentException('JPEG-Datei ist beschaedigt.');
            }
            $isStartOfFrame = $marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true);
            if ($isStartOfFrame) {
                $precision = ord($bytes[$offset + 4]);
                $height = (int) unpack('n', substr($bytes, $offset + 5, 2))[1];
                $width = (int) unpack('n', substr($bytes, $offset + 7, 2))[1];
                $components = ord($bytes[$offset + 9]);
                $colorSpace = match ($components) {
                    1 => 'DeviceGray',
                    3 => 'DeviceRGB',
                    default => throw new InvalidArgumentException('JPEG mit ' . $components . ' Farbkomponenten wird nicht unterstuetzt.'),
                };
                return new self($width, $height, $colorSpace, $precision, 'DCTDecode', $bytes);
            }
            $offset += 2 + $segmentLength;
        }
        throw new InvalidArgumentException('JPEG-Datei enthaelt keinen Bildkopf.');
    }
}
