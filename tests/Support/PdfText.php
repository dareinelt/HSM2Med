<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Extrahiert Text aus den von App\Report\Pdf\PdfDocument erzeugten PDFs (nur fuer Tests).
 */
final class PdfText
{
    /**
     * @return list<string> Text je Seite (Textstuecke durch "\n" getrennt)
     */
    public static function pages(string $pdf): array
    {
        if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $matches) === false) {
            throw new RuntimeException('Keine Streams gefunden.');
        }
        $pages = [];
        foreach ($matches[1] as $stream) {
            $content = @gzuncompress($stream);
            if ($content === false) {
                $content = $stream;
            }
            preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\) Tj/s', $content, $texts);
            $parts = array_map(self::unescape(...), $texts[1]);
            $pages[] = implode("\n", $parts);
        }
        return $pages;
    }

    public static function text(string $pdf): string
    {
        return implode("\n", self::pages($pdf));
    }

    public static function pageCount(string $pdf): int
    {
        if (preg_match('/\/Type \/Pages \/Kids \[[^\]]*\] \/Count (\d+)/', $pdf, $m) !== 1) {
            throw new RuntimeException('Seitenbaum nicht gefunden.');
        }
        return (int) $m[1];
    }

    private static function unescape(string $escaped): string
    {
        $bytes = preg_replace_callback('/\\\\([0-7]{3}|.)/s', static function (array $m): string {
            return strlen($m[1]) === 3 ? chr(octdec($m[1])) : $m[1];
        }, $escaped);
        return mb_convert_encoding((string) $bytes, 'UTF-8', 'Windows-1252');
    }
}
