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
 * Der Aufbau wird vollstaendig aus der Ausweisvorlage gelesen (PatientCardTemplate), die im
 * Snapshot des Ausweises eingefroren ist. Vorlagenaenderungen koennen ein bereits erzeugtes PDF
 * daher nicht veraendern; fehlt die eingefrorene Vorlage (Ausweise vor Migration 014), wird die
 * Standardvorlage verwendet, die den urspruenglich fest verdrahteten Aufbau abbildet.
 *
 * Seite 1:
 *   Kopfbereich (Zone "header"): Logo, Ueberschrift "Schrittmacher - Patientenausweis" mit
 *   "(Patient Identification Card)";
 *   linke Spalte:  Patientendaten, Notfallkontakt, Hausarzt, betreuendes Nachsorgezentrum;
 *   rechte Spalte: Implantate (Tabellen "Schrittmacher" und "Elektroden"), MRT-Tauglichkeit,
 *                  Hinweise und Flugsicherheit;
 *   unten:         Abschlussblock mit "Sonstiges", "Bemerkung", "Arzt",
 *                  "Naechste Kontrolle in" und dem Barcode der Patientenkennung.
 * Seite 2: Kopfzeile (Zone "header"), Messwerttabelle der aktuellen Untersuchung und der letzten
 *          sechs frueheren Untersuchungen (Werte aus config/patient_card_measurements.php) sowie
 *          die freien Textbausteine.
 *
 * Spaltenraster, Schriftgroessen und Zeilenabstaende sind aus der Vorlage abgeleitet.
 * Der Generator arbeitet ausschliesslich mit dem uebergebenen Snapshot, der darin eingefrorenen
 * Vorlage und dem uebergebenen Logo. Es werden keine medizinischen Bewertungen erzeugt; fehlende
 * Stammdaten erscheinen als "nicht angegeben", Zellen der Messwerttabelle bleiben leer.
 */
final class PatientCardPdfGenerator
{
    public const int SUPPORTED_CARD_VERSION = 2;

    /** Der Ausweis besteht aus genau zwei Seiten. */
    public const int PAGES = 2;

    /** Maximale Laenge der Stammdatentexte, damit Seite 1 sicher passt (Spiegel in der Stammdatenmaske). */
    public const int MAX_NOTICE_CHARS = 500;
    public const int MAX_FLIGHT_NOTICE_CHARS = 300;

    /** Maximale Laenge des Anzeigetextes der MRT-Tauglichkeit auf Seite 1 (Auswahlwert plus Zusatzangabe). */
    public const int MAX_MRT_CHARS = 160;

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

    /** Schriftgroessen der Messwerttabelle auf Seite 2. */
    private const float SIZE_GRID = 7.0;
    private const float SIZE_GRID_SECTION = 9.0;
    private const float SIZE_GRID_GROUP = 7.2;

    // Zeilenhoehen (Vielfaches der Schriftgroesse)
    private const float LINE_SECTION = 1.40;
    private const float LINE_FIELD = 1.49;
    private const float LINE_TEXT = 1.37;
    private const float LINE_SUMMARY = 1.55;
    private const float LINE_GRID = 1.30;

    /** Spaltenraster der Messwerttabelle: Kammerangabe, Beschriftung, danach je Untersuchung eine Spalte. */
    private const float GRID_CHAMBER_WIDTH = 17.0;
    private const float GRID_LABEL_WIDTH = 113.0;

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
    /** @var array<string, mixed> eingefrorene, gepruefte Ausweisvorlage */
    private array $template = [];
    /** @var array<string, string> Werte der Platzhalter des Ausweises */
    private array $values = [];

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
        $this->template = self::template($card);
        $this->values = self::values($card, $generatedAt);

        $this->pageOne($logo);
        $this->pageTwo();
        if ($this->pdf->pageCount() !== self::PAGES) {
            throw new RuntimeException('Der Patientenausweis muss aus genau zwei Seiten bestehen.');
        }
        $this->footers();

