<?php

declare(strict_types=1);

namespace App\PatientCard;

use App\Report\Pdf\ImageData;
use App\Report\Pdf\PdfDocument;
use DateTimeImmutable;
use RuntimeException;

/**
 * Layout des Patientenausweises: genau zwei DIN-A4-Seiten.
 *
 * Seite 1: Logo, Ueberschrift (deutsch/englisch), Geraete-/Patientenhinweis,
 *          Patientendaten, Geraetetabelle, Elektrodentabelle, Hinweise,
 *          Flugsicherheit (deutsch/englisch), Nachsorgezentrum, naechste Kontrolle, Arzt.
 * Seite 2: vergangene Nachsorgeuntersuchungen.
 *
 * Der Generator arbeitet ausschliesslich mit dem uebergebenen Snapshot und dem uebergebenen
 * Logo. Stammdatenaenderungen koennen ein bereits erzeugtes PDF daher nicht veraendern.
 * Es werden keine medizinischen Bewertungen erzeugt; fehlende Angaben erscheinen als
 * "nicht angegeben".
 */
final class PatientCardPdfGenerator
{
    public const int SUPPORTED_CARD_VERSION = 1;

    /** Der Ausweis besteht aus genau zwei Seiten. */
    public const int PAGES = 2;

    /** Maximale Laenge der Stammdatentexte, damit Seite 1 sicher passt (Spiegel in der Stammdatenmaske). */
    public const int MAX_NOTICE_CHARS = 500;
    public const int MAX_FLIGHT_NOTICE_CHARS = 300;

    private const float MARGIN_X = 42.0;
    private const float CONTENT_WIDTH = PdfDocument::PAGE_WIDTH - 2 * self::MARGIN_X;
    private const float BOTTOM_LIMIT = PdfDocument::PAGE_HEIGHT - 62.0;
    private const float CELL_PAD = 3.0;
    private const float LOGO_MAX_WIDTH = 150.0;
    private const float LOGO_MAX_HEIGHT = 40.0;
    private const float HEADER_TOP = 26.0;
    /** Abstand zwischen den beiden Spalten des Patientendaten-/Geraeteblocks. */
    private const float PAIR_GAP = 10.0;
    /** Breite der Beschriftungsspalte innerhalb einer Spalte. */
    private const float PAIR_LABEL_WIDTH = 84.0;

    private const array PRIMARY = [0.11, 0.23, 0.38];
    private const array TEXT = [0.1, 0.1, 0.12];
    private const array MUTED = [0.42, 0.44, 0.48];
    private const array HEADER_FILL = [0.85, 0.89, 0.94];
    private const array ZEBRA_FILL = [0.955, 0.965, 0.98];
    private const array GRID = [0.78, 0.81, 0.86];
    private const array ALERT_FILL = [0.70, 0.13, 0.13];
    private const array ALERT_TEXT = [1.0, 1.0, 1.0];
    private const array BOX_FILL = [0.96, 0.97, 0.99];
    private const array BOX_BORDER = [0.72, 0.77, 0.85];

    private const string EMPTY = 'nicht angegeben';

    private PdfDocument $pdf;
    /** @var array<string, mixed> */
    private array $card = [];
    private float $y = 0.0;

    public function __construct(private readonly bool $compress = true)
    {
    }

    /**
     * @param array<string, mixed> $card vollstaendiger Ausweis-Snapshot (siehe PatientCardService::snapshot())
     */
    public function generate(array $card, ?ImageData $logo, DateTimeImmutable $generatedAt): string
    {
        if ((int) ($card['card_version'] ?? 0) !== self::SUPPORTED_CARD_VERSION) {
            throw new RuntimeException(sprintf(
                'Ausweisversion %s wird von diesem PDF-Generator nicht unterstuetzt.',
                (string) ($card['card_version'] ?? '?'),
            ));
        }
        $this->pdf = new PdfDocument($this->compress);
        $this->card = $card;

        $this->pageOne($logo);
        $this->pageTwo();
        if ($this->pdf->pageCount() !== self::PAGES) {
            throw new RuntimeException('Der Patientenausweis muss aus genau zwei Seiten bestehen.');
        }
        $this->footers($generatedAt);

        return $this->pdf->output([
            'Title' => sprintf('Patientenausweis %s', (string) ($card['patient']['patient_name'] ?? '')),
            'Subject' => 'Patientenausweis / Patient Identification Card – automatisch erzeugt aus importierten Nachsorgeberichten',
            'Creator' => 'HSM2Med',
            'Producer' => 'HSM2Med PdfDocument',
        ], $generatedAt);
    }

