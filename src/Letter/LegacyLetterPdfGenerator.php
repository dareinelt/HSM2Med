<?php

declare(strict_types=1);

namespace App\Letter;

use App\Report\Pdf\ImageData;
use App\Report\Pdf\PdfDocument;
use App\Support\DateInput;
use DateTimeImmutable;
use RuntimeException;

/**
 * Erzeugt das PDF eines Briefes der Brief-Fassung 1 (fester Aufbau vor Einfuehrung der
 * Briefvorlagen). Unveraendert erhalten, damit Briefe aus dieser Zeit jederzeit mit dem damals
 * verwendeten Aufbau reproduziert werden koennen; neue Briefe erzeugt LetterPdfGenerator.
 *
 * Aufbau (Konzept §6):
 *   1. Briefkopf      – Logo und Nachsorgezentrum aus der Stammdaten-Fassung
 *   2. Patientendaten – Name, Geburtsdatum, Patienten-ID, Anschrift
 *   3. Anamnese       – Textfassung des Bausteins
 *   4. Vormedikation  – Tabelle (oder Textfassung)
 *   5. Befund         – nur wenn ein Bericht verknuepft ist (Geraet, Sonden, Messwerte)
 *   6. Epikrise       – Textfassung des Bausteins
 *   7. Anhang         – vollstaendige Tabelle der Abfrage, mehrseitig mit wiederholter Kopfzeile
 *
 * Anders als der Patientenausweis (harte Grenze: genau zwei Seiten) darf der Brief beliebig
 * viele Seiten haben. Der Ausweis bleibt unberuehrt.
 *
 * Der Generator liest ausschliesslich den Snapshot des Briefes; das PDF ist damit ohne
 * Datenbank reproduzierbar. Es wird nichts ergaenzt, was nicht im Snapshot steht; fehlende
 * Angaben erscheinen als "nicht angegeben".
 */
final class LegacyLetterPdfGenerator
{
    /** Fassung des Brief-Snapshots, die dieser Generator kennt. */
    public const int SUPPORTED_LETTER_VERSION = 1;

    private const float MARGIN_X = 42.0;
    private const float CONTENT_WIDTH = PdfDocument::PAGE_WIDTH - 2 * self::MARGIN_X;
    private const float TOP = 40.0;
    private const float BOTTOM_LIMIT = PdfDocument::PAGE_HEIGHT - 66.0;
    private const float CELL_PAD = 0.5;

    private const float LOGO_WIDTH = 119.0;
    private const float LOGO_HEIGHT = 76.9;
    private const float LABEL_WIDTH = 97.6;

    private const float SIZE_TITLE = 15.0;
    private const float SIZE_CENTER = 11.5;
    private const float SIZE_SECTION = 11.0;
    private const float SIZE_FIELD = 9.5;
    private const float SIZE_TEXT = 9.0;
    private const float SIZE_TABLE = 8.0;
    private const float SIZE_SMALL = 7.5;

    private const float LINE_TITLE = 1.35;
    private const float LINE_SECTION = 1.40;
    private const float LINE_FIELD = 1.49;
    private const float LINE_TEXT = 1.37;
    private const float LINE_TABLE = 1.30;

    private const float SECTION_GAP = 9.0;

    private const array INK = [0.0, 0.0, 0.0];
    private const array MUTED = [0.42, 0.42, 0.42];
    private const array RULE = [0.0, 0.0, 0.0];
    private const array SECTION_FILL = [0.88, 0.88, 0.88];

    private const string EMPTY = 'nicht angegeben';

    /** Spalten der Vormedikationstabelle; Summe entspricht der Inhaltsbreite. */
    private const array PREMED_COLUMNS = [
        ['title' => 'Präparat', 'width' => 148.0],
        ['title' => 'Dosis', 'width' => 62.0],
        ['title' => 'Einnahme', 'width' => 86.0],
        ['title' => 'Grund', 'width' => 118.0],
        ['title' => 'Zeitraum', 'width' => 97.28],
    ];