        return $this->pdf->output([
            'Title' => sprintf('Patientenausweis %s', (string) ($card['patient']['patient_name'] ?? '')),
            'Subject' => 'Patientenausweis / Patient Identification Card – automatisch erzeugt aus importierten Nachsorgeberichten',
            'Creator' => 'HSM2Med',
            'Producer' => 'HSM2Med PdfDocument',
        ], $generatedAt);
    }

    /**
     * Im Snapshot eingefrorene Vorlage; ohne eingefrorene Vorlage (Ausweise vor Migration 014)
     * wird die Standardvorlage verwendet.
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private static function template(array $card): array
    {
        $embedded = $card['template']['content'] ?? null;
        try {
            return PatientCardTemplate::normalize($embedded ?? PatientCardTemplate::default());
        } catch (PatientCardException) {
            throw new RuntimeException('Die im Ausweis eingefrorene Ausweisvorlage ist ungültig.');
        }
    }

    /**
     * Werte der Platzhalter der Vorlage, ausschliesslich aus dem Snapshot.
     *
     * @param array<string, mixed> $card
     * @return array<string, string>
     */
    private static function values(array $card, DateTimeImmutable $generatedAt): array
    {
        return PatientCardTemplate::values($card, $generatedAt);
    }

    /**
     * Fester Text eines Bausteins mit ersetzten Platzhaltern.
     *
     * @param array<string, mixed> $block
     */
    private function text(array $block, string $key): string
    {
        return PatientCardTemplate::fill(PatientCardTemplate::blockText($block, $key), $this->values);
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

        $y = ['left' => $this->headerZone($logo, self::MARGIN_X, self::TOP, self::LEFT_COLUMN_WIDTH), 'right' => self::TOP];
        $first = ['left' => true, 'right' => true];
        $summary = null;
        foreach (PatientCardTemplate::enabledBlocks($this->template) as $block) {
            $area = PatientCardTemplate::area((string) $block['type']);
            if ($area === 'page2') {
                continue;
            }
            if ($area === 'bottom') {
                $summary = $block;
                continue;
            }
            $x = $area === 'left' ? self::MARGIN_X : self::RIGHT_COLUMN_X;
            $width = $area === 'left' ? self::LEFT_COLUMN_WIDTH : self::RIGHT_COLUMN_WIDTH;
            if ($first[$area]) {
                $first[$area] = false;
            } elseif ($area === 'left') {
                $y[$area] = $this->divider($x, $y[$area], $width);
            } else {
                $y[$area] += self::SECTION_GAP;
            }
            $y[$area] = $this->blockOne($block, $x, $y[$area], $width);
        }

        $bottom = max($y['left'], $y['right']);
        if ($summary !== null) {
            $bottom = $this->summaryBlock($summary, $bottom + self::SUMMARY_GAP);
        }
        if ($bottom > self::BOTTOM_LIMIT) {
            throw new RuntimeException(
                'Die Stammdatentexte sind zu lang: Seite 1 des Patientenausweises wurde nicht vollstaendig bedruckt.',
            );
        }
    }

    /**
     * Kopfbereich (Zone "header") auf Seite 1: Logo, Ueberschrift und Unterzeile.
     */
    private function headerZone(?ImageData $logo, float $x, float $y, float $width): float
    {
        if (PatientCardTemplate::zoneOption($this->template, 'header', 'show_logo')) {
            $this->logo($x, $y, $logo);
            $y += self::LOGO_HEIGHT + self::LOGO_GAP;
        }
        if (!PatientCardTemplate::zoneOption($this->template, 'header', 'show_title')) {
            return $y;
        }
        $heading = PatientCardTemplate::fill(PatientCardTemplate::zoneText($this->template, 'header', 'title'), $this->values);
        if ($heading !== '') {
            $this->pdf->text(
                $x + ($width - PdfDocument::textWidth($heading, 'bold', self::SIZE_HEADING)) / 2,
                $y + 0.72 * self::SIZE_HEADING,
                $heading,
                'bold',
                self::SIZE_HEADING,
                self::INK,
            );
            $y += self::SIZE_HEADING * 1.2;
        }
        $subtitle = PatientCardTemplate::fill(PatientCardTemplate::zoneText($this->template, 'header', 'subtitle'), $this->values);
        if ($subtitle !== '') {
            $this->pdf->text(
                $x + ($width - PdfDocument::textWidth($subtitle, 'italic', self::SIZE_HEADING)) / 2,
                $y + 0.72 * self::SIZE_HEADING,
                $subtitle,
                'italic',
                self::SIZE_HEADING,
                self::INK,
            );
            $y += self::SIZE_HEADING * 1.85;
        }
        return $y;
    }

    /**
     * Baustein der Seite 1 an der Stelle, die seiner Lage in der Vorlage entspricht.
     *
     * @param array<string, mixed> $block
     */
    private function blockOne(array $block, float $x, float $y, float $width): float
    {
        return match ((string) $block['type']) {
            'patient_data' => $this->patientDataBlock($block, $x, $y, $width),
            'emergency_contact' => $this->emergencyContactBlock($block, $x, $y, $width),
            'physician' => $this->physicianBlock($block, $x, $y, $width),
            'center' => $this->centerBlock($block, $x, $y, $width),
            'implants' => $this->implantsBlock($block, $x, $y, $width),
            'mrt' => $this->mrtBlock($block, $x, $y, $width),
            'notice' => $this->noticeBlock($block, $x, $y, $width),
            default => $y,
        };
    }

    /**
     * Linke Spalte: Patientendaten.
     *
     * @param array<string, mixed> $block
     */
    private function patientDataBlock(array $block, float $x, float $y, float $width): float
    {
        $patient = $this->card['patient'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'));
        $rows = [
            [$this->text($block, 'label_name'), $this->orEmpty((string) $patient['patient_name'])],
            [$this->text($block, 'label_birth'), $this->orEmpty((string) $patient['date_of_birth_display'])],
            [$this->text($block, 'label_street'), $this->orEmpty((string) $patient['street'])],
            [$this->text($block, 'label_city'), $this->orEmpty(trim((string) $patient['postal_code'] . ' ' . (string) $patient['city']))],
            [$this->text($block, 'label_phone'), $this->orEmpty((string) $patient['phone'])],
        ];
        if (PatientCardTemplate::blockOption($block, 'show_indication')) {
            $rows[] = [$this->text($block, 'label_indication'), $this->orEmpty((string) $patient['indication'])];
        }
        return $this->fieldRows($x, $y, $width, $rows);
    }

    /**
     * Linke Spalte: Notfallkontakt.
     *
     * @param array<string, mixed> $block
     */
    private function emergencyContactBlock(array $block, float $x, float $y, float $width): float
    {
        $contact = $this->card['emergency_contact'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'));
        return $this->fieldRows($x, $y, $width, [
            [$this->text($block, 'label_name'), $this->orEmpty((string) $contact['name'])],
            [$this->text($block, 'label_phone'), $this->orEmpty((string) $contact['phone'])],
        ]);
    }

    /**
     * Linke Spalte: Hausarzt.
     *
     * @param array<string, mixed> $block
     */
    private function physicianBlock(array $block, float $x, float $y, float $width): float
    {
        $physician = $this->card['physician'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'));
        return $this->fieldRows($x, $y, $width, [
            [$this->text($block, 'label_name'), $this->orEmpty((string) $physician['name'])],
            [$this->text($block, 'label_practice'), $this->orEmpty((string) $physician['practice'])],
            [$this->text($block, 'label_city'), $this->orEmpty(trim((string) $physician['postal_code'] . ' ' . (string) $physician['city']))],
            [$this->text($block, 'label_phone'), $this->orEmpty((string) $physician['phone'])],
        ]);
    }

    /**
     * Linke Spalte: betreuendes Nachsorgezentrum.
     *
     * @param array<string, mixed> $block
     */
    private function centerBlock(array $block, float $x, float $y, float $width): float
    {
        $settings = $this->card['settings'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'), self::SIZE_SECTION, self::LINE_CENTER_TITLE);
        $body = trim((string) $settings['center_name'] . "\n" . (string) $settings['center_address']);
        $extra = trim($this->text($block, 'extra'));
        if ($extra !== '') {
            $body = ($body === '' ? '' : $body . "\n") . $extra;
        }
        return $this->paragraph($x, $y, $width, $body, 'regular', self::SIZE_FIELD, self::INK, self::LINE_CENTER);
    }

    /**
     * Rechte Spalte: Implantate mit Schrittmacher- und Elektrodentabelle.
     *
     * @param array<string, mixed> $block
     */
    private function implantsBlock(array $block, float $x, float $y, float $width): float
    {
        $device = $this->card['device'];
        $leads = $this->card['leads'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'), self::SIZE_IMPLANT_TITLE);
        $y += 2.6;
        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'device_title'));
        $y = $this->table($x, $y, $width, self::columns(
            $width,
            $this->text($block, 'col_model'),
            $this->text($block, 'col_location'),
            $this->text($block, 'col_date'),
        ), [[
            $this->deviceCell(),
            $this->orEmpty((string) $device['implant_location']),
            $this->orEmpty((string) $device['implant_date_display']),
        ]]);

        if (!PatientCardTemplate::blockOption($block, 'show_leads')) {
            return $y;
        }
        $y += self::SECTION_GAP;
        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'lead_title'));
        if ($leads === []) {
            return $this->paragraph($x, $y, $width, $this->text($block, 'lead_empty'), 'italic', self::SIZE_NOTICE, self::MUTED);
        }
        $rows = [];
        foreach ($leads as $lead) {
            $rows[] = [
                $this->leadCell($lead),
                $this->orEmpty((string) $lead['chamber_label']),
                $this->orEmpty((string) $lead['implant_date_display']),
            ];
        }
        return $this->table($x, $y, $width, self::columns(
            $width,
            $this->text($block, 'col_model'),
            $this->text($block, 'col_localization'),
            $this->text($block, 'col_date'),
        ), $rows);
    }

    /**
     * Rechte Spalte: MRT-Tauglichkeit.
     *
     * @param array<string, mixed> $block
     */
    private function mrtBlock(array $block, float $x, float $y, float $width): float
    {
        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'));
        return $this->paragraph($x, $y, $width, $this->mrtText($this->card['device']), 'bold', self::SIZE_FIELD, self::INK, self::LINE_FIELD);
    }

    /**
     * Rechte Spalte: Hinweise aus den Stammdaten und Hinweise zur Flugsicherheit.
     *
     * @param array<string, mixed> $block
     */
    private function noticeBlock(array $block, float $x, float $y, float $width): float
    {
        $settings = $this->card['settings'];

        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'title'), self::SIZE_NOTICE_TITLE);
        $y = $this->paragraph($x, $y, $width, (string) $settings['notice_text'], 'regular', self::SIZE_NOTICE);
        if (!PatientCardTemplate::blockOption($block, 'show_flight')) {
            return $y;
        }
        $y += self::FLIGHT_GAP;
        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'flight_title_de'), self::SIZE_NOTICE_TITLE);
        $y = $this->paragraph($x, $y, $width, (string) $settings['flight_notice_de'], 'regular', self::SIZE_NOTICE);

        $y += self::PAIRED_GAP;
        $y = $this->sectionTitle($x, $y, $width, $this->text($block, 'flight_title_en'), self::SIZE_NOTICE_TITLE);

        return $this->paragraph($x, $y, $width, (string) $settings['flight_notice_en'], 'regular', self::SIZE_NOTICE);
    }

    /**
     * Abschlussblock am Fuss von Seite 1 wie in der Vorlage.
     */
    /**
     * Abschlussblock am Fuss von Seite 1 wie in der Vorlage.
     *
     * @param array<string, mixed> $block
     */
    private function summaryBlock(array $block, float $y): float
    {
        $followUp = $this->card['follow_up'];
        $x = self::MARGIN_X;
        $width = self::CONTENT_WIDTH;

        $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.9);

        $y = $this->fieldRows($x, $y + 11.0, $width, [
            [$this->text($block, 'label_other'), $this->orEmpty((string) $followUp['report_label'])],
            [$this->text($block, 'label_remark'), ''],
            [$this->text($block, 'label_physician'), $this->orEmpty((string) $followUp['control_physician'])],
            [$this->text($block, 'label_next_control'), $this->orEmpty((string) $followUp['next_control_display'])],
        ], self::SUMMARY_LABEL_WIDTH, self::SIZE_SUMMARY, self::LINE_SUMMARY);

        if (!PatientCardTemplate::blockOption($block, 'show_barcode')) {
            return $y;
        }
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
     * Drei Spalten im Breitenverhaeltnis der Vorlage (Modell / Ort / Datum).
     *
     * @return list<array{title: string, width: float}>
     */
    private static function columns(float $width, string $model, string $location, string $date): array
    {
        return [
            ['title' => $model, 'width' => $width * 0.40],
            ['title' => $location, 'width' => $width * 0.31],
            ['title' => $date, 'width' => $width * 0.29],
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

    /**
     * Seite 2: Kopfzeile, Messwerttabelle der aktuellen und der letzten frueheren Untersuchungen
     * sowie die freien Textbausteine der Vorlage.
     *
     * Die Messwerte werden ausschliesslich aus dem Snapshot gelesen (PatientCardService::snapshot()
     * Schluessel "measurements"); das Layout kennt die Messwertvorlage selbst nicht. Zellen ohne
     * Wert bleiben leer, es werden keine Werte erfunden.
     */
    private function pageTwo(): void
    {
        $this->pdf->addPage();

        $x = self::MARGIN_X;
        $width = self::CONTENT_WIDTH;
        $y = $this->continuationZone($x, self::TOP, $width);

        $blocks = [];
        foreach (PatientCardTemplate::enabledBlocks($this->template) as $block) {
            if (PatientCardTemplate::area((string) $block['type']) === 'page2') {
                $blocks[] = $block;
            }
        }

        $reserved = 0.0;
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $reserved += $this->textBlockHeight($block, $width);
            }
        }

        $measurements = null;
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'measurements') {
                $measurements = $block;
                break;
            }
        }

        if ($measurements !== null) {
            $notes = PatientCardTemplate::blockText($measurements, 'notes');
            $notesHeading = PatientCardTemplate::blockText($measurements, 'notes_heading');
            $showNotes = PatientCardTemplate::blockOption($measurements, 'show_notes');
            $notesHeight = $showNotes
                ? count($this->wrap($notes, $width, 'regular', self::SIZE_NOTICE)) * self::SIZE_NOTICE * self::LINE_TEXT
                    + self::SIZE_SECTION * self::LINE_SECTION + 9.5
                : 0.0;
            $y = $this->measurementGrid(
                $x,
                $y,
                $width,
                self::BOTTOM_LIMIT - $y - $notesHeight - $reserved,
                PatientCardTemplate::blockText($measurements, 'current_label'),
                PatientCardTemplate::blockText($measurements, 'empty'),
            );
            if ($showNotes) {
                $y = $this->sectionTitle($x, $y + 8.0, $width, $notesHeading);
                $y = $this->paragraph($x, $y, $width, $notes, 'regular', self::SIZE_NOTICE);
            }
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'text') {
                continue;
            }
            $heading = trim($this->text($block, 'heading'));
            $text = trim($this->text($block, 'text'));
            if ($heading === '' && $text === '') {
                continue;
            }
            $y += 8.0;
            if ($heading !== '') {
                $y = $this->sectionTitle($x, $y, $width, $heading);
            }
            $y = $this->paragraph($x, $y, $width, $text, 'regular', self::SIZE_NOTICE);
        }

        if ($y > self::BOTTOM_LIMIT) {
            throw new RuntimeException('Seite 2 des Patientenausweises wurde nicht vollstaendig bedruckt.');
        }
    }

    /**
     * Kopfzeile ab Seite 2 (Zone "header", Text "continuation").
     */
    private function continuationZone(float $x, float $y, float $width): float
    {
        $header = PatientCardTemplate::fill(PatientCardTemplate::zoneText($this->template, 'header', 'continuation'), $this->values);
        if (trim($header) === '') {
            return $y;
        }
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
        return $y + 12.0;
    }

    /**
     * Hoehe eines freien Textbausteins unterhalb der Messwerttabelle.
     *
     * @param array<string, mixed> $block
     */
    private function textBlockHeight(array $block, float $width): float
    {
        $heading = trim($this->text($block, 'heading'));
        $text = trim($this->text($block, 'text'));
        if ($heading === '' && $text === '') {
            return 0.0;
        }
        $height = 8.0;
        if ($heading !== '') {
            $height += self::SIZE_SECTION * self::LINE_SECTION;
        }
        return $height + count($this->wrap($text, $width, 'regular', self::SIZE_NOTICE)) * self::SIZE_NOTICE * self::LINE_TEXT + 1.5;
    }

    /**
     * Messwerttabelle: Beschriftungsspalte, Kammerangabe und je eine Spalte pro Untersuchung.
     * Die Wertespalten verteilen sich gleichmaessig auf die vorhandenen Untersuchungen.
     *
     * Reicht der Platz fuer zweizeilige Zellen nicht aus, werden die Zellen einzeilig gekuerzt,
     * damit Seite 2 in jedem Fall vollstaendig bedruckt wird.
     *
     * @param float $available Hoehe, die der Tabelle bis zum Fuss der Seite zur Verfuegung steht
     */
    private function measurementGrid(float $x, float $y, float $width, float $available, string $currentLabel, string $emptyText): float
    {
        $measurements = is_array($this->card['measurements'] ?? null) ? $this->card['measurements'] : [];
        $columns = is_array($measurements['columns'] ?? null) ? array_values($measurements['columns']) : [];
        $sections = is_array($measurements['sections'] ?? null) ? array_values($measurements['sections']) : [];
        if ($columns === [] || $sections === []) {
            return $this->noticeBox($x, $y, $width, $emptyText);
        }

        $lineHeight = self::SIZE_GRID * self::LINE_GRID;
        $labelX = $x + self::GRID_CHAMBER_WIDTH;
        $valueX = $labelX + self::GRID_LABEL_WIDTH;
        $valueWidth = ($x + $width - $valueX) / count($columns);

        // Spaltenkoepfe: Datum der jeweiligen Untersuchung, die aktuelle Untersuchung zusaetzlich benannt.
        $header = [];
        foreach ($columns as $column) {
            $lines = $this->gridCell(
                (string) ($column['date_display'] ?? ''),
                $valueWidth,
                'bold',
                self::SIZE_GRID,
                2,
            );
            if (($column['current'] ?? false) === true) {
                foreach ($this->gridCell($currentLabel, $valueWidth, 'italic', self::SIZE_GRID, 2) as $line) {
                    $lines[] = $line;
                }
            }
            $header[] = $lines;
        }
        $headerLines = 0;
        foreach ($header as $lines) {
            $headerLines = max($headerLines, count($lines));
        }
        $headerHeight = $headerLines * $lineHeight + 2 * self::CELL_PAD + 2.0;

        $elements = [];
        $height = 0.0;
        foreach ([2, 1] as $maxLines) {
            $elements = $this->gridElements($columns, $sections, $valueWidth, $maxLines);
            $height = $this->gridHeight($elements, $lineHeight);
            if ($headerHeight + $height <= $available) {
                break;
            }
        }

        $cursor = $valueX;
        foreach ($header as $lines) {
            foreach ($lines as $index => $line) {
                $font = $index === 0 ? 'bold' : 'italic';
                $this->pdf->text(
                    $cursor + ($valueWidth - PdfDocument::textWidth($line, $font, self::SIZE_GRID)) / 2,
                    $y + self::CELL_PAD + $index * $lineHeight + 0.72 * self::SIZE_GRID,
                    $line,
                    $font,
                    self::SIZE_GRID,
                    $index === 0 ? self::INK : self::MUTED,
                );
            }
            $cursor += $valueWidth;
        }
        $y += $headerLines * $lineHeight + 2 * self::CELL_PAD;
        $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.7);
        $y += 2.0;

        $first = true;
        foreach ($elements as $element) {
            if ($element['kind'] === 'section') {
                $y += 2.0;
                $y = $this->sectionTitle($x, $y, $width, (string) $element['label'], self::SIZE_GRID_SECTION);
                continue;
            }
            if ($element['kind'] === 'group') {
                $this->pdf->text(
                    $x,
                    $y + 0.72 * self::SIZE_GRID_GROUP,
                    (string) $element['label'],
                    'bold',
                    self::SIZE_GRID_GROUP,
                    self::MUTED,
                );
                $y += self::SIZE_GRID_GROUP * self::LINE_GRID;
                continue;
            }

            if (!$first) {
                $this->pdf->line($x, $y, $x + $width, $y, self::RULE, 0.25);
                $y += 1.5;
            }
            $first = false;

            $chamber = (string) $element['chamber'];
            if ($chamber !== '') {
                $this->pdf->text(
                    $x + self::CELL_PAD,
                    $y + self::CELL_PAD + 0.72 * self::SIZE_GRID,
                    $chamber,
                    'bold',
                    self::SIZE_GRID,
                    self::INK,
                );
            }
            foreach ($element['label'] as $index => $line) {
                $this->pdf->text(
                    $labelX + self::CELL_PAD,
                    $y + self::CELL_PAD + $index * $lineHeight + 0.72 * self::SIZE_GRID,
                    $line,
                    'regular',
                    self::SIZE_GRID,
                    self::INK,
                );
            }
            $cursor = $valueX;
            foreach ($element['cells'] as $lines) {
                foreach ($lines as $index => $line) {
                    $this->pdf->text(
                        $cursor + ($valueWidth - PdfDocument::textWidth($line, 'regular', self::SIZE_GRID)) / 2,
                        $y + self::CELL_PAD + $index * $lineHeight + 0.72 * self::SIZE_GRID,
                        $line,
                        'regular',
                        self::SIZE_GRID,
                        self::INK,
                    );
                }
                $cursor += $valueWidth;
            }
            $y += count($element['label']) * $lineHeight + 2 * self::CELL_PAD;
        }
        return $y + 4.0;
    }

    /**
     * Abschnitte, Gruppen und Zeilen der Messwerttabelle mit bereits umbrochenen Zellinhalten.
     *
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $sections
     * @return list<array{kind: string, label: mixed, chamber?: string, cells?: list<list<string>>}>
     */
    private function gridElements(array $columns, array $sections, float $valueWidth, int $maxLines): array
    {
        $elements = [];
        foreach ($sections as $section) {
            $elements[] = ['kind' => 'section', 'label' => (string) $section['label']];
            foreach ((array) $section['groups'] as $group) {
                $elements[] = ['kind' => 'group', 'label' => (string) $group['label']];
                foreach ((array) $group['rows'] as $row) {
                    $label = $this->gridCell(
                        (string) $row['label'],
                        self::GRID_LABEL_WIDTH - 2 * self::CELL_PAD,
                        'regular',
                        self::SIZE_GRID,
                        $maxLines,
                    );
                    $cells = [];
                    $lines = count($label);
                    foreach ($columns as $index => $column) {
                        $cell = $this->gridCell(
                            (string) ($row['values'][$index] ?? ''),
                            $valueWidth - 2 * self::CELL_PAD,
                            'regular',
                            self::SIZE_GRID,
                            $maxLines,
                        );
                        $cells[] = $cell;
                        $lines = max($lines, count($cell));
                    }
                    // Alle Zellen einer Zeile wachsen auf die Zeilenhoehe der hoechsten Zelle.
                    $label = array_pad($label, $lines, '');
                    foreach ($cells as $index => $cell) {
                        $cells[$index] = array_pad($cell, $lines, '');
                    }
                    $elements[] = [
                        'kind' => 'row',
                        'label' => $label,
                        'chamber' => (string) ($row['chamber'] ?? ''),
                        'cells' => $cells,
                    ];
                }
            }
        }
        return $elements;
    }

    /**
     * @param list<array{kind: string, label: mixed, chamber?: string, cells?: list<list<string>>}> $elements
     */
    private function gridHeight(array $elements, float $lineHeight): float
    {
        $height = 0.0;
        $first = true;
        foreach ($elements as $element) {
            if ($element['kind'] === 'section') {
                $height += 2.0 + self::SIZE_GRID_SECTION * self::LINE_SECTION;
                continue;
            }
            if ($element['kind'] === 'group') {
                $height += self::SIZE_GRID_GROUP * self::LINE_GRID;
                continue;
            }
            if (!$first) {
                $height += 1.5;
            }
            $first = false;
            $height += count($element['label']) * $lineHeight + 2 * self::CELL_PAD;
        }
        return $height;
    }

    /**
     * Zellinhalt der Messwerttabelle: auf die Spaltenbreite umgebrochen und auf die angegebene
     * Zeilenzahl begrenzt, damit das Raster nicht aus der Seite laeuft.
     *
     * @return list<string>
     */
    private function gridCell(string $text, float $width, string $font, float $size, int $maxLines): array
    {
        $lines = $this->wrap($text, max($width, 1.0), $font, $size);
        if (count($lines) <= $maxLines) {
            return $lines;
        }
        $lines = array_slice($lines, 0, $maxLines);
        $last = array_pop($lines) ?? '';
        while ($last !== '' && PdfDocument::textWidth($last . '...', $font, $size) > $width) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[] = $last . '...';
        return $lines;
    }

    // --------------------------------------------------------------------- Bausteine

    /**
     * Fusszeile beider Seiten (Zone "footer").
     */
    private function footers(): void
    {
        $total = $this->pdf->pageCount();
        $showDisclaimer = PatientCardTemplate::zoneOption($this->template, 'footer', 'show_disclaimer');
        $showMeta = PatientCardTemplate::zoneOption($this->template, 'footer', 'show_meta');
        $disclaimer = PatientCardTemplate::fill(PatientCardTemplate::zoneText($this->template, 'footer', 'disclaimer'), $this->values);
        $meta = PatientCardTemplate::fill(PatientCardTemplate::zoneText($this->template, 'footer', 'meta'), $this->values);
        $label = PatientCardTemplate::zoneText($this->template, 'footer', 'page_label');
        for ($page = 0; $page < $total; $page++) {
            $this->pdf->setPage($page);
            $lineY = PdfDocument::PAGE_HEIGHT - 46;
            $this->pdf->line(self::MARGIN_X, $lineY, PdfDocument::PAGE_WIDTH - self::MARGIN_X, $lineY, self::MUTED, 0.4);
            if ($showDisclaimer && $disclaimer !== '') {
                $this->pdf->text(
                    self::MARGIN_X,
                    $lineY + 11,
                    $disclaimer,
                    'regular',
                    7,
                    self::MUTED,
                );
            }
            if ($showMeta && $meta !== '') {
                $this->pdf->text(
                    self::MARGIN_X,
                    $lineY + 21,
                    $meta,
                    'regular',
                    7,
                    self::MUTED,
                );
            }
            $pageLabel = PatientCardTemplate::fill($label, $this->values + ['page' => $page + 1, 'pages' => $total]);
            if ($pageLabel === '') {
                continue;
            }
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
     * Anzeigetext der MRT-Tauglichkeit: Auswahlwert und optionale Zusatzangabe in Klammern.
     * Der Text wird begrenzt, damit Seite 1 nicht ueberlaeuft (Ausweis bleibt bei zwei Seiten).
     *
     * @param array<string, mixed> $device
     */
    private function mrtText(array $device): string
    {
        $value = trim((string) ($device['mrt_compatibility'] ?? ''));
        $note = trim((string) ($device['mrt_compatibility_note'] ?? ''));
        if ($value === '' && $note === '') {
            return self::EMPTY;
        }
        if ($note === '') {
            $text = $value;
        } elseif ($value === '') {
            $text = $note;
        } else {
            $text = sprintf('%s (%s)', $value, $note);
        }
        if (mb_strlen($text) > self::MAX_MRT_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_MRT_CHARS - 3)) . '...';
        }
        return $text;
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