    public static function filename(array $card): string
    {
        $patient = (string) ($card['patient']['patient_name'] ?? 'Patient');
        $date = (string) ($card['follow_up']['report_date'] ?? $card['generated_at'] ?? '');
        $parts = ['Patientenausweis', $patient];
        if ($date !== '') {
            $parts[] = substr($date, 0, 10);
        }
        return \App\Security\FileName::downloadName(implode('_', $parts) . '.pdf');
    }

    // --------------------------------------------------------------------- Seite 1

    private function pageOne(?ImageData $logo): void
    {
        $this->pdf->addPage();
        $this->y = self::HEADER_TOP;

        $this->logoAndCenter($logo);
        $this->titles();
        $this->alertPanel();
        $this->patientAndDevice();
        $this->leads();
        $this->notices();
        $this->followUp();

        if ($this->y > self::BOTTOM_LIMIT) {
            throw new RuntimeException(
                'Die Stammdatentexte sind zu lang: Seite 1 des Patientenausweises wurde nicht vollstaendig bedruckt.',
            );
        }
    }

    /**
     * Patientendaten und Geraet kompakt nebeneinander; die Indikation folgt in voller Breite.
     */
    private function patientAndDevice(): void
    {
        $patient = $this->card['patient'];
        $device = $this->card['device'];

        $this->tablePair(
            'Patientendaten / Patient data',
            [
                ['Name', $this->orEmpty((string) $patient['patient_name'])],
                ['Geburtsdatum', $this->orEmpty((string) $patient['date_of_birth_display'])],
                ['Anschrift', $this->orEmpty(implode(', ', array_filter([
                    (string) $patient['street'],
                    trim((string) $patient['postal_code'] . ' ' . (string) $patient['city']),
                ])))],
                ['Telefon', $this->orEmpty((string) $patient['phone'])],
                ['Patienten-ID', $this->orEmpty((string) $patient['patient_identifier'])],
            ],
            'Gerät / Device',
            [
                ['Hersteller', $this->orEmpty((string) $device['manufacturer'])],
                ['Modell', $this->orEmpty(trim((string) $device['model_name'] . ' ' . (string) $device['model_number']))],
                ['Seriennummer', $this->orEmpty((string) $device['serial_number'])],
                ['Implantation', $this->orEmpty((string) $device['implant_date_display'])],
                ['Implantationsort', $this->orEmpty((string) $device['implant_location'])],
            ],
        );

        $this->keyValueTable([
            ['Indikation / Indication', $this->orEmpty((string) $patient['indication'])],
        ]);
    }

    private function leads(): void
    {
        $this->heading('Elektroden / Leads');
        $leads = $this->card['leads'];
        if ($leads === []) {
            $this->paragraph('Für diesen Bericht sind keine Elektrodendaten hinterlegt.', 'italic', 8.5, self::MUTED);
            return;
        }
        $rows = [];
        foreach ($leads as $lead) {
            $rows[] = [
                $this->orEmpty((string) $lead['chamber_label']),
                $this->orEmpty((string) $lead['manufacturer']),
                $this->orEmpty(trim((string) $lead['model_label'] . ' ' . (string) $lead['model_number'])),
                $this->orEmpty((string) $lead['serial_number']),
                $this->orEmpty((string) $lead['implant_date_display']),
            ];
        }
        $this->table([
            ['title' => 'Lokalisation', 'width' => 82.0],
            ['title' => 'Hersteller', 'width' => 100.0],
            ['title' => 'Modell', 'width' => 150.0],
            ['title' => 'Seriennummer', 'width' => 96.0],
            ['title' => 'Implantation', 'width' => self::CONTENT_WIDTH - 82.0 - 100.0 - 150.0 - 96.0],
        ], $rows, 8.0);
    }

    private function notices(): void
    {
        $settings = $this->card['settings'];
        $this->heading('Hinweise / Notes');
        $this->noticeBox((string) $settings['notice_text'], 'regular', 8.2);

        $this->heading('Achtung Flugsicherheit / Attention Airline Security');
        $this->twoColumnNotices((string) $settings['flight_notice_de'], (string) $settings['flight_notice_en']);
    }

