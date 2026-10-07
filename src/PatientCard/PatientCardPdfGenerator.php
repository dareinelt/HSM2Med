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
 * Seite 1 bildet die Vorlage (.reference/idcard_ann.png) nach:
 *   oben links:  Logo, Ueberschrift "Schrittmacher - Patientenausweis" mit
 *                "(Patient Identification Card)" sowie "Patientendaten:" mit Notfallkontakt,
 *                Hausarzt und betreuendem Nachsorgezentrum;
 *   oben rechts: "Implantate:" mit den Tabellen "Schrittmacher" (Modell / Impl.Ort / Impl.Datum)
 *                und "Elektroden" (Modell / Lokalisation / Impl.Datum), darunter "Hinweise:",
 *                "Achtung Flugsicherheit:" und "Attention Airline Security:";
 *   unten:       "Sonstiges", "Bemerkung", "Arzt", "Naechste Kontrolle in" und der Barcode.
 * Seite 2: vergangene Nachsorgeuntersuchungen.
 *
 * Spaltenraster, Schriftgroessen und Zeilenabstaende sind aus der Vorlage abgeleitet.
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
    private const float BOTTOM_LIMIT = PdfDocument::PAGE_HEIGHT - 60.0;
    private const float CELL_PAD = 0.5;

    /** Oberkante des Ausweisinhalts; beide Spalten beginnen auf dieser Hoehe. */
    private const float TOP = 34.2;

    /** Spaltenraster der Vorlage: Patientendaten links, Implantate rechts. */
    private const float LEFT_COLUMN_WIDTH = 245.6;
    private const float COLUMN_GAP = 9.7;
    private const float RIGHT_COLUMN_WIDTH = self::CONTENT_WIDTH - self::LEFT_COLUMN_WIDTH - self::COLUMN_GAP;
    private const float RIGHT_COLUMN_X = self::MARGIN_X + self::LEFT_COLUMN_WIDTH + self::COLUMN_GAP;

    /** Logofeld der Vorlage (Platzhalterrahmen, wenn kein Logo hinterlegt ist). */
    private const float LOGO_WIDTH = 119.0;
    private const float LOGO_HEIGHT = 76.9;
    private const float LOGO_GAP = 16.6;

    /** Breite der Beschriftungsspalte in "Patientendaten" bzw. im Abschlussblock. */
    private const float LABEL_WIDTH = 76.9;
    private const float SUMMARY_LABEL_WIDTH = 97.6;

    // Schriftgroessen der Vorlage
    private const float SIZE_HEADING = 13.5;
    private const float SIZE_IMPLANT_TITLE = 11.5;
    private const float SIZE_SECTION = 9.5;
    private const float SIZE_FIELD = 9.5;
    private const float SIZE_SUMMARY = 9.5;
    private const float SIZE_NOTICE_TITLE = 7.6;
    private const float SIZE_NOTICE = 7.6;
    private const float SIZE_TABLE = 7.5;
    private const float SIZE_BARCODE = 6.0;

    // Zeilenhoehen (Vielfaches der Schriftgroesse)
    private const float LINE_SECTION = 1.40;
    private const float LINE_FIELD = 1.49;
    private const float LINE_TEXT = 1.37;
    private const float LINE_SUMMARY = 1.55;

    /** Ueberschrift "Betreuendes Nachsorgezentrum:" rueckt enger an den folgenden Adressblock (Vorlage). */
    private const float LINE_CENTER_TITLE = 1.16;
    private const float LINE_CENTER = 1.32;

    // Abstaende zwischen den Abschnitten der rechten Spalte
    private const float SECTION_GAP = 28.0;
    private const float FLIGHT_GAP = 38.0;
    private const float PAIRED_GAP = 8.0;

    /** Abstand zwischen dem Ende der laengeren Spalte und der Linie des Abschlussblocks. */
    private const float SUMMARY_GAP = 11.5;

    private const float BARCODE_X = self::MARGIN_X + 93.1;
    private const float BARCODE_WIDTH = 93.1;
    private const float BARCODE_HEIGHT = 11.6;

    private const array INK = [0.0, 0.0, 0.0];
    private const array MUTED = [0.42, 0.42, 0.42];
    private const array RULE = [0.0, 0.0, 0.0];

    private const string EMPTY = 'nicht angegeben';

    /**
     * Code 39: je Zeichen neun Elemente (Balken/Luecke im Wechsel), drei davon breit.
     * Wird nur fuer den Barcode auf Seite 1 verwendet.
     */
    private const array CODE39 = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
    ];

    private PdfDocument $pdf;
    /** @var array<string, mixed> */
    private array $card = [];

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

        $left = $this->patientColumn($logo);
        $right = $this->implantColumn();
        $bottom = $this->summaryBlock(max($left, $right) + self::SUMMARY_GAP);

        if ($bottom > self::BOTTOM_LIMIT) {
            throw new RuntimeException(
                'Die Stammdatentexte sind zu lang: Seite 1 des Patientenausweises wurde nicht vollstaendig bedruckt.',
            );
        }
    }

    /**
     * Linke Spalte der Vorlage: Logo, Ueberschrift und Patientendaten.
     */
    private function patientColumn(?ImageData $logo): float
    {
        $patient = $this->card['patient'];
        $contact = $this->card['emergency_contact'];
        $physician = $this->card['physician'];
        $settings = $this->card['settings'];

        $x = self::MARGIN_X;
        $width = self::LEFT_COLUMN_WIDTH;
        $y = self::TOP;

        $this->logo($x, $y, $logo);
        $y += self::LOGO_HEIGHT + self::LOGO_GAP;

        $heading = 'Schrittmacher - Patientenausweis';
        $this->pdf->text(
            $x + ($width - PdfDocument::textWidth($heading, 'bold', self::SIZE_HEADING)) / 2,
            $y + 0.72 * self::SIZE_HEADING,
            $heading,
            'bold',
            self::SIZE_HEADING,
            self::INK,
        );
        $y += self::SIZE_HEADING * 1.2;

        $subtitle = '(Patient Identification Card)';
        $this->pdf->text(
            $x + ($width - PdfDocument::textWidth($subtitle, 'italic', self::SIZE_HEADING)) / 2,
            $y + 0.72 * self::SIZE_HEADING,
            $subtitle,
            'italic',
            self::SIZE_HEADING,
            self::INK,
        );
        $y += self::SIZE_HEADING * 1.85;

        $y = $this->sectionTitle($x, $y, $width, 'Patientendaten:');
        $y = $this->fieldRows($x, $y, $width, [
            ['Name', $this->orEmpty((string) $patient['patient_name'])],
            ['geboren am:', $this->orEmpty((string) $patient['date_of_birth_display'])],
            ['Straße:', $this->orEmpty((string) $patient['street'])],
            ['PLZ/Wohnort:', $this->orEmpty(trim((string) $patient['postal_code'] . ' ' . (string) $patient['city']))],
            ['Telefon:', $this->orEmpty((string) $patient['phone'])],
            ['Indikation:', $this->orEmpty((string) $patient['indication'])],
        ]);

        $y = $this->divider($x, $y, $width);
        $y = $this->sectionTitle($x, $y, $width, 'Notfallkontakt:');
        $y = $this->fieldRows($x, $y, $width, [
            ['Name:', $this->orEmpty((string) $contact['name'])],
            ['Telefon:', $this->orEmpty((string) $contact['phone'])],
        ]);

        $y = $this->divider($x, $y, $width);
        $y = $this->sectionTitle($x, $y, $width, 'Hausarzt:');
        $y = $this->fieldRows($x, $y, $width, [
            ['Name:', $this->orEmpty((string) $physician['name'])],
            ['Praxis-Adresse:', $this->orEmpty((string) $physician['practice'])],
            ['PLZ/Ort:', $this->orEmpty(trim((string) $physician['postal_code'] . ' ' . (string) $physician['city']))],
            ['Telefon:', $this->orEmpty((string) $physician['phone'])],
        ]);

        $y = $this->divider($x, $y, $width);
        $y = $this->sectionTitle($x, $y, $width, 'Betreuendes Nachsorgezentrum:', self::SIZE_SECTION, self::LINE_CENTER_TITLE);

        return $this->paragraph(
            $x,
            $y,
            $width,
            trim((string) $settings['center_name'] . "\n" . (string) $settings['center_address']),
            'regular',
            self::SIZE_FIELD,
            self::INK,
            self::LINE_CENTER,
        );
    }

    /**
     * Rechte Spalte der Vorlage: Implantate, Elektroden, Hinweise und Flugsicherheit.
     */
    private function implantColumn(): float
    {
        $device = $this->card['device'];
        $settings = $this->card['settings'];
        $leads = $this->card['leads'];

        $x = self::RIGHT_COLUMN_X;
        $width = self::RIGHT_COLUMN_WIDTH;
        $y = self::TOP;

        $y = $this->sectionTitle($x, $y, $width, 'Implantate:', self::SIZE_IMPLANT_TITLE);
        $y += 2.6;
        $y = $this->sectionTitle($x, $y, $width, 'Schrittmacher:');
        $y = $this->table($x, $y, $width, self::deviceColumns($width), [[
            $this->deviceCell(),
            $this->orEmpty((string) $device['implant_location']),
            $this->orEmpty((string) $device['implant_date_display']),
        ]]);

        $y += self::SECTION_GAP;
        $y = $this->sectionTitle($x, $y, $width, 'Elektroden:');
        if ($leads === []) {
            $y = $this->paragraph(
                $x,
                $y,
                $width,
                'Für diesen Bericht sind keine Elektrodendaten hinterlegt.',
                'italic',
                self::SIZE_NOTICE,
                self::MUTED,
            );
        } else {
            $rows = [];
            foreach ($leads as $lead) {
                $rows[] = [
                    $this->leadCell($lead),
                    $this->orEmpty((string) $lead['chamber_label']),
                    $this->orEmpty((string) $lead['implant_date_display']),
                ];
            }
            $y = $this->table($x, $y, $width, self::leadColumns($width), $rows);
        }

        $y += self::SECTION_GAP;
        $y = $this->sectionTitle($x, $y, $width, 'Hinweise:', self::SIZE_NOTICE_TITLE);
        $y = $this->paragraph($x, $y, $width, (string) $settings['notice_text'], 'regular', self::SIZE_NOTICE);

        $y += self::FLIGHT_GAP;
        $y = $this->sectionTitle($x, $y, $width, 'Achtung Flugsicherheit:', self::SIZE_NOTICE_TITLE);
        $y = $this->paragraph($x, $y, $width, (string) $settings['flight_notice_de'], 'regular', self::SIZE_NOTICE);

        $y += self::PAIRED_GAP;
        $y = $this->sectionTitle($x, $y, $width, 'Attention Airline Security:', self::SIZE_NOTICE_TITLE);

        return $this->paragraph($x, $y, $width, (string) $settings['flight_notice_en'], 'regular', self::SIZE_NOTICE);
    }

    /**
     * Abschlussblock am Fuss von Seite 1 wie in der Vorlage.
     */
    private function summaryBlock(float $y): float
    {
        $followUp = $this->card['follow_up'];
        $x = self::MARGIN_X;
        $width = self::CONTENT_WIDTH;

        $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.9);

        $y = $this->fieldRows($x, $y + 11.0, $width, [
            ['Sonstiges', $this->orEmpty((string) $followUp['report_label'])],
            ['Bemerkung', ''],
            ['Arzt', $this->orEmpty((string) $followUp['control_physician'])],
            ['Nächste Kontrolle in', $this->orEmpty((string) $followUp['next_control_display'])],
        ], self::SUMMARY_LABEL_WIDTH, self::SIZE_SUMMARY, self::LINE_SUMMARY);

        return $this->barcode($x, $y, (string) $this->card['patient']['patient_identifier']);
    }

    /**
     * Barcodezeile der Vorlage: Klartextkennung links, Code 39 rechts daneben.
     */
    private function barcode(float $x, float $y, string $value): float
    {
        $encoded = $this->code39($value);
        if ($encoded === '') {
            return $y;
        }
        $this->pdf->text($x, $y + 8.5, $value, 'regular', self::SIZE_BARCODE, self::INK);

        $modules = 0.0;
        foreach (mb_str_split($encoded) as $char) {
            foreach (str_split(self::CODE39[$char]) as $element) {
                $modules += $element === 'w' ? 2.5 : 1.0;
            }
        }
        $module = self::BARCODE_WIDTH / $modules;
        $cursor = self::BARCODE_X;
        $isBar = true;
        foreach (mb_str_split($encoded) as $char) {
            foreach (str_split(self::CODE39[$char]) as $element) {
                $elementWidth = ($element === 'w' ? 2.5 : 1.0) * $module;
                if ($isBar) {
                    $this->pdf->rect($cursor, $y, $elementWidth, self::BARCODE_HEIGHT, self::INK);
                }
                $cursor += $elementWidth;
                $isBar = !$isBar;
            }
            $cursor += $module * 1.5;
        }
        return $y + self::BARCODE_HEIGHT;
    }

    private function code39(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }
        $encoded = '*';
        foreach (mb_str_split($value) as $char) {
            if (!isset(self::CODE39[$char])) {
                return '';
            }
            $encoded .= $char;
        }
        return $encoded . '*';
    }

    /**
     * Spalten der Schrittmachertabelle (Vorlage: Modell / Impl.Ort / Impl.Datum).
     *
     * @return list<array{title: string, width: float}>
     */
    private static function deviceColumns(float $width): array
    {
        return [
            ['title' => 'Modell', 'width' => $width * 0.40],
            ['title' => 'Impl.Ort', 'width' => $width * 0.31],
            ['title' => 'Impl.Datum', 'width' => $width * 0.29],
        ];
    }

    /**
     * Spalten der Elektrodentabelle (Vorlage: Modell / Lokalisation / Impl.Datum).
     *
     * @return list<array{title: string, width: float}>
     */
    private static function leadColumns(float $width): array
    {
        return [
            ['title' => 'Modell', 'width' => $width * 0.40],
            ['title' => 'Lokalisation', 'width' => $width * 0.31],
            ['title' => 'Impl.Datum', 'width' => $width * 0.29],
        ];
    }

    /**
     * Modellspalte des Schrittmachers wie in der Vorlage: Hersteller, Modell, Modellnummer
     * und Seriennummer untereinander.
     */
    private function deviceCell(): string
    {
        $device = $this->card['device'];
        $lines = [];
        foreach (['manufacturer', 'model_name', 'model_number'] as $key) {
            $value = trim((string) ($device[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }
        $serial = trim((string) $device['serial_number']);
        if ($serial !== '') {
            $lines[] = 'SN: ' . $serial;
        }
        return $lines === [] ? self::EMPTY : implode("\n", $lines);
    }

    /**
     * Modellspalte einer Elektrode: Hersteller, Modellbezeichnung und Seriennummer.
     *
     * @param array<string, mixed> $lead
     */
    private function leadCell(array $lead): string
    {
        $lines = [];
        $manufacturer = trim((string) ($lead['manufacturer'] ?? ''));
        if ($manufacturer !== '') {
            $lines[] = $manufacturer;
        }
        $model = trim((string) ($lead['model_number'] ?? '') . ' ' . (string) ($lead['model_label'] ?? ''));
        if ($model !== '') {
            $lines[] = $model;
        }
        $serial = trim((string) ($lead['serial_number'] ?? ''));
        if ($serial !== '') {
            $lines[] = 'SN: ' . $serial;
        }
        return $lines === [] ? self::EMPTY : implode("\n", $lines);
    }

    // --------------------------------------------------------------------- Seite 2

    private function pageTwo(): void
    {
        $this->pdf->addPage();

        $patient = $this->card['patient'];
        $device = $this->card['device'];
        $x = self::MARGIN_X;
        $width = self::CONTENT_WIDTH;
        $y = self::TOP;

        $header = sprintf(
            'Patientenausweis · %s · Geburtsdatum %s · Gerät %s · Seriennummer %s',
            (string) $patient['patient_name'],
            (string) $patient['date_of_birth_display'],
            trim((string) $device['model_name'] . ' ' . (string) $device['model_number']),
            (string) $device['serial_number'],
        );
        $lineHeight = self::SIZE_TABLE * self::LINE_TEXT;
        $headerLines = $this->wrap($header, $width, 'regular', self::SIZE_TABLE);
        foreach ($headerLines as $index => $line) {
            $this->pdf->text(
                $x,
                $y + 0.72 * self::SIZE_TABLE + $index * $lineHeight,
                $line,
                'regular',
                self::SIZE_TABLE,
                self::MUTED,
            );
        }
        $y += count($headerLines) * $lineHeight + 6.0;
        $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.7);
        $y += 12.0;

        $y = $this->sectionTitle($x, $y, $width, 'Vergangene Nachsorgeuntersuchungen');
        $this->pdf->text(
            $x,
            $y + 0.72 * self::SIZE_NOTICE,
            'Previous follow-up examinations',
            'italic',
            self::SIZE_NOTICE,
            self::MUTED,
        );
        $y += 12.0;

        $history = $this->card['history'];
        if ($history === []) {
            $y = $this->noticeBox(
                $x,
                $y,
                $width,
                'Es sind keine früheren Nachsorgeuntersuchungen dieses Patienten gespeichert.',
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
            $y = $this->table($x, $y, $width, [
                ['title' => 'Datum', 'width' => 74.0],
                ['title' => 'Bericht', 'width' => 150.0],
                ['title' => 'Arzt', 'width' => 140.0],
                ['title' => 'Nachsorgezentrum', 'width' => $width - 74.0 - 150.0 - 140.0],
            ], $rows);
            $this->pdf->text(
                $x,
                $y + 0.72 * self::SIZE_NOTICE,
                'Die Angaben stammen aus den importierten Nachsorgeberichten und werden unverändert dargestellt.',
                'italic',
                self::SIZE_NOTICE,
                self::MUTED,
            );
            $y += 12.0;
        }

        $y = $this->sectionTitle($x, $y, $width, 'Hinweise zum Verlauf / Notes on the history');
        $y = $this->paragraph(
            $x,
            $y,
            $width,
            'Diese Seite dokumentiert die im System vorhandenen Nachsorgeuntersuchungen zum '
            . 'Zeitpunkt der Erstellung des Ausweises. Änderungen an Stammdaten oder später '
            . 'importierte Berichte verändern diesen Ausweis nicht.',
            'regular',
            self::SIZE_NOTICE,
        );
        if ($y > self::BOTTOM_LIMIT) {
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
            $this->pdf->line(self::MARGIN_X, $lineY, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $lineY, self::MUTED, 0.4);
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
            $this->pdf->text(
                PdfDocument::PAGE_WIDTH - self::MARGIN_X - $width,
                $lineY + 21,
                $pageLabel,
                'bold',
                8,
                self::INK,
            );
        }
    }

    /**
     * Logofeld oben links; ohne Logo erscheint ein Platzhalterrahmen in der Groesse der Vorlage.
     */
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
     * Abschnittsueberschrift ohne Linie; gibt die Oberkante des Folgeblocks zurueck.
     */
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
     * Beschriftung links, Wert rechts daneben; die Zeilenhoehe richtet sich nach dem laengeren Text.
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
            foreach ($labelLines as $index => $line) {
                $this->pdf->text(
                    $x,
                    $y + $index * $lineHeight + 0.72 * $fontSize,
                    $line,
                    'regular',
                    $fontSize,
                    self::INK,
                );
            }
            foreach ($valueLines as $index => $line) {
                $this->pdf->text(
                    $x + $labelWidth,
                    $y + $index * $lineHeight + 0.72 * $fontSize,
                    $line,
                    'bold',
                    $fontSize,
                    self::INK,
                );
            }
            $y += max(count($labelLines), count($valueLines)) * $lineHeight;
        }
        return $y;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function paragraph(
        float $x,
        float $y,
        float $width,
        string $text,
        string $font = 'regular',
        float $size = self::SIZE_NOTICE,
        array $color = self::INK,
        float $lineHeightRatio = self::LINE_TEXT,
    ): float {
        $text = trim($text) === '' ? self::EMPTY : $text;
        $lineHeight = $size * $lineHeightRatio;
        foreach ($this->wrap($text, $width, $font, $size) as $line) {
            $this->pdf->text($x, $y + 0.72 * $size, $line, $font, $size, $color);
            $y += $lineHeight;
        }
        return $y + 1.5;
    }

    /**
     * Hinweisblock in einem Rahmen (leere Ausweishistorie auf Seite 2).
     */
    private function noticeBox(float $x, float $y, float $width, string $text): float
    {
        $lines = $this->wrap($text, $width - 12.0, 'italic', self::SIZE_NOTICE);
        $lineHeight = self::SIZE_NOTICE * self::LINE_TEXT;
        $height = count($lines) * $lineHeight + 8.0;
        $this->pdf->rect($x, $y, $width, $height, null, self::RULE, 0.5);
        foreach ($lines as $index => $line) {
            $this->pdf->text(
                $x + 6.0,
                $y + 4.0 + $index * $lineHeight + 0.72 * self::SIZE_NOTICE,
                $line,
                'italic',
                self::SIZE_NOTICE,
                self::INK,
            );
        }
        return $y + $height + 8.0;
    }

    /**
     * Tabelle ohne Seitenumbruch (Seite 1 und 2 sind fest aufgeteilt).
     * Wie in der Vorlage: Linie unter der Kopfzeile und zwischen den Datenzeilen.
     *
     * @param list<array{title: string, width: float}> $columns
     * @param list<list<string>> $rows
     */
    private function table(
        float $x,
        float $y,
        float $width,
        array $columns,
        array $rows,
        float $fontSize = self::SIZE_TABLE,
    ): float {
        $lineHeight = $fontSize * self::LINE_TEXT;
        $cursor = $x;
        foreach ($columns as $column) {
            $this->pdf->text(
                $cursor + self::CELL_PAD,
                $y + self::CELL_PAD + 0.72 * $fontSize,
                $column['title'],
                'bold',
                $fontSize,
                self::INK,
            );
            $cursor += $column['width'];
        }
        $y += $lineHeight + 2 * self::CELL_PAD;
        $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.7);
        $y += 1.5;

        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $lines = $this->wrap(
                    (string) ($row[$columnIndex] ?? ''),
                    $column['width'] - 2 * self::CELL_PAD,
                    'regular',
                    $fontSize,
                );
                $cells[] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $cursor = $x;
            foreach ($columns as $columnIndex => $column) {
                foreach ($cells[$columnIndex] as $lineIndex => $line) {
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
            $y += $maxLines * $lineHeight + 2 * self::CELL_PAD;
            if ($index < $last) {
                $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.5);
                $y += 1.5;
            }
        }
        return $y + 3.0;
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
