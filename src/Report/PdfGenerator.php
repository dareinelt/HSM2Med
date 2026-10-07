<?php

declare(strict_types=1);

namespace App\Report;

use App\Report\Pdf\PdfDocument;
use DateTimeImmutable;
use RuntimeException;

/**
 * Layout des PDF-Berichts (Berichtsversion 1).
 *
 * Verwendet ausschliesslich den gespeicherten Snapshot (ReportData). Gleicher Snapshot fuehrt
 * zu identischem Inhalt; lediglich Erstellungszeitpunkt (Fusszeile/Metadaten) unterscheidet sich.
 * Es werden keine medizinischen Bewertungen erzeugt.
 */
final class PdfGenerator
{
    public const int SUPPORTED_REPORT_VERSION = 1;

    private const float MARGIN_X = 42.0;
    private const float TOP = 64.0;
    private const float BOTTOM_LIMIT = PdfDocument::PAGE_HEIGHT - 58.0;
    private const float CONTENT_WIDTH = PdfDocument::PAGE_WIDTH - 2 * self::MARGIN_X;
    private const float CELL_PAD = 3.0;
    private const float FONT_SIZE = 8.5;

    private const array PRIMARY = [0.11, 0.23, 0.38];
    private const array TEXT = [0.1, 0.1, 0.12];
    private const array MUTED = [0.42, 0.44, 0.48];
    private const array HEADER_FILL = [0.85, 0.89, 0.94];
    private const array ZEBRA_FILL = [0.955, 0.965, 0.98];
    private const array GRID = [0.78, 0.81, 0.86];
    private const array NOTICE_FILL = [1.0, 0.97, 0.88];
    private const array NOTICE_BORDER = [0.85, 0.7, 0.35];

    private const string FS_SYMBOL = "\u{00A6}";

    private const array STATUS_LABELS = [
        'completed' => 'abgeschlossen',
        'completed_with_warnings' => 'abgeschlossen mit Warnungen',
        'completed_with_errors' => 'abgeschlossen mit fehlerhaften Datensätzen',
        'failed' => 'fehlgeschlagen',
    ];

    private PdfDocument $pdf;
    private ReportData $data;
    private float $y = 0.0;

    public function __construct(private readonly bool $compress = true)
    {
    }