    private function followUp(): void
    {
        $physician = $this->card['physician'];
        $contact = $this->card['emergency_contact'];
        $followUp = $this->card['follow_up'];
        $settings = $this->card['settings'];

        $this->heading('Nachsorge / Follow-up');
        $this->keyValueTable([
            ['Nachsorgezentrum', $this->orEmpty(trim((string) $settings['center_name'] . "\n" . (string) $settings['center_address']))],
            ['Nächste Kontrolle', $this->orEmpty((string) $followUp['next_control_display'])],
            ['Arzt', $this->orEmpty((string) $followUp['control_physician'])],
            ['Hausarzt', $this->orEmpty(trim((string) $physician['name'] . "\n" . (string) $physician['practice']))],
            ['Notfallkontakt', $this->orEmpty(trim((string) $contact['name'] . "\n" . (string) $contact['phone']))],
            ['Berichtsgrundlage', $this->orEmpty((string) $followUp['report_label'])],
        ]);
    }

    private function logoAndCenter(?ImageData $logo): void
    {
        $settings = $this->card['settings'];
        if ($logo !== null && $logo->width > 0 && $logo->height > 0) {
            $scale = min(self::LOGO_MAX_WIDTH / $logo->width, self::LOGO_MAX_HEIGHT / $logo->height);
            $width = $logo->width * $scale;
            $height = $logo->height * $scale;
            $this->pdf->image(self::MARGIN_X, $this->y, $width, $height, $logo);
        } else {
            $this->pdf->text(self::MARGIN_X, $this->y + 16, 'Logo nicht hinterlegt', 'italic', 7.5, self::MUTED);
        }

        $center = trim((string) $settings['center_name'] . "\n" . (string) $settings['center_address']);
        if ($center !== '') {
            $lines = $this->wrap($center, 230.0, 'regular', 7.8);
            $lines = array_slice($lines, 0, 5);
            foreach ($lines as $index => $line) {
                $width = PdfDocument::textWidth($line, 'regular', 7.8);
                $this->pdf->text(PdfDocument::PAGE_WIDTH - self::MARGIN_X - $width, $this->y + 10 + $index * 9.4, $line, 'regular', 7.8, self::MUTED);
            }
        }
        $this->y += max(self::LOGO_MAX_HEIGHT, 20.0) + 5;
    }

    private function titles(): void
    {
        $this->pdf->text(self::MARGIN_X, $this->y + 14, 'Schrittmacher - Patientenausweis', 'bold', 19, self::PRIMARY);
        $this->y += 20;
        $this->pdf->text(self::MARGIN_X, $this->y + 9, 'Patient Identification Card', 'italic', 10.5, self::MUTED);
        $this->y += 12;
        $this->pdf->line(self::MARGIN_X, $this->y, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $this->y, self::PRIMARY, 1.0);
        $this->y += 8;
    }

