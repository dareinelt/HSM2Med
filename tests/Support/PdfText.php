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
        $pages = [];
        foreach (self::streams($pdf) as $content) {
            preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\) Tj/s', $content, $texts);
            $parts = array_map(self::unescape(...), $texts[1]);
            $pages[] = implode("\n", $parts);
        }
        return $pages;
    }

    /**
     * Textlaeufe mit Schrift und Schriftgroesse, damit sich fett und skalierte
     * Bausteine pruefen lassen.
     *
     * @return list<array{font: string, size: float, text: string}>
     */
    public static function runs(string $pdf): array
    {
        $runs = [];
        foreach (self::streams($pdf) as $content) {
            preg_match_all(
                '/\/F(\d) ([\d.]+) Tf[^\n]*?\(((?:\\\\.|[^\\\\)])*)\) Tj/s',
                $content,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $runs[] = [
                    'font' => $match[1] === '2' ? 'bold' : 'regular',
                    'size' => (float) $match[2],
                    'text' => self::unescape($match[3]),
                ];
            }
        }
        return $runs;
    }

    /**
     * @return list<string> entpackte Inhaltsstroeme
     */
    private static function streams(string $pdf): array
    {
        if (preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $matches) === false) {
            throw new RuntimeException('Keine Streams gefunden.');
        }
        $streams = [];
        foreach ($matches[1] as $stream) {
            $content = @gzuncompress($stream);
            $streams[] = $content === false ? $stream : $content;
        }
        return $streams;
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
