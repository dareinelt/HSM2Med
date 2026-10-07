<?php

declare(strict_types=1);

namespace App\Report\Pdf;

use InvalidArgumentException;

/**
 * Minimaler, deterministischer PDF-1.4-Writer ohne externe Abhaengigkeiten.
 *
 * - Schriften: PDF-Standardschriften (Helvetica, Helvetica-Bold, Helvetica-Oblique, Courier) mit
 *   WinAnsiEncoding; es werden keine Schriftdateien eingebettet oder geladen.
 * - Zeichen ausserhalb von Windows-1252 werden sichtbar als [U+XXXX], Steuerzeichen als [0xNN]
 *   dargestellt – es geht also keine Information verloren.
 * - Koordinaten: Punkte, Ursprung oben links (y waechst nach unten).
 */
final class PdfDocument
{
    public const float PAGE_WIDTH = 595.28;
    public const float PAGE_HEIGHT = 841.89;

    private const array FONTS = [
        'regular' => ['res' => 'F1', 'base' => 'Helvetica', 'metrics' => 'Helvetica'],
        'bold' => ['res' => 'F2', 'base' => 'Helvetica-Bold', 'metrics' => 'Helvetica-Bold'],
        'italic' => ['res' => 'F3', 'base' => 'Helvetica-Oblique', 'metrics' => 'Helvetica'],
        'mono' => ['res' => 'F4', 'base' => 'Courier', 'metrics' => null],
    ];

    /** @var array<string, list<int>>|null */
    private static ?array $metrics = null;
    /** @var array<string, string> */
    private static array $charCache = [];

    /** @var list<string> */
    private array $pages = [];
    private int $current = -1;