    private function alertPanel(): void
    {
        $patient = $this->card['patient'];
        $device = $this->card['device'];
        $lines = [
            'Gerät / Device: ' . $this->orEmpty(trim((string) $device['model_name'] . ' ' . (string) $device['model_number'])),
            'Seriennummer / Serial number: ' . $this->orEmpty((string) $device['serial_number']),
            'Patient / Patient: ' . $this->orEmpty((string) $patient['patient_name'])
                . '  ·  Geburtsdatum / Date of birth: ' . $this->orEmpty((string) $patient['date_of_birth_display']),
        ];
        $wrapped = [];
        foreach ($lines as $line) {
            foreach ($this->wrap($line, self::CONTENT_WIDTH - 20, 'bold', 9) as $part) {
                $wrapped[] = $part;
            }
        }
        $height = 18 + count($wrapped) * 11.6;
        $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::ALERT_FILL);
        $this->pdf->text(self::MARGIN_X + 10, $this->y + 13, 'Achtung / Attention', 'bold', 10, self::ALERT_TEXT);
        foreach ($wrapped as $index => $line) {
            $this->pdf->text(self::MARGIN_X + 10, $this->y + 13 + ($index + 1) * 11.6, $line, 'regular', 9, self::ALERT_TEXT);
        }
        $this->y += $height + 10;
    }

    // --------------------------------------------------------------------- Seite 2

    private function pageTwo(): void
    {
        $this->pdf->addPage();
        $this->y = self::HEADER_TOP;

        $patient = $this->card['patient'];
        $device = $this->card['device'];
        $header = sprintf(
            'Patientenausweis · %s · Geburtsdatum %s · Gerät %s · Seriennummer %s',
            (string) $patient['patient_name'],
            (string) $patient['date_of_birth_display'],
            trim((string) $device['model_name'] . ' ' . (string) $device['model_number']),
            (string) $device['serial_number'],
        );
        foreach ($this->wrap($header, self::CONTENT_WIDTH, 'regular', 7.5) as $index => $line) {
            $this->pdf->text(self::MARGIN_X, 34 + $index * 9.4, $line, 'regular', 7.5, self::MUTED);
        }
        $this->pdf->line(self::MARGIN_X, 52, PdfDocument::PAGE_WIDTH - self::MARGIN_X, 52, self::GRID, 0.6);
        $this->y = 66;

        $this->heading('Vergangene Nachsorgeuntersuchungen');
        $this->pdf->text(self::MARGIN_X, $this->y + 8, 'Previous follow-up examinations', 'italic', 8.5, self::MUTED);
        $this->y += 14;

        $history = $this->card['history'];
        if ($history === []) {
            $this->noticeBox(
                'Es sind keine früheren Nachsorgeuntersuchungen dieses Patienten gespeichert.',
                'italic',
                8.5,
            );
        } else {
            $rows = [];
            foreach ($history as $entry) {
                $rows[] = [
                    (string) $entry['date_display'],
                    (string) $entry['report_label'],
                    $this->orEmpty((string) $entry['physician']),
                    $this->orEmpty((string) $entry['center']),
                ];
            }
            $this->table([
                ['title' => 'Datum', 'width' => 74.0],
                ['title' => 'Bericht', 'width' => 150.0],
                ['title' => 'Arzt', 'width' => 140.0],
                ['title' => 'Nachsorgezentrum', 'width' => self::CONTENT_WIDTH - 74.0 - 150.0 - 140.0],
            ], $rows, 8.0);
            $this->pdf->text(
                self::MARGIN_X,
                $this->y + 8,
                'Die Angaben stammen aus den importierten Nachsorgeberichten und werden unverändert dargestellt.',
                'italic',
                7.5,
                self::MUTED,
            );
            $this->y += 14;
        }

        $this->heading('Hinweise zum Verlauf / Notes on the history');
        $this->paragraph(
            'Diese Seite dokumentiert die im System vorhandenen Nachsorgeuntersuchungen zum '
            . 'Zeitpunkt der Erstellung des Ausweises. Änderungen an Stammdaten oder später '
            . 'importierte Berichte verändern diesen Ausweis nicht.',
            'regular',
            8.5,
            self::TEXT,
        );
        if ($this->y > self::BOTTOM_LIMIT) {
            throw new RuntimeException('Seite 2 des Patientenausweises wurde nicht vollstaendig bedruckt.');
        }
    }

    // --------------------------------------------------------------------- Bausteine

    private function footers(DateTimeImmutable $generatedAt): void
    {
        $total = $this->pdf->pageCount();
        $created = 'Erstellt am ' . $generatedAt->format('d.m.Y H:i:s');
        $patientName = (string) ($this->card['patient']['patient_name'] ?? '');
        for ($page = 0; $page < $total; $page++) {
            $this->pdf->setPage($page);
            $lineY = PdfDocument::PAGE_HEIGHT - 46;
            $this->pdf->line(self::MARGIN_X, $lineY, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $lineY, self::GRID, 0.6);
            $this->pdf->text(
                self::MARGIN_X,
                $lineY + 11,
                'Automatisch erzeugter Patientenausweis auf Basis importierter Nachsorgeberichte – keine medizinische Bewertung oder Diagnose.',
                'regular',
                7,
                self::MUTED,
            );
            $this->pdf->text(
                self::MARGIN_X,
                $lineY + 21,
                sprintf('%s · %s · Ausweisfassung %d', $created, $patientName, (int) ($this->card['card_version'] ?? 1)),
                'regular',
                7,
                self::MUTED,
            );
            $pageLabel = sprintf('Seite %d von %d', $page + 1, $total);
            $width = PdfDocument::textWidth($pageLabel, 'bold', 8);
            $this->pdf->text(PdfDocument::PAGE_WIDTH - self::MARGIN_X - $width, $lineY + 21, $pageLabel, 'bold', 8, self::TEXT);
        }
    }

    private function heading(string $title): void
    {
        $this->y += 5;
        $this->pdf->text(self::MARGIN_X, $this->y + 11, $title, 'bold', 11, self::PRIMARY);
        $this->y += 13;
        $this->pdf->line(self::MARGIN_X, $this->y, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $this->y, self::PRIMARY, 0.7);
        $this->y += 3;
    }

    /**
     * Zwei Tabellen nebeneinander (Patientendaten links, Geraet rechts).
     * Die Zeilen werden im Gleichschritt gesetzt, damit die Hoehen zueinander passen.
     *
     * @param list<array{0: string, 1: string}> $leftRows
     * @param list<array{0: string, 1: string}> $rightRows
     */
    private function tablePair(string $leftTitle, array $leftRows, string $rightTitle, array $rightRows): void
    {
        $fontSize = 8.0;
        $lineHeight = $fontSize * 1.25;
        $columnWidth = (self::CONTENT_WIDTH - self::PAIR_GAP) / 2;
        $valueWidth = $columnWidth - self::PAIR_LABEL_WIDTH - 2 * self::CELL_PAD;
        $columns = [
            ['x' => self::MARGIN_X, 'title' => $leftTitle, 'rows' => $leftRows],
            ['x' => self::MARGIN_X + $columnWidth + self::PAIR_GAP, 'title' => $rightTitle, 'rows' => $rightRows],
        ];

        $this->y += 5;
        foreach ($columns as $column) {
            $this->pdf->text($column['x'], $this->y + 11, $column['title'], 'bold', 11, self::PRIMARY);
            $this->pdf->line($column['x'], $this->y + 13, $column['x'] + $columnWidth, $this->y + 13, self::PRIMARY, 0.7);
        }
        $this->y += 16;

        $rowCount = max(count($leftRows), count($rightRows));
        for ($index = 0; $index < $rowCount; $index++) {
            $cells = [];
            $height = $lineHeight + 2 * self::CELL_PAD;
            foreach ($columns as $column) {
                $row = $column['rows'][$index] ?? null;
                if ($row === null) {
                    continue;
                }
                $labelLines = $this->wrap($row[0], self::PAIR_LABEL_WIDTH - 2 * self::CELL_PAD, 'bold', $fontSize);
                $valueLines = $this->wrap($row[1], $valueWidth, 'regular', $fontSize);
                $cells[] = ['x' => $column['x'], 'label' => $labelLines, 'value' => $valueLines];
                $height = max($height, max(count($labelLines), count($valueLines)) * $lineHeight + 2 * self::CELL_PAD);
            }
            if ($index % 2 === 1) {
                foreach ($columns as $column) {
                    $this->pdf->rect($column['x'], $this->y, $columnWidth, $height, self::ZEBRA_FILL);
                }
            }
            foreach ($cells as $cell) {
                foreach ($cell['label'] as $lineIndex => $line) {
                    $this->pdf->text(
                        $cell['x'] + self::CELL_PAD,
                        $this->y + self::CELL_PAD + $lineIndex * $lineHeight + $lineHeight * 0.76,
                        $line,
                        'bold',
                        $fontSize,
                        self::TEXT,
                    );
                }
                foreach ($cell['value'] as $lineIndex => $line) {
                    $this->pdf->text(
                        $cell['x'] + self::PAIR_LABEL_WIDTH + self::CELL_PAD,
                        $this->y + self::CELL_PAD + $lineIndex * $lineHeight + $lineHeight * 0.76,
                        $line,
                        'regular',
                        $fontSize,
                        self::TEXT,
                    );
                }
            }
            $this->y += $height;
            foreach ($columns as $column) {
                $this->pdf->line($column['x'], $this->y, $column['x'] + $columnWidth, $this->y, self::GRID, 0.3);
            }
        }
        $this->y += 4;
    }

    private function paragraph(string $text, string $font, float $size, array $color): void
    {
        $lineHeight = $size * 1.3;
        foreach ($this->wrap($text, self::CONTENT_WIDTH, $font, $size) as $line) {
            $this->pdf->text(self::MARGIN_X, $this->y + $size, $line, $font, $size, $color);
            $this->y += $lineHeight;
        }
        $this->y += 3;
    }

    /**
     * Hinweisblock; leere Texte werden als "nicht angegeben" dargestellt.
     */
    private function noticeBox(string $text, string $font, float $size): void
    {
        $text = trim($text) === '' ? self::EMPTY : $text;
        $lines = $this->wrap($text, self::CONTENT_WIDTH - 16, $font, $size);
        $lineHeight = $size * 1.3;
        $height = count($lines) * $lineHeight + 8;
        $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::BOX_FILL, self::BOX_BORDER, 0.5);
        foreach ($lines as $index => $line) {
            $this->pdf->text(self::MARGIN_X + 8, $this->y + 4 + ($index + 1) * $lineHeight - 1.5, $line, $font, $size, self::TEXT);
        }
        $this->y += $height + 4;
    }

    private function twoColumnNotices(string $german, string $english): void
    {
        $columnWidth = (self::CONTENT_WIDTH - 10) / 2;
        $texts = [
            ['title' => 'Achtung Flugsicherheit', 'text' => trim($german) === '' ? self::EMPTY : $german],
            ['title' => 'Attention Airline Security', 'text' => trim($english) === '' ? self::EMPTY : $english],
        ];
        $size = 8.0;
        $lineHeight = $size * 1.3;
        $maxLines = 1;
        foreach ($texts as $index => $text) {
            $texts[$index]['lines'] = $this->wrap($text['text'], $columnWidth - 16, 'regular', $size);
            $maxLines = max($maxLines, count($texts[$index]['lines']));
        }
        $height = 16 + $maxLines * $lineHeight + 8;
        foreach ($texts as $index => $text) {
            $x = self::MARGIN_X + $index * ($columnWidth + 10);
            $this->pdf->rect($x, $this->y, $columnWidth, $height, self::BOX_FILL, self::BOX_BORDER, 0.5);
            $this->pdf->text($x + 8, $this->y + 12, $text['title'], 'bold', 8.6, self::PRIMARY);
            foreach ($text['lines'] as $lineIndex => $line) {
                $this->pdf->text($x + 8, $this->y + 16 + ($lineIndex + 1) * $lineHeight - 1.5, $line, 'regular', $size, self::TEXT);
            }
        }
        $this->y += $height + 4;
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     */
    private function keyValueTable(array $rows): void
    {
        $this->table([
            ['title' => '', 'width' => 150.0, 'font' => 'bold'],
            ['title' => '', 'width' => self::CONTENT_WIDTH - 150.0],
        ], $rows, 8.2, false);
    }

    /**
     * Tabelle ohne Seitenumbruch (Seite 1 und 2 sind fest aufgeteilt).
     *
     * @param list<array{title: string, width: float, align?: string, font?: string}> $columns
     * @param list<list<string>> $rows
     */
    private function table(array $columns, array $rows, float $fontSize, bool $showHeader = true): void
    {
        $lineHeight = $fontSize * 1.25;
        if ($showHeader) {
            $this->tableHeader($columns, $fontSize, $lineHeight);
        }
        foreach ($rows as $index => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $cell = $row[$columnIndex] ?? '';
                $font = $column['font'] ?? 'regular';
                $lines = $this->wrap($cell, $column['width'] - 2 * self::CELL_PAD, $font, $fontSize);
                $cells[] = ['lines' => $lines, 'font' => $font];
                $maxLines = max($maxLines, count($lines));
            }
            $height = $maxLines * $lineHeight + 2 * self::CELL_PAD;
            if ($index % 2 === 1) {
                $this->pdf->rect(self::MARGIN_X, $this->y, self::CONTENT_WIDTH, $height, self::ZEBRA_FILL);
            }
            $x = self::MARGIN_X;
            foreach ($columns as $columnIndex => $column) {
                $cell = $cells[$columnIndex];
                foreach ($cell['lines'] as $lineIndex => $line) {
                    $textX = $x + self::CELL_PAD;
                    if (($column['align'] ?? 'L') === 'R') {
                        $textX = $x + $column['width'] - self::CELL_PAD - PdfDocument::textWidth($line, $cell['font'], $fontSize);
                    }
                    $baseline = $this->y + self::CELL_PAD + $lineIndex * $lineHeight + $lineHeight * 0.76;
                    $this->pdf->text($textX, $baseline, $line, $cell['font'], $fontSize, self::TEXT);
                }
                $x += $column['width'];
            }
            $this->y += $height;
            $this->pdf->line(self::MARGIN_X, $this->y, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $this->y, self::GRID, 0.3);
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
     * Zeilenumbruch nach Breite; respektiert Zeilenumbrueche, trennt ueberlange Woerter hart.
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

    private function orEmpty(string $value): string
    {
        $value = trim($value);
        return $value === '' ? self::EMPTY : $value;
    }
}