    private const float APPENDIX_LABEL_WIDTH = 296.0;

    private PdfDocument $pdf;

    /** @var array<string, mixed> */
    private array $letter = [];

    public function __construct(private readonly bool $compress = true)
    {
    }

    /**
     * @param array<string, mixed> $letter Brief-Snapshot (siehe LetterService::snapshot())
     */
    public function generate(array $letter, ?ImageData $logo, DateTimeImmutable $generatedAt): string
    {
        if ((int) ($letter['letter_version'] ?? 0) !== self::SUPPORTED_LETTER_VERSION) {
            throw new RuntimeException(sprintf(
                'Brief-Fassung %s wird von diesem PDF-Generator nicht unterstuetzt.',
                (string) ($letter['letter_version'] ?? '?'),
            ));
        }
        $this->pdf = new PdfDocument($this->compress);
        $this->letter = $letter;

        $this->pdf->addPage();
        $y = $this->header($logo);
        $y = $this->patientBlock($y);
        $y = $this->body($y);
        $this->appendix();
        $this->footers($generatedAt);

        return $this->pdf->output([
            'Title' => sprintf('Brief zur Schrittmacher-/ICD-Abfrage – %s', $this->patientName()),
            'Subject' => 'Automatisch erzeugter Brief zur Schrittmacher-/ICD-Abfrage – keine medizinische Bewertung',
            'Creator' => 'HSM2Med',
            'Producer' => 'HSM2Med PdfDocument',
        ], $generatedAt);
    }

    public static function filename(array $letter): string
    {
        $parts = ['Brief', 'Schrittmacher-ICD-Abfrage', (string) ($letter['patient']['patient_name'] ?? 'Patient')];
        $date = (string) ($letter['document']['letter_date'] ?? $letter['generated_at'] ?? '');
        if ($date !== '') {
            $parts[] = substr($date, 0, 10);
        }
        return \App\Security\FileName::downloadName(implode('_', $parts) . '.pdf');
    }

    /**
     * Seitenzahl eines erzeugten Briefes (der Brief ist nicht auf zwei Seiten begrenzt).
     */
    public static function pageCount(string $pdf): int
    {
        if (preg_match('#/Type /Pages /Kids \[[^\]]*\] /Count (\d+)#', $pdf, $matches) !== 1) {
            return 0;
        }
        return (int) $matches[1];
    }

    // --------------------------------------------------------------------- Briefkopf und Patient

    /**
     * Briefkopf: Logo, Nachsorgezentrum, Titelzeile mit Dokumentnummer und Briefdatum.
     */
    private function header(?ImageData $logo): float
    {
        $this->logo(self::MARGIN_X, self::TOP, $logo);

        $textX = self::MARGIN_X + self::LOGO_WIDTH + 16.6;
        $textWidth = PdfDocument::PAGE_WIDTH - self::MARGIN_X - $textX;
        $y = self::TOP + 2.0;
        $center = trim((string) ($this->letter['master']['center_name'] ?? ''));
        $y = $this->paragraph($textX, $y, $textWidth, $center === '' ? self::EMPTY : $center, 'bold', self::SIZE_CENTER, self::INK, 1.25);
        $address = trim((string) ($this->letter['master']['center_address'] ?? ''));
        if ($address !== '') {
            $y = $this->paragraph($textX, $y, $textWidth, $address, 'regular', self::SIZE_SMALL, self::INK, 1.25);
        }

        $y = max($y, self::TOP + self::LOGO_HEIGHT) + 8.0;
        $y = $this->divider(self::MARGIN_X, $y, self::CONTENT_WIDTH);
        $y = $this->sectionTitle(self::MARGIN_X, $y, self::CONTENT_WIDTH, 'Brief zur Schrittmacher-/ICD-Abfrage', self::SIZE_TITLE, self::LINE_TITLE);
        $y = $this->paragraph(
            self::MARGIN_X,
            $y + 1.0,
            self::CONTENT_WIDTH,
            sprintf(
                'Dokument %s · Briefdatum %s · Nachsorgezentrum-Angaben aus Stammdatenfassung %d',
                (string) ($this->letter['document']['document_number'] ?? self::EMPTY),
                $this->displayDate((string) ($this->letter['document']['letter_date'] ?? '')),
                (int) ($this->letter['master']['settings_version'] ?? 1),
            ),
            'regular',
            self::SIZE_SMALL,
            self::MUTED,
        );
        return $y + 4.0;
    }