    public function generate(ReportData $data, bool $includeRawAppendix, DateTimeImmutable $generatedAt): string
    {
        if ($data->reportVersion() !== self::SUPPORTED_REPORT_VERSION) {
            throw new RuntimeException(sprintf('Berichtsversion %d wird von diesem PDF-Generator nicht unterstuetzt.', $data->reportVersion()));
        }
        $this->pdf = new PdfDocument($this->compress);
        $this->data = $data;

        $this->newPage(true);
        $this->titleBlock();
        $this->metaSection();
        $this->keyValueSection('Patient', $data->summarySection('patient'));
        $this->keyValueSection('Gerät', $data->summarySection('device'));
        $this->leadsSection();
        foreach ($data->categories() as $category) {
            $this->categorySection($category);
        }
        $this->issuesSection();
        if ($includeRawAppendix) {
            $this->rawAppendix();
        }
        $this->footers($generatedAt);

        return $this->pdf->output([
            'Title' => sprintf('Herzschrittmacher – Auslesebericht Nr. %d', $data->id()),
            'Subject' => 'Automatisch erzeugter Datenbericht (keine originale Abbott-/Merlin-Dokumentation)',
            'Creator' => 'HSM2Med',
            'Producer' => 'HSM2Med PdfDocument',
        ], $generatedAt);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    // ------------------------------------------------------------------ Abschnitte

    private function titleBlock(): void
    {
        $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, 3.0, self::PRIMARY);
        $this->y += 22;
        $this->pdf->text(self::MARGIN_X, $this->y, 'HERZSCHRITTMACHER – AUSLESEBERICHT', 'bold', 17, self::PRIMARY);
        $this->y += 16;
        $this->pdf->text(self::MARGIN_X, $this->y, 'Automatisch erzeugter Datenbericht', 'regular', 10, self::TEXT);
        $this->y += 13;
        $this->pdf->text(self::MARGIN_X, $this->y, 'Keine originale Abbott-/Merlin-Dokumentation', 'regular', 10, self::TEXT);
        $this->y += 12;

        $notice = 'Dieser Bericht stellt die importierten Gerätedaten strukturiert und unverändert dar. '
            . 'Er enthält keine medizinische Bewertung, Diagnose oder Empfehlung. '
            . 'Werte und Einheiten entsprechen den Originalwerten der Exportdatei.';
        $lines = $this->wrap($notice, self::CONTENT_WIDTH - 16, 'italic', 8.5);
        $height = count($lines) * 10.5 + 10;
        $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::NOTICE_FILL, self::NOTICE_BORDER, 0.6);
        foreach ($lines as $i => $line) {
            $this->pdf->text(self::MARGIN_X + 8, $this->y + 5 + $i * 10.5 + 8.2, $line, 'italic', 8.5, self::TEXT);
        }
        $this->y += $height + 6;
    }

    private function metaSection(): void
    {
        $r = $this->data->report;
        $i = $this->data->import;
        $importedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $i['imported_at']);

        $this->heading('Bericht');
        $this->keyValueTable([
            ['Bericht-Nr.', (string) $r['id']],
            ['Berichtsversion', sprintf('%d (Parser %s, Mapping %s)', $r['report_version'], $r['parser_version'], $r['mapping_version'])],
            ['Importdatei', (string) $i['filename']],
            ['SHA-256', (string) $i['file_hash']],
            ['Kodierung', (string) $i['encoding']],
            ['Importiert am', $importedAt !== false ? $importedAt->format('d.m.Y H:i:s') : (string) $i['imported_at']],
            ['Datensätze', sprintf(
                '%d erkannt, %d als Parameter gespeichert, %d fehlerhaft, %d Warnungen',
                $i['record_count'],
                $r['parameter_count'],
                $i['error_count'],
                $i['warning_count'],
            )],
            ['Importstatus', self::statusLabel((string) $i['status'])],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function keyValueSection(string $title, array $rows): void
    {
        $this->heading($title);
        $tableRows = [];
        foreach ($rows as $row) {
            $value = $row['value'];
            if ($value === null) {
                $cell = ['text' => 'nicht in den Quelldaten enthalten', 'style' => 'muted'];
            } elseif ($value === '') {
                $cell = ['text' => '(leer)', 'style' => 'muted'];
            } else {
                $text = (string) $value . (($row['unit'] ?? '') !== '' ? ' ' . $row['unit'] : '');
                $cell = ['text' => $text, 'style' => 'normal'];
            }
            $tableRows[] = [(string) $row['label'], $cell];
        }
        $this->keyValueTable($tableRows);
    }

    private function leadsSection(): void
    {
        $leads = $this->data->leads();
        $this->heading('Sonden', count($leads) . ' laut Quelldaten');
        if ($leads === []) {
            $this->paragraph('Die Quelldatei enthält keine Sondenangaben.', 'italic', 8.5, self::MUTED);
            return;
        }
        $cell = static function (mixed $value): array|string {
            if ($value === null) {
                return ['text' => 'nicht enthalten', 'style' => 'muted'];
            }
            if ($value === '') {
                return ['text' => '(leer)', 'style' => 'muted'];
            }
            return (string) $value;
        };
        $rows = [];
        foreach ($leads as $lead) {
            $model = $lead['model_number'];
            if ($model !== null && $model !== '' && !empty($lead['model_label'])) {
                $model .= "\n(" . $lead['model_label'] . ')';
            }
            $rows[] = [
                (string) $lead['chamber_label'],
                $cell($lead['manufacturer']),
                $cell($model),
                $cell($lead['serial_number']),
                $cell($lead['lead_type']),
                $cell($lead['implant_date_display'] ?? $lead['implant_date']),
            ];
        }
        $this->table([
            ['title' => 'Kammer', 'width' => 78.0],
            ['title' => 'Hersteller', 'width' => 85.0],
            ['title' => 'Modell', 'width' => 120.0],
            ['title' => 'Seriennummer', 'width' => 80.0],
            ['title' => 'Typ', 'width' => 64.0],
            ['title' => 'Implantation', 'width' => self::CONTENT_WIDTH - 427.0],
        ], $rows);
    }

    /**
     * @param array{key: string, label: string, sort: int, parameters: list<array<string, mixed>>} $category
     */
    private function categorySection(array $category): void
    {
        $count = count($category['parameters']);
        $this->heading($category['label'], $count . ($count === 1 ? ' Parameter' : ' Parameter'));
        $rows = [];
        foreach ($category['parameters'] as $p) {
            $name = (string) $p['display_name'];
            if ($p['display_name'] !== $p['parameter_name']) {
                $name .= "\n(Original: " . $p['parameter_name'] . ')';
            }
            $value = $p['value'];
            $rows[] = [
                ['text' => (string) $p['parameter_id'], 'style' => 'muted'],
                $name,
                $value === null ? ['text' => 'nicht vorhanden', 'style' => 'muted'] : ($value === '' ? ['text' => '(leer)', 'style' => 'muted'] : (string) $value),
                (string) $p['unit'],
            ];
        }
        $this->table([
            ['title' => 'ID', 'width' => 40.0],
            ['title' => 'Parameter', 'width' => 238.0],
            ['title' => 'Wert', 'width' => 160.0, 'align' => 'R'],
            ['title' => 'Einheit', 'width' => self::CONTENT_WIDTH - 438.0],
        ], $rows);
    }

    private function issuesSection(): void
    {
        $issues = $this->data->issues;
        $this->heading('Importprotokoll', count($issues) . ' Einträge');
        if ($issues === []) {
            $this->paragraph('Beim Import wurden keine Fehler oder Warnungen protokolliert.', 'italic', 8.5, self::MUTED);
            return;
        }
        $this->paragraph(
            'Fehlerhafte Datensätze wurden nicht als Parameter übernommen. Warnungen betreffen übernommene Datensätze. '
            . 'Feldtrennzeichen 0x1C wird als ' . self::FS_SYMBOL . ' dargestellt.',
            'italic',
            8.0,
            self::MUTED,
        );
        $rows = [];
        foreach ($issues as $issue) {
            $rows[] = [
                $issue['record_position'] === null ? ['text' => 'Datei', 'style' => 'muted'] : (string) $issue['record_position'],
                $issue['severity'] === 'error' ? ['text' => 'Fehler', 'style' => 'bold'] : 'Warnung',
                (string) $issue['error_code'],
                (string) $issue['message'],
                $issue['raw_record'] === null ? '' : ['text' => str_replace("\x1C", self::FS_SYMBOL, (string) $issue['raw_record']), 'style' => 'mono'],
            ];
        }
        $this->table([
            ['title' => 'Pos.', 'width' => 34.0],
            ['title' => 'Art', 'width' => 46.0],
            ['title' => 'Code', 'width' => 92.0],
            ['title' => 'Meldung', 'width' => 180.0],
            ['title' => 'Rohdaten', 'width' => self::CONTENT_WIDTH - 352.0],
        ], $rows, 7.5);
    }

    private function rawAppendix(): void
    {
        $this->newPage();
        $this->heading('Anhang: Originaldaten / Importdaten');
        $this->paragraph(
            'Sämtliche Datensätze in der ursprünglichen Reihenfolge. Format: ID | Parameter | Wert | Einheit. '
            . 'Fehlerhafte Datensätze sind mit [FEHLERHAFT] gekennzeichnet und als Rohdaten wiedergegeben (Feldtrenner als '
            . self::FS_SYMBOL . ').',
            'italic',
            8.0,
            self::MUTED,
        );
        $this->y += 4;
        $size = 7.2;
        $lineHeight = 9.0;
        foreach ($this->data->recordsInOriginalOrder() as $record) {
            if ($record['valid']) {
                $p = $record['parameter'];
                $text = sprintf('%s | %s | %s | %s', $p['parameter_id'], $p['parameter_name'], (string) $p['value'], (string) $p['unit']);
                $color = self::TEXT;
            } else {
                $text = '[FEHLERHAFT] ' . str_replace("\x1C", self::FS_SYMBOL, $record['raw']);
                $color = [0.6, 0.1, 0.1];
            }
            foreach ($this->wrap($text, self::CONTENT_WIDTH, 'mono', $size) as $line) {
                $this->ensureSpace($lineHeight);
                $this->pdf->text(self::MARGIN_X, $this->y + 7.0, $line, 'mono', $size, $color);
                $this->y += $lineHeight;
            }
        }
    }

    private function footers(DateTimeImmutable $generatedAt): void
    {
        $total = $this->pdf->pageCount();
        $created = 'Erstellt am ' . $generatedAt->format('d.m.Y H:i:s');
        for ($page = 0; $page < $total; $page++) {
            $this->pdf->setPage($page);
            $lineY = PdfDocument::PAGE_HEIGHT - 44;
            $this->pdf->line(self::MARGIN_X, $lineY, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $lineY, self::GRID, 0.6);
            $this->pdf->text(self::MARGIN_X, $lineY + 12, 'Automatisch erzeugter Datenbericht – keine medizinische Bewertung oder Diagnose. Keine originale Abbott-/Merlin-Dokumentation.', 'regular', 7, self::MUTED);
            $this->pdf->text(self::MARGIN_X, $lineY + 22, sprintf('%s · Bericht Nr. %d · Berichtsversion %d', $created, $this->data->id(), $this->data->reportVersion()), 'regular', 7, self::MUTED);
            $pageLabel = sprintf('Seite %d von %d', $page + 1, $total);
            $width = PdfDocument::textWidth($pageLabel, 'bold', 8);
            $this->pdf->text(PdfDocument::PAGE_WIDTH - self::MARGIN_X - $width, $lineY + 22, $pageLabel, 'bold', 8, self::TEXT);
        }
    }

    // ------------------------------------------------------------------ Bausteine

    private function newPage(bool $first = false): void
    {
        $this->pdf->addPage();
        if ($first) {
            $this->y = 36.0;
            return;
        }
        $r = $this->data->report;
        $parts = ['Herzschrittmacher – Auslesebericht', 'Bericht Nr. ' . $r['id']];
        if (($r['patient_name_snapshot'] ?? '') !== '' || ($r['patient_identifier_snapshot'] ?? '') !== '') {
            $parts[] = trim(sprintf('Patient: %s %s', $r['patient_name_snapshot'] ?? '', ($r['patient_identifier_snapshot'] ?? '') !== '' ? '(' . $r['patient_identifier_snapshot'] . ')' : ''));
        }
        if (($r['device_serial_snapshot'] ?? '') !== '') {
            $parts[] = trim(sprintf('Gerät: %s SN %s', $r['device_model_name_snapshot'] ?? '', $r['device_serial_snapshot']));
        }
        $header = implode('  ·  ', $parts);
        $lines = $this->wrap($header, self::CONTENT_WIDTH, 'regular', 7.5);
        $this->pdf->text(self::MARGIN_X, 34, $lines[0] ?? '', 'regular', 7.5, self::MUTED);
        $this->pdf->line(self::MARGIN_X, 40, PdfDocument::PAGE_WIDTH - self::MARGIN_X, 40, self::GRID, 0.6);
        $this->y = self::TOP;
    }

    private function ensureSpace(float $height): void
    {
        if ($this->y + $height > self::BOTTOM_LIMIT) {
            $this->newPage();
        }
    }

    private function heading(string $title, ?string $suffix = null): void
    {
        // Ueberschrift nicht allein am Seitenende
        $this->ensureSpace(64);
        $this->y += 10;
        $this->pdf->text(self::MARGIN_X, $this->y + 11, $title, 'bold', 11.5, self::PRIMARY);
        if ($suffix !== null) {
            $titleWidth = PdfDocument::textWidth($title, 'bold', 11.5);
            $this->pdf->text(self::MARGIN_X + $titleWidth + 8, $this->y + 11, $suffix, 'regular', 8, self::MUTED);
        }
        $this->y += 15;
        $this->pdf->line(self::MARGIN_X, $this->y, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $this->y, self::PRIMARY, 0.8);
        $this->y += 4;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function paragraph(string $text, string $font, float $size, array $color): void
    {
        $lineHeight = $size * 1.3;
        foreach ($this->wrap($text, self::CONTENT_WIDTH, $font, $size) as $line) {
            $this->ensureSpace($lineHeight);
            $this->pdf->text(self::MARGIN_X, $this->y + $size, $line, $font, $size, $color);
            $this->y += $lineHeight;
        }
        $this->y += 3;
    }

    /**
     * @param list<array{0: string, 1: string|array{text: string, style?: string}}> $rows
     */
    private function keyValueTable(array $rows): void
    {
        $this->table([
            ['title' => '', 'width' => 130.0, 'font' => 'bold'],
            ['title' => '', 'width' => self::CONTENT_WIDTH - 130.0],
        ], $rows, self::FONT_SIZE, false);
    }

    /**
     * Tabelle mit Zeilenumbruch in Zellen, Kopfzeilenwiederholung und Seitenumbruechen
     * (auch innerhalb sehr hoher Zeilen).
     *
     * @param list<array{title: string, width: float, align?: string, font?: string}> $columns
     * @param list<list<string|array{text: string, style?: string}>> $rows
     */
    private function table(array $columns, array $rows, float $fontSize = self::FONT_SIZE, bool $showHeader = true): void
    {
        $lineHeight = $fontSize * 1.25;
        $headerHeight = $showHeader ? $lineHeight + 2 * self::CELL_PAD : 0.0;

        if ($showHeader) {
            $this->ensureSpace($headerHeight + $lineHeight + 2 * self::CELL_PAD);
            $this->tableHeader($columns, $fontSize, $lineHeight);
        }

        foreach ($rows as $index => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $c => $column) {
                $cell = $row[$c] ?? '';
                $cell = is_array($cell) ? $cell : ['text' => $cell];
                $style = $cell['style'] ?? 'normal';
                $font = match ($style) {
                    'muted' => 'italic',
                    'bold' => 'bold',
                    'mono' => 'mono',
                    default => $column['font'] ?? 'regular',
                };
                $size = $style === 'mono' ? $fontSize - 0.8 : $fontSize;
                $lines = $this->wrap($cell['text'], $column['width'] - 2 * self::CELL_PAD, $font, $size);
                $cells[] = ['lines' => $lines, 'font' => $font, 'size' => $size, 'color' => $style === 'muted' ? self::MUTED : self::TEXT];
                $maxLines = max($maxLines, count($lines));
            }

            $rowHeight = $maxLines * $lineHeight + 2 * self::CELL_PAD;
            $fullPage = self::BOTTOM_LIMIT - self::TOP - $headerHeight;
            if ($this->y + $rowHeight > self::BOTTOM_LIMIT && $rowHeight <= $fullPage) {
                $this->newPage();
                if ($showHeader) {
                    $this->tableHeader($columns, $fontSize, $lineHeight);
                }
            }

            $offset = 0;
            while ($offset < $maxLines) {
                $fit = (int) floor((self::BOTTOM_LIMIT - $this->y - 2 * self::CELL_PAD) / $lineHeight);
                if ($fit < 1) {
                    $this->newPage();
                    if ($showHeader) {
                        $this->tableHeader($columns, $fontSize, $lineHeight);
                    }
                    continue;
                }
                $chunk = min($fit, $maxLines - $offset);
                $height = $chunk * $lineHeight + 2 * self::CELL_PAD;
                if ($index % 2 === 1) {
                    $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::ZEBRA_FILL);
                }
                $x = self::MARGIN_X;
                foreach ($columns as $c => $column) {
                    $cell = $cells[$c];
                    for ($l = $offset; $l < min($offset + $chunk, count($cell['lines'])); $l++) {
                        $line = $cell['lines'][$l];
                        $textX = $x + self::CELL_PAD;
                        if (($column['align'] ?? 'L') === 'R') {
                            $textX = $x + $column['width'] - self::CELL_PAD - PdfDocument::textWidth($line, $cell['font'], $cell['size']);
                        }
                        $baseline = $this->y + self::CELL_PAD + ($l - $offset) * $lineHeight + $lineHeight * 0.76;
                        $this->pdf->text($textX, $baseline, $line, $cell['font'], $cell['size'], $cell['color']);
                    }
                    $x += $column['width'];
                }
                $this->y += $height;
                $this->pdf->line(self::MARGIN_X, $this->y, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $this->y, self::GRID, 0.3);
                $offset += $chunk;
                if ($offset < $maxLines) {
                    $this->newPage();
                    if ($showHeader) {
                        $this->tableHeader($columns, $fontSize, $lineHeight);
                    }
                }
            }
        }
        $this->y += 4;
    }

    /**
     * @param list<array{title: string, width: float, align?: string, font?: string}> $columns
     */
    private function tableHeader(array $columns, float $fontSize, float $lineHeight): void
    {
        $height = $lineHeight + 2 * self::CELL_PAD;
        $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::HEADER_FILL);
        $x = self::MARGIN_X;
        foreach ($columns as $column) {
            $textX = $x + self::CELL_PAD;
            if (($column['align'] ?? 'L') === 'R') {
                $textX = $x + $column['width'] - self::CELL_PAD - PdfDocument::textWidth($column['title'], 'bold', $fontSize);
            }
            $this->pdf->text($textX, $this->y + self::CELL_PAD + $lineHeight * 0.76, $column['title'], 'bold', $fontSize, self::PRIMARY);
            $x += $column['width'];
        }
        $this->y += $height;
    }

    /**
     * Zeilenumbruch nach Breite; respektiert vorhandene Zeilenumbrueche, trennt ueberlange Woerter hart.
     *
     * @return list<string>
     */
    private function wrap(string $text, float $maxWidth, string $font, float $size): array
    {
        $text = PdfDocument::sanitize(str_replace(["\r\n", "\r"], "\n", $text));
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            $line = [];
            $lineWidth = 0.0;
            $lastSpace = -1;
            foreach (mb_str_split($paragraph) as $char) {
                $charWidth = PdfDocument::textWidth($char, $font, $size);
                while ($line !== [] && $lineWidth + $charWidth > $maxWidth) {
                    if ($lastSpace > 0) {
                        $lines[] = implode('', array_slice($line, 0, $lastSpace));
                        $line = array_slice($line, $lastSpace + 1);
                    } else {
                        $lines[] = implode('', $line);
                        $line = [];
                    }
                    $lineWidth = PdfDocument::textWidth(implode('', $line), $font, $size);
                    $found = array_keys($line, ' ', true);
                    $lastSpace = $found === [] ? -1 : (int) end($found);
                }
                $line[] = $char;
                $lineWidth += $charWidth;
                if ($char === ' ') {
                    $lastSpace = count($line) - 1;
                }
            }
            $lines[] = implode('', $line);
        }
        return $lines;
    }
}