    public function __construct(private readonly bool $compress = true)
    {
    }

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
        return $this->current;
    }

    public function setPage(int $index): void
    {
        if (!isset($this->pages[$index])) {
            throw new InvalidArgumentException('Seite existiert nicht.');
        }
        $this->current = $index;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color RGB 0..1
     */
    public function text(float $x, float $y, string $text, string $font = 'regular', float $size = 10.0, array $color = [0.0, 0.0, 0.0]): void
    {
        $encoded = self::encode(self::sanitize($text));
        if ($encoded === '') {
            return;
        }
        $this->append(sprintf(
            "BT /%s %s Tf %s rg %s %s Td (%s) Tj ET\n",
            self::fontInfo($font)['res'],
            self::num($size),
            self::color($color),
            self::num($x),
            self::num(self::PAGE_HEIGHT - $y),
            self::escape($encoded),
        ));
    }

    /**
     * @param array{0: float, 1: float, 2: float}|null $fill
     * @param array{0: float, 1: float, 2: float}|null $stroke
     */
    public function rect(float $x, float $y, float $width, float $height, ?array $fill, ?array $stroke = null, float $lineWidth = 0.5): void
    {
        if ($fill === null && $stroke === null) {
            return;
        }
        $ops = '';
        if ($fill !== null) {
            $ops .= self::color($fill) . " rg\n";
        }
        if ($stroke !== null) {
            $ops .= self::color($stroke) . ' RG ' . self::num($lineWidth) . " w\n";
        }
        $ops .= sprintf(
            "%s %s %s %s re %s\n",
            self::num($x),
            self::num(self::PAGE_HEIGHT - $y - $height),
            self::num($width),
            self::num($height),
            $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S'),
        );
        $this->append($ops);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color
     */
    public function line(float $x1, float $y1, float $x2, float $y2, array $color = [0.0, 0.0, 0.0], float $lineWidth = 0.5): void
    {
        $this->append(sprintf(
            "%s RG %s w %s %s m %s %s l S\n",
            self::color($color),
            self::num($lineWidth),
            self::num($x1),
            self::num(self::PAGE_HEIGHT - $y1),
            self::num($x2),
            self::num(self::PAGE_HEIGHT - $y2),
        ));
    }

    public static function textWidth(string $text, string $font, float $size): float
    {
        $encoded = self::encode(self::sanitize($text));
        $info = self::fontInfo($font);
        if ($info['metrics'] === null) {
            return strlen($encoded) * 600 * $size / 1000;
        }
        $widths = self::metrics()[$info['metrics']];
        $sum = 0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $sum += $widths[ord($encoded[$i])];
        }
        return $sum * $size / 1000;
    }

    /**
     * Ersetzt nicht darstellbare Zeichen durch sichtbare Platzhalter. Zeilenumbrueche (\n) bleiben erhalten.
     */
    public static function sanitize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        if (preg_match('/^[\x20-\x7E\n]*$/D', $text) === 1) {
            return $text;
        }
        $out = '';
        foreach (mb_str_split($text) as $char) {
            $out .= self::$charCache[$char] ??= self::sanitizeChar($char);
        }
        return $out;
    }

    /**
     * Erzeugt das PDF. $info: Title, Author, Subject, Creator; $creationDate im Format YmdHisP.
     *
     * @param array<string, string> $info
     */
    public function output(array $info, \DateTimeImmutable $creationDate): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontRefs = [];
        $objectNo = 3;
        foreach (self::FONTS as $font) {
            $objects[$objectNo] = sprintf('<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>', $font['base']);
            $fontRefs[] = sprintf('/%s %d 0 R', $font['res'], $objectNo);
            $objectNo++;
        }
        $resourcesNo = $objectNo++;
        $objects[$resourcesNo] = '<< /Font << ' . implode(' ', $fontRefs) . ' >> /ProcSet [/PDF /Text] >>';

        $kids = [];
        foreach ($this->pages as $content) {
            $pageNo = $objectNo++;
            $contentNo = $objectNo++;
            $kids[] = $pageNo . ' 0 R';
            $objects[$pageNo] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources %d 0 R /Contents %d 0 R >>',
                self::num(self::PAGE_WIDTH),
                self::num(self::PAGE_HEIGHT),
                $resourcesNo,
                $contentNo,
            );
            $data = $this->compress ? (string) gzcompress($content, 6) : $content;
            $objects[$contentNo] = sprintf(
                "<< /Length %d%s >>\nstream\n%s\nendstream",
                strlen($data),
                $this->compress ? ' /Filter /FlateDecode' : '',
                $data,
            );
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));

        $infoNo = $objectNo;
        $date = $creationDate->format('YmdHisO');
        $pdfDate = 'D:' . substr($date, 0, 14) . substr($date, 14, 3) . "'" . substr($date, 17, 2) . "'";
        $infoEntries = [];
        foreach ($info as $key => $value) {
            $infoEntries[] = '/' . $key . ' ' . self::utf16Hex($value);
        }
        $infoEntries[] = '/CreationDate (' . $pdfDate . ')';
        $infoEntries[] = '/ModDate (' . $pdfDate . ')';
        $objects[$infoNo] = '<< ' . implode(' ', $infoEntries) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $no => $body) {
            $offsets[$no] = strlen($pdf);
            $pdf .= $no . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . ($infoNo + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $infoNo; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $id = md5($pdf);
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R /ID [<%s> <%s>] >>\nstartxref\n%d\n%%%%EOF\n",
            $infoNo + 1,
            $infoNo,
            $id,
            $id,
            $xref,
        );
        return $pdf;
    }

    private function append(string $operations): void
    {
        if ($this->current < 0) {
            $this->addPage();
        }
        $this->pages[$this->current] .= $operations;
    }

    private static function sanitizeChar(string $char): string
    {
        $cp = mb_ord($char, 'UTF-8');
        if ($cp === false) {
            return '?';
        }
        if ($cp === 0x0A) {
            return "\n";
        }
        if ($cp < 0x20 || ($cp >= 0x7F && $cp <= 0x9F)) {
            return sprintf('[0x%02X]', $cp);
        }
        if ($cp < 0x7F) {
            return $char;
        }
        $encoded = mb_convert_encoding($char, 'Windows-1252', 'UTF-8');
        if (strlen($encoded) === 1 && mb_convert_encoding($encoded, 'UTF-8', 'Windows-1252') === $char) {
            return $char;
        }
        return sprintf('[U+%04X]', $cp);
    }

    private static function encode(string $sanitized): string
    {
        $sanitized = str_replace("\n", ' ', $sanitized);
        return mb_convert_encoding($sanitized, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $bytes): string
    {
        $out = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $c = $bytes[$i];
            $o = ord($c);
            $out .= match (true) {
                $c === '\\' || $c === '(' || $c === ')' => '\\' . $c,
                $o < 0x20 || $o > 0x7E => sprintf('\\%03o', $o),
                default => $c,
            };
        }
        return $out;
    }

    private static function utf16Hex(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color
     */
    private static function color(array $color): string
    {
        return self::num($color[0]) . ' ' . self::num($color[1]) . ' ' . self::num($color[2]);
    }

    private static function num(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * @return array{res: string, base: string, metrics: ?string}
     */
    private static function fontInfo(string $font): array
    {
        return self::FONTS[$font] ?? throw new InvalidArgumentException('Unbekannte Schrift: ' . $font);
    }

    /**
     * @return array<string, list<int>>
     */
    private static function metrics(): array
    {
        return self::$metrics ??= require __DIR__ . '/font_metrics.php';
    }
}