    private function patientBlock(float $y): float
    {
        $y = $this->sectionTitle(self::MARGIN_X, $y, self::CONTENT_WIDTH, 'Patientendaten', self::SIZE_SECTION);
        $patient = (array) ($this->letter['patient'] ?? []);
        $address = (array) ($patient['address'] ?? []);
        $addressLine = trim(implode(' ', array_filter([
            trim((string) ($address['postal_code'] ?? '')),
            trim((string) ($address['city'] ?? '')),
        ])));
        $rows = [
            ['Name', $this->orEmpty((string) ($patient['patient_name'] ?? ''))],
            ['Geburtsdatum', $this->displayDate((string) ($patient['date_of_birth'] ?? ''))],
            ['Patienten-ID', $this->orEmpty((string) ($patient['patient_identifier'] ?? ''))],
            ['Anschrift', $this->addressLines($address, $addressLine)],
        ];
        $y = $this->fieldRows(self::MARGIN_X, $y, self::CONTENT_WIDTH, $rows, self::LABEL_WIDTH, self::SIZE_FIELD, self::LINE_FIELD);
        return $this->paragraph(
            self::MARGIN_X,
            $y + 2.0,
            self::CONTENT_WIDTH,
            'Sehr geehrte Damen und Herren,',
            'regular',
            self::SIZE_TEXT,
        );
    }

    /**
     * @param array<string, mixed> $address
     */
    private function addressLines(array $address, string $addressLine): string
    {
        $lines = [];
        foreach ([trim((string) ($address['street'] ?? '')), $addressLine, trim((string) ($address['phone'] ?? '')) !== '' ? 'Telefon: ' . trim((string) $address['phone']) : ''] as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines === [] ? self::EMPTY : implode("\n", $lines);
    }

    // --------------------------------------------------------------------- Brieftext

    private function body(float $y): float
    {
        $y = $this->textSection($y, 'Anamnese', (array) ($this->letter['anamnesis'] ?? []));
        $y = $this->premedicationSection($y, (array) ($this->letter['premedication'] ?? []));
        $y = $this->reportSection($y, $this->letter['report'] ?? null);
        $y = $this->textSection($y, 'Epikrise', (array) ($this->letter['epicrisis'] ?? []));
        return $this->paragraph(
            self::MARGIN_X,
            $y,
            self::CONTENT_WIDTH,
            'Mit freundlichen Grüßen',
            'regular',
            self::SIZE_TEXT,
        );
    }

    /**
     * Textbaustein (Anamnese, Epikrise) mit Kopfzeile, Stand der Fassung und Autor.
     *
     * @param array<string, mixed> $record
     */
    private function textSection(float $y, string $title, array $record): float
    {
        $y = $this->sectionStart($y, $title, $this->recordMeta($record));
        $text = trim((string) ($record['text'] ?? ''));
        $y = $this->paragraph(
            self::MARGIN_X,
            $y,
            self::CONTENT_WIDTH,
            $text === '' ? self::EMPTY : $text,
            'regular',
            self::SIZE_TEXT,
        );
        return $y + self::SECTION_GAP;
    }

    /**
     * Vormedikation: strukturierte Tabelle, zusaetzlich die Textfassung, falls vorhanden.
     *
     * @param array<string, mixed> $record
     */
    private function premedicationSection(float $y, array $record): float
    {
        $y = $this->sectionStart($y, 'Vormedikation', $this->recordMeta($record));
        $entries = [];
        foreach ((array) ($record['entries'] ?? []) as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        if ($entries !== []) {
            $rows = [];
            foreach ($entries as $entry) {
                $dose = trim((string) ($entry['dose'] ?? '') . ' ' . (string) ($entry['unit'] ?? ''));
                $rows[] = [
                    $this->orEmpty((string) ($entry['substance'] ?? '')),
                    $dose === '' ? self::EMPTY : $dose,
                    $this->orEmpty((string) ($entry['schedule'] ?? '')),
                    $this->orEmpty((string) ($entry['reason'] ?? '')),
                    $this->period((string) ($entry['from'] ?? ''), (string) ($entry['to'] ?? '')),
                ];
            }
            $y = $this->flowTable(self::MARGIN_X, $y, self::PREMED_COLUMNS, $rows, self::SIZE_TABLE);
        }
        $text = trim((string) ($record['text'] ?? ''));
        if ($text !== '') {
            $y = $this->paragraph(self::MARGIN_X, $y, self::CONTENT_WIDTH, $text, 'regular', self::SIZE_TEXT);
        } elseif ($entries === []) {
            $y = $this->paragraph(self::MARGIN_X, $y, self::CONTENT_WIDTH, self::EMPTY, 'regular', self::SIZE_TEXT);
        }
        return $y + self::SECTION_GAP;
    }

    /**
     * Befundteil "Schrittmacher-/ICD-Abfrage" aus dem verknuepften Bericht; ohne Bericht entfaellt
     * der Abschnitt vollstaendig.
     */
    private function reportSection(float $y, mixed $report): float
    {
        if (!is_array($report)) {
            return $y;
        }
        $label = 'Befund: Schrittmacher-/ICD-Abfrage';
        $meta = trim((string) ($report['meta'] ?? ''));
        $y = $this->sectionStart($y, $label, $meta);
        $rows = [];
        foreach ((array) ($report['rows'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
                $rows[] = [(string) $row['label'], (string) $row['value']];
            }
        }
        if ($rows !== []) {
            $y = $this->fieldRows(self::MARGIN_X, $y, self::CONTENT_WIDTH, $rows, self::LABEL_WIDTH, self::SIZE_FIELD, self::LINE_FIELD);
        }
        foreach ((array) ($report['leads'] ?? []) as $lead) {
            if (!is_array($lead)) {
                continue;
            }
            $leadRows = [];
            foreach ((array) ($lead['rows'] ?? []) as $row) {
                if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
                    $leadRows[] = [(string) $row['label'], (string) $row['value']];
                }
            }
            if ($leadRows === []) {
                continue;
            }
            $y = $this->ensureSpace($y, self::SIZE_FIELD * self::LINE_FIELD * 2.0);
            $y = $this->subTitle($y, (string) ($lead['label'] ?? ''));
            $y = $this->fieldRows(self::MARGIN_X, $y, self::CONTENT_WIDTH, $leadRows, self::LABEL_WIDTH, self::SIZE_FIELD, self::LINE_FIELD);
        }
        foreach ((array) ($report['groups'] ?? []) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupRows = [];
            foreach ((array) ($group['rows'] ?? []) as $row) {
                if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
                    $groupRows[] = [(string) $row['label'], (string) $row['value']];
                }
            }
            if ($groupRows === []) {
                continue;
            }
            $y = $this->ensureSpace($y, self::SIZE_FIELD * self::LINE_FIELD * 2.0);
            $y = $this->subTitle($y, (string) ($group['label'] ?? ''));
            $y = $this->fieldRows(self::MARGIN_X, $y, self::CONTENT_WIDTH, $groupRows, self::LABEL_WIDTH, self::SIZE_FIELD, self::LINE_FIELD);
        }
        return $y + self::SECTION_GAP;
    }

    /**
     * Anhang: vollstaendige Tabelle der Abfrage, beginnt auf einer neuen Seite und laeuft
     * mehrseitig mit wiederholter Kopfzeile.
     */
    private function appendix(): void
    {
        $appendix = (array) ($this->letter['appendix'] ?? []);
        $sections = [];
        foreach ((array) ($appendix['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections[] = $section;
            }
        }
        if ($sections === []) {
            return;
        }

        $this->pdf->addPage();
        $y = self::TOP;
        $y = $this->sectionTitle(
            self::MARGIN_X,
            $y,
            self::CONTENT_WIDTH,
            'Anhang: Schrittmacher-/ICD-Abfrage (vollständige Tabelle)',
            self::SIZE_TITLE,
            self::LINE_TITLE,
        );
        $y = $this->paragraph(
            self::MARGIN_X,
            $y + 1.0,
            self::CONTENT_WIDTH,
            $this->appendixMeta($appendix),
            'regular',
            self::SIZE_SMALL,
            self::MUTED,
        );
        $y += 2.0;
        $y = $this->appendixHeader($y);

        foreach ($sections as $section) {
            $rows = [];
            foreach ((array) ($section['rows'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = [(string) ($row['label'] ?? ''), (string) ($row['value'] ?? '')];
                }
            }
            if ($rows === []) {
                continue;
            }
            $y = $this->appendixSectionRow($y, (string) ($section['label'] ?? ''));
            $y = $this->appendixRows($y, $rows);
        }

        $notes = trim((string) ($appendix['notes'] ?? ''));
        if ($notes !== '') {
            $y = $this->appendixSectionRow($y, 'Bemerkungen');
            $y = $this->paragraph(self::MARGIN_X, $y, self::CONTENT_WIDTH, $notes, 'regular', self::SIZE_TEXT);
        }
    }

    /**
     * @param array<string, mixed> $appendix
     */
    private function appendixMeta(array $appendix): string
    {
        $parts = [];
        $deviceType = trim((string) ($appendix['device_type_label'] ?? ''));
        if ($deviceType !== '') {
            $parts[] = 'Geräteart: ' . $deviceType;
        }
        $parts[] = 'angegebene Werte: ' . (int) ($appendix['filled'] ?? 0);
        $mrt = trim((string) ($appendix['mrt_label'] ?? ''));
        if ($mrt !== '') {
            $parts[] = $mrt;
        }
        return 'Vollständige Angaben aus der Schrittmacher-/ICD-Abfrage · ' . implode(' · ', $parts);
    }

    private function appendixHeader(float $y): float
    {
        $y = $this->ensureSpace($y, self::SIZE_SMALL * self::LINE_TABLE + 6.0);
        $this->pdf->text(self::MARGIN_X + self::CELL_PAD, $y + 0.72 * self::SIZE_SMALL, 'Parameter', 'bold', self::SIZE_SMALL, self::INK);
        $this->pdf->text(
            self::MARGIN_X + self::APPENDIX_LABEL_WIDTH + self::CELL_PAD,
            $y + 0.72 * self::SIZE_SMALL,
            'Wert',
            'bold',
            self::SIZE_SMALL,
            self::INK,
        );
        $y += self::SIZE_SMALL * self::LINE_TABLE + 2 * self::CELL_PAD;
        $this->pdf->line(self::MARGIN_X, $y, self::MARGIN_X + self::CONTENT_WIDTH, $y, self::RULE, 0.7);
        return $y + 2.0;
    }

    /**
     * Abschnittszeile der Anhangstabelle (grau hinterlegt, ueber die ganze Breite).
     */
    private function appendixSectionRow(float $y, string $label): float
    {
        $height = self::SIZE_TABLE * self::LINE_TABLE + 3.0;
        if ($y + $height > self::BOTTOM_LIMIT) {
            $this->pdf->addPage();
            $y = $this->appendixHeader(self::TOP);
        }
        $this->pdf->rect(self::MARGIN_X, $y, self::CONTENT_WIDTH, $height, self::SECTION_FILL, null, 0.0);
        $this->pdf->text(
            self::MARGIN_X + self::CELL_PAD,
            $y + 1.5 + 0.72 * self::SIZE_TABLE,
            $label,
            'bold',
            self::SIZE_TABLE,
            self::INK,
        );
        return $y + $height + 2.0;
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     */
    private function appendixRows(float $y, array $rows): float
    {
        $lineHeight = self::SIZE_TABLE * self::LINE_TABLE;
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $labelLines = $this->wrap($row[0], self::APPENDIX_LABEL_WIDTH - 2 * self::CELL_PAD, 'regular', self::SIZE_TABLE);
            $valueLines = $this->wrap($row[1], self::CONTENT_WIDTH - self::APPENDIX_LABEL_WIDTH - 2 * self::CELL_PAD, 'bold', self::SIZE_TABLE);
            $height = max(count($labelLines), count($valueLines)) * $lineHeight + 2 * self::CELL_PAD;
            if ($y + $height > self::BOTTOM_LIMIT) {
                $this->pdf->addPage();
                $y = $this->appendixHeader(self::TOP);
            }
            foreach ($labelLines as $lineIndex => $line) {
                $this->pdf->text(
                    self::MARGIN_X + self::CELL_PAD,
                    $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.72 * self::SIZE_TABLE,
                    $line,
                    'regular',
                    self::SIZE_TABLE,
                    self::INK,
                );
            }
            foreach ($valueLines as $lineIndex => $line) {
                $this->pdf->text(
                    self::MARGIN_X + self::APPENDIX_LABEL_WIDTH + self::CELL_PAD,
                    $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.72 * self::SIZE_TABLE,
                    $line,
                    'bold',
                    self::SIZE_TABLE,
                    self::INK,
                );
            }
            $y += $height;
            if ($index < $last) {
                $this->pdf->line(self::MARGIN_X, $y, self::MARGIN_X + self::CONTENT_WIDTH, $y, self::RULE, 0.4);
                $y += 1.5;
            }
        }
        return $y + 3.0;
    }

    // --------------------------------------------------------------------- Bausteine

    /**
     * Abschnitt mit Kopfzeile und optionaler Standzeile.
     */
    private function sectionStart(float $y, string $title, string $meta): float
    {
        $y = $this->ensureSpace($y, self::SIZE_SECTION * self::LINE_SECTION + 3.0 * self::SIZE_TEXT * self::LINE_TEXT);
        $y = $this->sectionTitle(self::MARGIN_X, $y, self::CONTENT_WIDTH, $title, self::SIZE_SECTION);
        if ($meta !== '') {
            $y = $this->paragraph(self::MARGIN_X, $y, self::CONTENT_WIDTH, $meta, 'regular', self::SIZE_SMALL, self::MUTED);
        }
        return $y;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordMeta(array $record): string
    {
        $version = (int) ($record['version'] ?? 0);
        if ($version <= 0) {
            return '';
        }
        $parts = [sprintf('Fassung %d', $version)];
        $author = trim((string) ($record['author_name'] ?? ''));
        if ($author !== '') {
            $parts[] = 'erfasst von ' . $author;
        }
        $created = trim((string) ($record['version_created_at'] ?? ''));
        if ($created !== '') {
            $parts[] = 'Stand ' . $this->displayDateTime($created);
        }
        return implode(' · ', $parts);
    }

    private function subTitle(float $y, string $label): float
    {
        $this->pdf->text(self::MARGIN_X, $y + 0.72 * self::SIZE_FIELD, $label, 'bold', self::SIZE_FIELD, self::INK);
        return $y + self::SIZE_FIELD * self::LINE_FIELD;
    }

    /**
     * Seite anlegen, wenn der Platz nicht mehr reicht.
     */
    private function ensureSpace(float $y, float $needed): float
    {
        if ($y + $needed <= self::BOTTOM_LIMIT) {
            return $y;
        }
        $this->pdf->addPage();
        return self::TOP;
    }

    private function sectionTitle(
        float $x,
        float $y,
        float $width,
        string $title,
        float $size = self::SIZE_SECTION,
        float $lineHeightRatio = self::LINE_SECTION,
    ): float {
        $this->pdf->text($x, $y + 0.72 * $size, $title, 'bold', $size, self::INK);
        return $y + $size * $lineHeightRatio;
    }

    private function divider(float $x, float $y, float $width): float
    {
        $this->pdf->line($x, $y + 0.5, $x + $width, $y + 0.5, self::RULE, 0.8);
        return $y + 8.5;
    }

    /**
     * Beschriftung links, Wert rechts daneben; mit Seitenumbruch vor jeder Zeile.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    private function fieldRows(
        float $x,
        float $y,
        float $width,
        array $rows,
        float $labelWidth = self::LABEL_WIDTH,
        float $fontSize = self::SIZE_FIELD,
        float $lineHeightRatio = self::LINE_FIELD,
    ): float {
        $lineHeight = $fontSize * $lineHeightRatio;
        foreach ($rows as $row) {
            $labelLines = $this->wrap($row[0], $labelWidth - 2.0, 'regular', $fontSize);
            $valueLines = $this->wrap($row[1], $width - $labelWidth, 'bold', $fontSize);
            $y = $this->ensureSpace($y, max(count($labelLines), count($valueLines)) * $lineHeight);
            foreach ($labelLines as $index => $line) {
                $this->pdf->text($x, $y + $index * $lineHeight + 0.72 * $fontSize, $line, 'regular', $fontSize, self::INK);
            }
            foreach ($valueLines as $index => $line) {
                $this->pdf->text($x + $labelWidth, $y + $index * $lineHeight + 0.72 * $fontSize, $line, 'bold', $fontSize, self::INK);
            }
            $y += max(count($labelLines), count($valueLines)) * $lineHeight;
        }
        return $y + 3.0;
    }

    private function paragraph(
        float $x,
        float $y,
        float $width,
        string $text,
        string $font = 'regular',
        float $size = self::SIZE_TEXT,
        array $color = self::INK,
        float $lineHeightRatio = self::LINE_TEXT,
    ): float {
        $text = trim($text) === '' ? self::EMPTY : $text;
        $lineHeight = $size * $lineHeightRatio;
        foreach ($this->wrap($text, $width, $font, $size) as $line) {
            $y = $this->ensureSpace($y, $lineHeight);
            $this->pdf->text($x, $y + 0.72 * $size, $line, $font, $size, $color);
            $y += $lineHeight;
        }
        return $y + 1.5;
    }

    /**
     * Tabelle mit Seitenumbruch und wiederholter Kopfzeile (Vormedikation).
     *
     * @param list<array{title: string, width: float}> $columns
     * @param list<list<string>> $rows
     */
    private function flowTable(float $x, float $y, array $columns, array $rows, float $fontSize = self::SIZE_TABLE): float
    {
        $lineHeight = $fontSize * self::LINE_TABLE;
        $header = function (float $lineY) use ($x, $columns, $fontSize, $lineHeight): float {
            $cellX = $x;
            foreach ($columns as $column) {
                $this->pdf->text($cellX + self::CELL_PAD, $lineY + self::CELL_PAD + 0.72 * $fontSize, $column['title'], 'bold', $fontSize, self::INK);
                $cellX += $column['width'];
            }
            $lineY += $lineHeight + 2 * self::CELL_PAD;
            $this->pdf->line($x, $lineY, $x + self::CONTENT_WIDTH, $lineY, self::RULE, 0.7);
            return $lineY + 1.5;
        };
        $y = $this->ensureSpace($y, $lineHeight * 3.0);
        $y = $header($y);

        foreach ($rows as $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $index => $column) {
                $lines = $this->wrap((string) ($row[$index] ?? ''), $column['width'] - 2 * self::CELL_PAD, 'regular', $fontSize);
                $cells[] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $height = $maxLines * $lineHeight + 2 * self::CELL_PAD;
            if ($y + $height > self::BOTTOM_LIMIT) {
                $this->pdf->addPage();
                $y = $header(self::TOP);
            }
            $cursor = $x;
            foreach ($columns as $index => $column) {
                foreach ($cells[$index] as $lineIndex => $line) {
                    $this->pdf->text(
                        $cursor + self::CELL_PAD,
                        $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.72 * $fontSize,
                        $line,
                        'regular',
                        $fontSize,
                        self::INK,
                    );
                }
                $cursor += $column['width'];
            }
            $y += $height;
            $this->pdf->line($x, $y, $x + self::CONTENT_WIDTH, $y, self::RULE, 0.4);
            $y += 1.5;
        }
        return $y + 3.0;
    }

    private function footers(DateTimeImmutable $generatedAt): void
    {
        $total = $this->pdf->pageCount();
        $document = (string) ($this->letter['document']['document_number'] ?? '');
        $created = 'Erstellt am ' . $generatedAt->format('d.m.Y H:i:s');
        for ($page = 0; $page < $total; $page++) {
            $this->pdf->setPage($page);
            $lineY = PdfDocument::PAGE_HEIGHT - 46;
            $this->pdf->line(self::MARGIN_X, $lineY, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $lineY, self::MUTED, 0.4);
            $this->pdf->text(
                self::MARGIN_X,
                $lineY + 11,
                'Automatisch erzeugter Brief auf Basis der Patientenakte – keine medizinische Bewertung oder Diagnose.',
                'regular',
                7,
                self::MUTED,
            );
            $this->pdf->text(
                self::MARGIN_X,
                $lineY + 21,
                sprintf('%s · Dokument %s · Brief-Fassung %d', $created, $document, (int) ($this->letter['letter_version'] ?? 1)),
                'regular',
                7,
                self::MUTED,
            );
            $pageLabel = sprintf('Seite %d von %d', $page + 1, $total);
            $width = PdfDocument::textWidth($pageLabel, 'bold', 8);
            $this->pdf->text(PdfDocument::PAGE_WIDTH - self::MARGIN_X - $width, $lineY + 21, $pageLabel, 'bold', 8, self::INK);
        }
    }

    private function logo(float $x, float $y, ?ImageData $logo): void
    {
        if ($logo !== null && $logo->width > 0 && $logo->height > 0) {
            $scale = min(self::LOGO_WIDTH / $logo->width, self::LOGO_HEIGHT / $logo->height);
            $this->pdf->image($x, $y, $logo->width * $scale, $logo->height * $scale, $logo);
            return;
        }
        $this->pdf->rect($x, $y, self::LOGO_WIDTH, self::LOGO_HEIGHT, null, self::RULE, 0.6);
        $label = 'Logo nicht hinterlegt';
        $this->pdf->text(
            $x + (self::LOGO_WIDTH - PdfDocument::textWidth($label, 'italic', self::SIZE_TABLE)) / 2,
            $y + self::LOGO_HEIGHT / 2 + 0.35 * self::SIZE_TABLE,
            $label,
            'italic',
            self::SIZE_TABLE,
            self::MUTED,
        );
    }

    /**
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

    private function period(string $from, string $to): string
    {
        if ($from === '' && $to === '') {
            return self::EMPTY;
        }
        return sprintf(
            'von %s bis %s',
            $from === '' ? 'unbekannt' : DateInput::format($from),
            $to === '' ? 'fortlaufend' : DateInput::format($to),
        );
    }

    private function patientName(): string
    {
        return (string) ($this->letter['patient']['patient_name'] ?? 'Patient');
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::EMPTY;
        }
        return DateInput::format($value);
    }

    private function displayDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::EMPTY;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('d.m.Y H:i', $timestamp);
    }

    private function orEmpty(string $value): string
    {
        $value = trim($value);
        return $value === '' ? self::EMPTY : $value;
    }
}
