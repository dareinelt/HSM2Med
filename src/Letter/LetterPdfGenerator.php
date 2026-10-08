<?php

declare(strict_types=1);

namespace App\Letter;

use App\Report\Pdf\ImageData;
use App\Report\Pdf\PdfDocument;
use App\Support\DateInput;
use DateTimeImmutable;
use RuntimeException;

/**
 * Erzeugt das PDF eines Briefes zur Schrittmacher-/ICD-Abfrage nach DIN 5008 (Form B).
 *
 * Seitenaufbau (Masse in mm ab der linken oberen Blattecke):
 *   * Briefkopf          0 bis 45 – Nachsorgezentrum links, Logo rechts
 *   * Anschriftfeld      x 20 (Text ab 25), y 45, 85 × 45 – Rücksendeangabe und Vermerk in der
 *                        Zusatz- und Vermerkzone (17,7), Empfaenger in der Anschriftzone (27,3)
 *   * Informationsblock  x 125, y 50, Breite 75 – Bezugszeichen und Datum (TT.MM.JJJJ)
 *   * Brieftext          ab ca. 98,5 – linker Rand 25, rechter Rand 20
 *   * Falzmarken 105 und 210, Lochmarke 148,5 am linken Blattrand
 *   * Seitenzahl „Seite x von y“ in der Fusszeile, Folgeseiten mit Kopfzeile
 *
 * Reihenfolge und feste Texte der Bausteine stammen aus der im Snapshot eingefrorenen
 * Briefvorlage (siehe LetterTemplate). Snapshots der Brief-Fassung 1 (vor Einfuehrung der
 * Vorlagen) werden unveraendert mit LegacyLetterPdfGenerator erzeugt.
 *
 * Der Generator liest ausschliesslich den Snapshot; das PDF ist damit ohne Datenbank
 * reproduzierbar. Fehlende Angaben erscheinen mit dem Text der Vorlage ("nicht angegeben").
 */
final class LetterPdfGenerator
{
    /** Fassung des Brief-Snapshots, die dieser Generator erzeugt. */
    public const int SUPPORTED_LETTER_VERSION = 2;

    /** Aeltere Snapshot-Fassungen, die ueber den damaligen Aufbau reproduziert werden. */
    public const array LEGACY_LETTER_VERSIONS = [1];

    public const float MM = 72.0 / 25.4;

    private const float LEFT = 25.0 * self::MM;
    private const float RIGHT = PdfDocument::PAGE_WIDTH - 20.0 * self::MM;
    private const float CONTENT_WIDTH = self::RIGHT - self::LEFT;

    private const float HEAD_TOP = 10.0 * self::MM;
    private const float HEAD_BOTTOM = 43.0 * self::MM;
    private const float LOGO_WIDTH = 42.0 * self::MM;
    private const float LOGO_HEIGHT = 27.0 * self::MM;

    private const float ADDRESS_TOP = 45.0 * self::MM;
    private const float ADDRESS_ZONE_TOP = 62.7 * self::MM;
    private const float ADDRESS_BOTTOM = 90.0 * self::MM;
    private const float ADDRESS_WIDTH = 80.0 * self::MM;
    private const int ADDRESS_MAX_LINES = 6;

    private const float INFO_X = 125.0 * self::MM;
    private const float INFO_TOP = 50.0 * self::MM;
    private const float INFO_WIDTH = 75.0 * self::MM;
    private const float INFO_LABEL_WIDTH = 25.5 * self::MM;

    private const float BODY_TOP = 98.46 * self::MM;
    private const float NEXT_TOP = 25.0 * self::MM;
    private const float BOTTOM_LIMIT = 270.0 * self::MM;
    private const float FOOTER_RULE = 274.0 * self::MM;

    private const array FOLD_MARKS = [105.0 * self::MM, 210.0 * self::MM];
    private const float HOLE_MARK = 148.5 * self::MM;

    private const float SIZE_SUBJECT = 11.0;
    private const float SIZE_SECTION = 10.5;
    private const float SIZE_TEXT = 10.0;
    private const float SIZE_FIELD = 9.5;
    private const float SIZE_TABLE = 8.5;
    private const float SIZE_SMALL = 7.5;
    private const float SIZE_RETURN = 7.0;
    private const float SIZE_INFO = 7.8;

    private const float LINE = 1.3;
    private const float CELL_PAD = 0.5;
    private const float LABEL_WIDTH = 35.0 * self::MM;
    private const float APPENDIX_LABEL_WIDTH = 95.0 * self::MM;

    private const array INK = [0.0, 0.0, 0.0];
    private const array MUTED = [0.40, 0.40, 0.40];
    private const array RULE = [0.0, 0.0, 0.0];
    private const array MARK = [0.55, 0.55, 0.55];
    private const array SECTION_FILL = [0.90, 0.90, 0.90];

    /** Spaltenbreiten der Vormedikation (Anteile der Inhaltsbreite). */
    private const array PREMED_SHARES = [
        'col_substance' => 0.29,
        'col_dose' => 0.13,
        'col_schedule' => 0.16,
        'col_reason' => 0.22,
        'col_period' => 0.20,
    ];

    private PdfDocument $pdf;

    /** @var array<string, mixed> */
    private array $letter = [];

    /** @var array<string, mixed> */
    private array $template = [];

    /** @var array<string, string> */
    private array $values = [];

    private string $empty = 'nicht angegeben';

    public function __construct(private readonly bool $compress = true)
    {
    }

    /**
     * @param array<string, mixed> $letter Brief-Snapshot (siehe LetterService::snapshot())
     */
    public function generate(array $letter, ?ImageData $logo, DateTimeImmutable $generatedAt): string
    {
        $version = (int) ($letter['letter_version'] ?? 0);
        if (in_array($version, self::LEGACY_LETTER_VERSIONS, true)) {
            return (new LegacyLetterPdfGenerator($this->compress))->generate($letter, $logo, $generatedAt);
        }
        if ($version !== self::SUPPORTED_LETTER_VERSION) {
            throw new RuntimeException(sprintf(
                'Brief-Fassung %s wird von diesem PDF-Generator nicht unterstuetzt.',
                (string) ($letter['letter_version'] ?? '?'),
            ));
        }
        try {
            $this->template = LetterTemplate::normalize($letter['template']['content'] ?? null);
        } catch (LetterException) {
            throw new RuntimeException('Die im Brief eingefrorene Vorlage ist ungueltig.');
        }
        $this->pdf = new PdfDocument($this->compress);
        $this->letter = $letter;
        $this->values = self::placeholderValues($letter);
        $this->empty = $this->zoneText('general', 'empty');
        if (trim($this->empty) === '') {
            $this->empty = 'nicht angegeben';
        }

        $this->pdf->addPage();
        $this->letterhead($logo);
        $this->addressField();
        $infoBottom = $this->infoBlock();
        $this->body(max(self::BODY_TOP, $infoBottom + self::SIZE_TEXT * self::LINE));
        $this->appendix();
        $this->pageFurniture($generatedAt);

        $subject = $this->blockText('subject', 'title');
        return $this->pdf->output([
            'Title' => sprintf('%s – %s', $subject === '' ? 'Brief' : $subject, $this->patientName()),
            'Subject' => 'Automatisch erzeugter Brief zur Schrittmacher-/ICD-Abfrage – keine medizinische Bewertung',
            'Creator' => 'HSM2Med',
            'Producer' => 'HSM2Med PdfDocument',
        ], $generatedAt);
    }

    public static function filename(array $letter): string
    {
        return LegacyLetterPdfGenerator::filename($letter);
    }

    /**
     * Seitenzahl eines erzeugten Briefes (der Brief ist nicht auf zwei Seiten begrenzt).
     */
    public static function pageCount(string $pdf): int
    {
        return LegacyLetterPdfGenerator::pageCount($pdf);
    }

    /**
     * Werte der Platzhalter aus dem Snapshot.
     *
     * @param array<string, mixed> $letter
     * @return array<string, string>
     */
    public static function placeholderValues(array $letter): array
    {
        $patient = (array) ($letter['patient'] ?? []);
        $master = (array) ($letter['master'] ?? []);
        $addressLines = array_values(array_filter(array_map(
            'trim',
            explode("\n", str_replace(["\r\n", "\r"], "\n", (string) ($master['center_address'] ?? ''))),
        ), static fn (string $line): bool => $line !== ''));
        $date = static function (string $value): string {
            $value = trim($value);
            return $value === '' ? '' : DateInput::format(substr($value, 0, 10));
        };
        return [
            'center_name' => trim((string) ($master['center_name'] ?? '')),
            'center_address_line' => implode(' · ', $addressLines),
            'salutation' => self::salutationText($letter),
            'patient_name' => trim((string) ($patient['patient_name'] ?? '')),
            'first_name' => trim((string) ($patient['first_name'] ?? '')),
            'last_name' => trim((string) ($patient['last_name'] ?? '')),
            'date_of_birth' => $date((string) ($patient['date_of_birth'] ?? '')),
            'patient_identifier' => trim((string) ($patient['patient_identifier'] ?? '')),
            'document_number' => trim((string) ($letter['document']['document_number'] ?? '')),
            'letter_date' => $date((string) ($letter['document']['letter_date'] ?? '')),
            'sequence_no' => (string) ($letter['sequence_no'] ?? ''),
        ];
    }

    /**
     * Anrede des Briefes aus dem Snapshot (Empfaenger). Briefe ohne Empfaenger – vor Migration
     * 008 oder ohne Auswahl im Assistenten – erhalten die unpersoenliche Anrede.
     *
     * @param array<string, mixed> $letter
     */
    private static function salutationText(array $letter): string
    {
        $recipient = $letter['recipient'] ?? null;
        if (!is_array($recipient)) {
            return LetterSalutation::FALLBACK;
        }
        $frozen = trim((string) ($recipient['salutation'] ?? ''));
        if ($frozen !== '') {
            return $frozen;
        }
        $patient = (array) ($letter['patient'] ?? []);
        $type = (string) ($recipient['type'] ?? '');
        return LetterSalutation::text(
            LetterRecipient::isType($type) ? $type : LetterRecipient::PATIENT,
            (string) ($recipient['salutation_value'] ?? ''),
            (string) ($patient['last_name'] ?? ''),
            (string) ($patient['first_name'] ?? ''),
        );
    }

    // --------------------------------------------------------------------- Feste Zonen (Seite 1)

    /**
     * Briefkopf: Nachsorgezentrum links, Logo rechts, innerhalb der 45 mm hohen Kopfzone.
     */
    private function letterhead(?ImageData $logo): void
    {
        $showLogo = $this->zoneOption('letterhead', 'show_logo');
        $textWidth = $showLogo ? self::CONTENT_WIDTH - self::LOGO_WIDTH - 8.0 * self::MM : self::CONTENT_WIDTH;
        if ($showLogo) {
            $this->logo(self::RIGHT - self::LOGO_WIDTH, self::HEAD_TOP, $logo);
        }
        $y = self::HEAD_TOP;
        $center = trim((string) ($this->letter['master']['center_name'] ?? ''));
        $y = $this->boundedLines(self::LEFT, $y, $textWidth, $center === '' ? $this->empty : $center, 'bold', 12.0, self::INK, self::HEAD_BOTTOM);
        $address = trim((string) ($this->letter['master']['center_address'] ?? ''));
        if ($address !== '') {
            $y = $this->boundedLines(self::LEFT, $y + 1.0, $textWidth, $address, 'regular', 8.5, self::INK, self::HEAD_BOTTOM);
        }
        $extra = $this->zoneText('letterhead', 'extra');
        if ($extra !== '') {
            $this->boundedLines(self::LEFT, $y + 2.0, $textWidth, $extra, 'regular', 8.5, self::MUTED, self::HEAD_BOTTOM);
        }
    }

    /**
     * Anschriftfeld nach DIN 5008 Form B: Zusatz- und Vermerkzone (Ruecksendeangabe, Vermerk)
     * und Anschriftzone mit hoechstens sechs Zeilen.
     */
    private function addressField(): void
    {
        if ($this->zoneOption('return_address', 'show')) {
            $return = $this->zoneText('return_address', 'text');
            if ($return !== '') {
                $line = $this->wrap($return, self::ADDRESS_WIDTH, 'regular', self::SIZE_RETURN)[0] ?? '';
                $y = self::ADDRESS_TOP + 3.0 * self::MM;
                $this->pdf->text(self::LEFT, $y, $line, 'regular', self::SIZE_RETURN, self::MUTED);
                $this->pdf->line(self::LEFT, $y + 1.6, self::LEFT + min(self::ADDRESS_WIDTH, PdfDocument::textWidth($line, 'regular', self::SIZE_RETURN)), $y + 1.6, self::MUTED, 0.3);
            }
        }
        $remark = $this->zoneText('recipient', 'remark');
        if ($remark !== '') {
            $this->pdf->text(self::LEFT, self::ADDRESS_ZONE_TOP - 1.5 * self::MM, $remark, 'bold', 8.5, self::INK);
        }

        $lines = [];
        foreach ($this->wrap($this->recipientText(), self::ADDRESS_WIDTH, 'regular', self::SIZE_TEXT) as $line) {
            if (trim($line) !== '') {
                $lines[] = $line;
            }
        }
        $lines = array_slice($lines, 0, self::ADDRESS_MAX_LINES);
        $lineHeight = (self::ADDRESS_BOTTOM - self::ADDRESS_ZONE_TOP) / self::ADDRESS_MAX_LINES;
        foreach ($lines as $index => $line) {
            $this->pdf->text(
                self::LEFT,
                self::ADDRESS_ZONE_TOP + $index * $lineHeight + 0.8 * self::SIZE_TEXT,
                $line,
                'regular',
                self::SIZE_TEXT,
                self::INK,
            );
        }
    }

    /**
     * Anschrift: der im Assistenten gewaehlte Empfaenger (im Snapshot eingefroren). Briefe ohne
     * Empfaenger (vor Migration 008 oder Vorschau) verwenden die Einstellung der Vorlage.
     */
    private function recipientText(): string
    {
        $recipient = $this->letter['recipient'] ?? null;
        if (is_array($recipient)) {
            $lines = array_values(array_filter(
                array_map(static fn (mixed $line): string => trim((string) $line), (array) ($recipient['lines'] ?? [])),
                static fn (string $line): bool => $line !== '',
            ));
            if ($lines !== []) {
                return implode("\n", $lines);
            }
        }
        if (($this->template['zones']['recipient']['options']['source'] ?? 'text') === 'patient') {
            $patient = (array) ($this->letter['patient'] ?? []);
            $address = (array) ($patient['address'] ?? []);
            $first = trim((string) ($patient['first_name'] ?? ''));
            $last = trim((string) ($patient['last_name'] ?? ''));
            $name = trim($first . ' ' . $last);
            $lines = [
                $name === '' ? $this->patientName() : $name,
                trim((string) ($address['street'] ?? '')),
                trim(trim((string) ($address['postal_code'] ?? '')) . ' ' . trim((string) ($address['city'] ?? ''))),
            ];
            return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
        }
        return $this->zoneText('recipient', 'text');
    }

    /**
     * Informationsblock rechts neben dem Anschriftfeld. Zeilen mit leerer Beschriftung oder
     * ohne Häkchen in den Vorlagenoptionen entfallen.
     */
    private function infoBlock(): float
    {
        $master = (array) ($this->letter['master'] ?? []);
        $rows = [
            ['label_reference', 'show_reference', $this->values['document_number']],
            ['label_patient', 'show_patient', $this->values['patient_name']],
            ['label_birth', 'show_birth', $this->values['date_of_birth']],
            ['label_identifier', 'show_identifier', $this->values['patient_identifier']],
            ['label_sequence', 'show_sequence', $this->values['sequence_no']],
            ['label_settings', 'show_settings', (string) (int) ($master['settings_version'] ?? 1)],
        ];
        $reissue = $this->letter['reissue'] ?? null;
        if (is_array($reissue)) {
            $source = trim((string) ($reissue['source_document_number'] ?? ''));
            $sourceDate = trim((string) ($reissue['source_letter_date'] ?? ''));
            $rows[] = ['label_reissue', 'show_reissue', $source . ($sourceDate === '' ? '' : ' vom ' . DateInput::format(substr($sourceDate, 0, 10)))];
        }
        $rows[] = ['label_date', 'show_date', $this->values['letter_date']];

        $y = self::INFO_TOP;
        $lineHeight = self::SIZE_SMALL * 1.45;
        $valueWidth = self::INFO_WIDTH - self::INFO_LABEL_WIDTH;
        foreach ($rows as [$key, $option, $value]) {
            if (!$this->zoneOption('info_block', $option, true)) {
                continue;
            }
            $label = $this->zoneText('info_block', $key);
            if ($label === '') {
                continue;
            }
            $value = trim($value) === '' ? $this->empty : $value;
            $labelLines = $this->wrap($label, self::INFO_LABEL_WIDTH - 2.0, 'regular', self::SIZE_SMALL);
            $valueLines = $this->wrap($value, $valueWidth, 'regular', self::SIZE_INFO);
            foreach ($labelLines as $index => $line) {
                $this->pdf->text(self::INFO_X, $y + $index * $lineHeight + 0.75 * self::SIZE_SMALL, $line, 'regular', self::SIZE_SMALL, self::MUTED);
            }
            foreach ($valueLines as $index => $line) {
                $this->pdf->text(self::INFO_X + self::INFO_LABEL_WIDTH, $y + $index * $lineHeight + 0.75 * self::SIZE_SMALL, $line, 'regular', self::SIZE_INFO, self::INK);
            }
            $y += max(count($labelLines), count($valueLines)) * $lineHeight;
        }
        return $y;
    }

    // --------------------------------------------------------------------- Brieftext

    private function body(float $y): void
    {
        foreach ((array) ($this->template['blocks'] ?? []) as $block) {
            if (!is_array($block) || ($block['enabled'] ?? true) !== true) {
                continue;
            }
            $y = match ((string) $block['type']) {
                'subject' => $this->subjectBlock($y, $block),
                'salutation' => $this->plainBlock($y, $this->blockValue($block, 'text')),
                'patient' => $this->patientBlock($y, $block),
                'anamnesis' => $this->recordBlock($y, $block, (array) ($this->letter['anamnesis'] ?? [])),
                'premedication' => $this->premedicationBlock($y, $block, (array) ($this->letter['premedication'] ?? [])),
                'report' => $this->reportBlock($y, $block, $this->letter['report'] ?? null),
                'epicrisis' => $this->recordBlock($y, $block, (array) ($this->letter['epicrisis'] ?? [])),
                'closing' => $this->closingBlock($y, $block),
                'text' => $this->textBlock($y, $block),
                default => $y,
            };
        }
    }

    /**
     * Betreff in Fettschrift ohne Leitwort; danach zwei Leerzeilen (DIN 5008).
     *
     * @param array<string, mixed> $block
     */
    private function subjectBlock(float $y, array $block): float
    {
        $title = $this->blockValue($block, 'title');
        $line2 = $this->blockValue($block, 'line2');
        if ($title === '' && $line2 === '') {
            return $y;
        }
        if ($title !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $title, 'bold', self::SIZE_SUBJECT);
        }
        if ($line2 !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $line2, 'bold', self::SIZE_TEXT);
        }
        return $y + 2 * self::SIZE_TEXT * self::LINE;
    }

    /**
     * Einzelner Absatz (Anrede) mit anschliessender Leerzeile.
     */
    private function plainBlock(float $y, string $text): float
    {
        if ($text === '') {
            return $y;
        }
        $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $text, 'regular', self::SIZE_TEXT);
        return $y + self::SIZE_TEXT * self::LINE;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function patientBlock(float $y, array $block): float
    {
        $patient = (array) ($this->letter['patient'] ?? []);
        $address = (array) ($patient['address'] ?? []);
        $lines = [];
        $phonePrefix = $this->blockValue($block, 'label_phone');
        foreach ([
            trim((string) ($address['street'] ?? '')),
            trim(trim((string) ($address['postal_code'] ?? '')) . ' ' . trim((string) ($address['city'] ?? ''))),
            trim((string) ($address['phone'] ?? '')) === '' ? '' : trim($phonePrefix . ' ' . trim((string) $address['phone'])),
        ] as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $rows = [];
        foreach ([
            ['label_name', $this->orEmpty((string) ($patient['patient_name'] ?? ''))],
            ['label_birth', $this->displayDate((string) ($patient['date_of_birth'] ?? ''))],
            ['label_identifier', $this->orEmpty((string) ($patient['patient_identifier'] ?? ''))],
            ['label_address', $lines === [] ? $this->empty : implode("\n", $lines)],
        ] as [$key, $value]) {
            $label = $this->blockValue($block, $key);
            if ($label !== '') {
                $rows[] = [$label, $value];
            }
        }
        $y = $this->sectionStart($y, $this->blockValue($block, 'heading'), '');
        if ($rows !== []) {
            $y = $this->fieldRows($y, $rows);
        }
        return $y + self::SIZE_TEXT * self::LINE;
    }

    /**
     * Textbaustein der Akte (Anamnese, Epikrise) mit Stand der Fassung.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $record
     */
    private function recordBlock(float $y, array $block, array $record): float
    {
        $meta = ($block['options']['show_meta'] ?? true) === true ? $this->recordMeta($record) : '';
        $y = $this->sectionStart($y, $this->blockValue($block, 'heading'), $meta);
        $text = trim((string) ($record['text'] ?? ''));
        $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $text === '' ? $this->empty : $text, 'regular', self::SIZE_TEXT);
        return $y + self::SIZE_TEXT * self::LINE;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $record
     */
    private function premedicationBlock(float $y, array $block, array $record): float
    {
        $meta = ($block['options']['show_meta'] ?? true) === true ? $this->recordMeta($record) : '';
        $y = $this->sectionStart($y, $this->blockValue($block, 'heading'), $meta);
        $entries = array_values(array_filter((array) ($record['entries'] ?? []), 'is_array'));
        if ($entries !== []) {
            $columns = [];
            foreach (self::PREMED_SHARES as $key => $share) {
                $columns[] = ['title' => $this->blockValue($block, $key), 'width' => self::CONTENT_WIDTH * $share];
            }
            $rows = [];
            foreach ($entries as $entry) {
                $dose = trim((string) ($entry['dose'] ?? '') . ' ' . (string) ($entry['unit'] ?? ''));
                $rows[] = [
                    $this->orEmpty((string) ($entry['substance'] ?? '')),
                    $dose === '' ? $this->empty : $dose,
                    $this->orEmpty((string) ($entry['schedule'] ?? '')),
                    $this->orEmpty((string) ($entry['reason'] ?? '')),
                    $this->period((string) ($entry['from'] ?? ''), (string) ($entry['to'] ?? '')),
                ];
            }
            $y = $this->flowTable($y, $columns, $rows);
        }
        $text = trim((string) ($record['text'] ?? ''));
        if ($text !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $text, 'regular', self::SIZE_TEXT);
        } elseif ($entries === []) {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $this->empty, 'regular', self::SIZE_TEXT);
        }
        return $y + self::SIZE_TEXT * self::LINE;
    }

    /**
     * Befundteil aus dem verknuepften Bericht; ohne Bericht entfaellt der Abschnitt.
     *
     * @param array<string, mixed> $block
     */
    private function reportBlock(float $y, array $block, mixed $report): float
    {
        if (!is_array($report)) {
            return $y;
        }
        $meta = ($block['options']['show_meta'] ?? true) === true ? trim((string) ($report['meta'] ?? '')) : '';
        $y = $this->sectionStart($y, $this->blockValue($block, 'heading'), $meta);
        $rows = self::valueRows($report['rows'] ?? []);
        if ($rows !== []) {
            $y = $this->fieldRows($y, $rows);
        }
        foreach (['leads', 'groups'] as $key) {
            foreach ((array) ($report[$key] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $groupRows = self::valueRows($group['rows'] ?? []);
                if ($groupRows === []) {
                    continue;
                }
                $y = $this->ensureSpace($y, self::SIZE_FIELD * self::LINE * 2.0);
                $this->pdf->text(self::LEFT, $y + 0.75 * self::SIZE_FIELD, (string) ($group['label'] ?? ''), 'bold', self::SIZE_FIELD, self::INK);
                $y += self::SIZE_FIELD * self::LINE;
                $y = $this->fieldRows($y, $groupRows);
            }
        }
        return $y + self::SIZE_TEXT * self::LINE;
    }

    /**
     * Grussformel, Platz fuer die Unterschrift (drei Leerzeilen) und Unterzeichner.
     *
     * @param array<string, mixed> $block
     */
    private function closingBlock(float $y, array $block): float
    {
        $text = $this->blockValue($block, 'text');
        $signature = $this->blockValue($block, 'signature');
        $lineHeight = self::SIZE_TEXT * self::LINE;
        $y = $this->ensureSpace($y, $lineHeight * 5.0);
        if ($text !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $text, 'regular', self::SIZE_TEXT);
        }
        if ($signature !== '') {
            $y = $this->paragraph(self::LEFT, $y + 3 * $lineHeight, self::CONTENT_WIDTH, $signature, 'regular', self::SIZE_TEXT);
        }
        return $y + $lineHeight;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function textBlock(float $y, array $block): float
    {
        $heading = $this->blockValue($block, 'heading');
        $text = $this->blockValue($block, 'text');
        if ($heading === '' && $text === '') {
            return $y;
        }
        if ($heading !== '') {
            $y = $this->sectionStart($y, $heading, '');
        }
        if ($text !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $text, 'regular', self::SIZE_TEXT);
        }
        return $y + self::SIZE_TEXT * self::LINE;
    }

    // --------------------------------------------------------------------- Anhang

    /**
     * Anhang: vollstaendige Tabelle der Abfrage, beginnt auf einer neuen Seite und laeuft
     * mehrseitig mit wiederholter Kopfzeile.
     */
    private function appendix(): void
    {
        if (!$this->zoneOption('appendix', 'show')) {
            return;
        }
        $appendix = (array) ($this->letter['appendix'] ?? []);
        $sections = array_values(array_filter((array) ($appendix['sections'] ?? []), 'is_array'));
        if ($sections === []) {
            return;
        }

        $this->pdf->addPage();
        $y = self::NEXT_TOP;
        $heading = $this->zoneText('appendix', 'heading');
        if ($heading !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $heading, 'bold', self::SIZE_SUBJECT);
        }
        $y = $this->paragraph(self::LEFT, $y + 1.0, self::CONTENT_WIDTH, $this->appendixMeta($appendix), 'regular', self::SIZE_SMALL, self::MUTED);
        $y = $this->appendixHeader($y + 4.0);

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
            $y = $this->appendixSectionRow($y, $this->zoneText('appendix', 'notes_heading'));
            $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $notes, 'regular', self::SIZE_TABLE);
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
        $intro = $this->zoneText('appendix', 'intro');
        return ($intro === '' ? '' : $intro . ' · ') . implode(' · ', $parts);
    }

    private function appendixHeader(float $y): float
    {
        $y = $this->ensureSpace($y, self::SIZE_TABLE * self::LINE + 6.0);
        $this->pdf->text(self::LEFT + self::CELL_PAD, $y + 0.75 * self::SIZE_TABLE, $this->zoneText('appendix', 'column_parameter'), 'bold', self::SIZE_TABLE, self::INK);
        $this->pdf->text(self::LEFT + self::APPENDIX_LABEL_WIDTH + self::CELL_PAD, $y + 0.75 * self::SIZE_TABLE, $this->zoneText('appendix', 'column_value'), 'bold', self::SIZE_TABLE, self::INK);
        $y += self::SIZE_TABLE * self::LINE + 2 * self::CELL_PAD;
        $this->pdf->line(self::LEFT, $y, self::RIGHT, $y, self::RULE, 0.7);
        return $y + 2.0;
    }

    private function appendixSectionRow(float $y, string $label): float
    {
        $height = self::SIZE_TABLE * self::LINE + 3.0;
        if ($y + $height + self::SIZE_TABLE * self::LINE > self::BOTTOM_LIMIT) {
            $this->pdf->addPage();
            $y = $this->appendixHeader(self::NEXT_TOP);
        }
        $this->pdf->rect(self::LEFT, $y, self::CONTENT_WIDTH, $height, self::SECTION_FILL, null, 0.0);
        $this->pdf->text(self::LEFT + 2.0, $y + 1.5 + 0.75 * self::SIZE_TABLE, $label, 'bold', self::SIZE_TABLE, self::INK);
        return $y + $height + 2.0;
    }

    /**
     * @param list<array{0: string, 1: string}> $rows
     */
    private function appendixRows(float $y, array $rows): float
    {
        $lineHeight = self::SIZE_TABLE * self::LINE;
        $last = count($rows) - 1;
        foreach ($rows as $index => $row) {
            $labelLines = $this->wrap($row[0], self::APPENDIX_LABEL_WIDTH - 2 * self::CELL_PAD, 'regular', self::SIZE_TABLE);
            $valueLines = $this->wrap($row[1], self::CONTENT_WIDTH - self::APPENDIX_LABEL_WIDTH - 2 * self::CELL_PAD, 'bold', self::SIZE_TABLE);
            $height = max(count($labelLines), count($valueLines)) * $lineHeight + 2 * self::CELL_PAD;
            if ($y + $height > self::BOTTOM_LIMIT) {
                $this->pdf->addPage();
                $y = $this->appendixHeader(self::NEXT_TOP);
            }
            foreach ($labelLines as $lineIndex => $line) {
                $this->pdf->text(self::LEFT + self::CELL_PAD, $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.75 * self::SIZE_TABLE, $line, 'regular', self::SIZE_TABLE, self::INK);
            }
            foreach ($valueLines as $lineIndex => $line) {
                $this->pdf->text(self::LEFT + self::APPENDIX_LABEL_WIDTH + self::CELL_PAD, $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.75 * self::SIZE_TABLE, $line, 'bold', self::SIZE_TABLE, self::INK);
            }
            $y += $height;
            if ($index < $last) {
                $this->pdf->line(self::LEFT, $y, self::RIGHT, $y, self::RULE, 0.3);
                $y += 1.5;
            }
        }
        return $y + 3.0;
    }

    // --------------------------------------------------------------------- Seitenelemente

    /**
     * Fusszeile mit Seitenzahl auf jeder Seite, Kopfzeile auf Folgeseiten, Falz- und Lochmarken
     * auf der ersten Seite.
     */
    private function pageFurniture(DateTimeImmutable $generatedAt): void
    {
        $total = $this->pdf->pageCount();
        $disclaimer = $this->zoneText('footer', 'disclaimer');
        $continuation = $this->zoneText('footer', 'continuation');
        $created = sprintf(
            'Erstellt am %s · Dokument %s · Brief-Fassung %d · Vorlage Fassung %s',
            $generatedAt->format('d.m.Y H:i:s'),
            (string) ($this->letter['document']['document_number'] ?? ''),
            (int) ($this->letter['letter_version'] ?? self::SUPPORTED_LETTER_VERSION),
            (string) ($this->letter['template']['version_no'] ?? '–'),
        );
        $pageTemplate = (string) ($this->template['zones']['footer']['texts']['page_label'] ?? '');

        for ($page = 0; $page < $total; $page++) {
            $this->pdf->setPage($page);
            if ($page === 0 && $this->zoneOption('footer', 'fold_marks')) {
                foreach (self::FOLD_MARKS as $markY) {
                    $this->pdf->line(4.0 * self::MM, $markY, 9.0 * self::MM, $markY, self::MARK, 0.5);
                }
                $this->pdf->line(4.0 * self::MM, self::HOLE_MARK, 11.0 * self::MM, self::HOLE_MARK, self::MARK, 0.5);
            }
            if ($page > 0 && $continuation !== '') {
                $headY = 12.0 * self::MM;
                $this->pdf->text(self::LEFT, $headY, $this->wrap($continuation, self::CONTENT_WIDTH, 'regular', self::SIZE_SMALL)[0] ?? '', 'regular', self::SIZE_SMALL, self::MUTED);
                $this->pdf->line(self::LEFT, $headY + 3.0, self::RIGHT, $headY + 3.0, self::MUTED, 0.3);
            }

            $y = self::FOOTER_RULE;
            $this->pdf->line(self::LEFT, $y, self::RIGHT, $y, self::MUTED, 0.4);
            $pageLabel = self::clean(LetterTemplate::fill($pageTemplate, $this->values + [
                'page' => (string) ($page + 1),
                'pages' => (string) $total,
            ]));
            $pageWidth = $pageLabel === '' ? 0.0 : PdfDocument::textWidth($pageLabel, 'bold', 8.0) + 6.0;
            $textY = $y + 9.0;
            if ($disclaimer !== '') {
                foreach (array_slice($this->wrap($disclaimer, self::CONTENT_WIDTH - $pageWidth, 'regular', 7.0), 0, 2) as $line) {
                    $this->pdf->text(self::LEFT, $textY, $line, 'regular', 7.0, self::MUTED);
                    $textY += 8.5;
                }
            }
            $this->pdf->text(self::LEFT, $textY, $created, 'regular', 7.0, self::MUTED);
            if ($pageLabel !== '') {
                $this->pdf->text(self::RIGHT - PdfDocument::textWidth($pageLabel, 'bold', 8.0), $y + 9.0, $pageLabel, 'bold', 8.0, self::INK);
            }
        }
    }

    private function logo(float $x, float $y, ?ImageData $logo): void
    {
        if ($logo !== null && $logo->width > 0 && $logo->height > 0) {
            $scale = min(self::LOGO_WIDTH / $logo->width, self::LOGO_HEIGHT / $logo->height);
            $width = $logo->width * $scale;
            $this->pdf->image($x + self::LOGO_WIDTH - $width, $y, $width, $logo->height * $scale, $logo);
            return;
        }
        $this->pdf->rect($x, $y, self::LOGO_WIDTH, self::LOGO_HEIGHT, null, self::MARK, 0.5);
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

    // --------------------------------------------------------------------- Bausteine

    private function sectionStart(float $y, string $title, string $meta): float
    {
        $y = $this->ensureSpace($y, self::SIZE_SECTION * self::LINE + 3.0 * self::SIZE_TEXT * self::LINE);
        if ($title !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $title, 'bold', self::SIZE_SECTION);
        }
        if ($meta !== '') {
            $y = $this->paragraph(self::LEFT, $y, self::CONTENT_WIDTH, $meta, 'regular', self::SIZE_SMALL, self::MUTED);
        }
        return $y + 1.5;
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
            $timestamp = strtotime($created);
            $parts[] = 'Stand ' . ($timestamp === false ? $created : date('d.m.Y H:i', $timestamp));
        }
        return implode(' · ', $parts);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function valueRows(mixed $rows): array
    {
        $result = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
                $result[] = [(string) ($row['label'] ?? ''), (string) $row['value']];
            }
        }
        return $result;
    }

    private function ensureSpace(float $y, float $needed): float
    {
        if ($y + $needed <= self::BOTTOM_LIMIT) {
            return $y;
        }
        $this->pdf->addPage();
        return self::NEXT_TOP;
    }

    /**
     * Beschriftung links, Wert rechts daneben; Seitenumbruch vor jeder Zeile.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    private function fieldRows(float $y, array $rows): float
    {
        $lineHeight = self::SIZE_FIELD * self::LINE;
        foreach ($rows as $row) {
            $labelLines = $this->wrap($row[0], self::LABEL_WIDTH - 4.0, 'regular', self::SIZE_FIELD);
            $valueLines = $this->wrap($row[1], self::CONTENT_WIDTH - self::LABEL_WIDTH, 'bold', self::SIZE_FIELD);
            $height = max(count($labelLines), count($valueLines)) * $lineHeight;
            $y = $this->ensureSpace($y, $height);
            foreach ($labelLines as $index => $line) {
                $this->pdf->text(self::LEFT, $y + $index * $lineHeight + 0.75 * self::SIZE_FIELD, $line, 'regular', self::SIZE_FIELD, self::INK);
            }
            foreach ($valueLines as $index => $line) {
                $this->pdf->text(self::LEFT + self::LABEL_WIDTH, $y + $index * $lineHeight + 0.75 * self::SIZE_FIELD, $line, 'bold', self::SIZE_FIELD, self::INK);
            }
            $y += $height;
        }
        return $y + 2.0;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function paragraph(float $x, float $y, float $width, string $text, string $font, float $size, array $color = self::INK): float
    {
        $lineHeight = $size * self::LINE;
        foreach ($this->wrap($text, $width, $font, $size) as $line) {
            $y = $this->ensureSpace($y, $lineHeight);
            $this->pdf->text($x, $y + 0.75 * $size, $line, $font, $size, $color);
            $y += $lineHeight;
        }
        return $y;
    }

    /**
     * Zeilen, die nicht ueber $limit hinauslaufen (Kopfzone der ersten Seite, ohne Umbruch).
     *
     * @param array{0: float, 1: float, 2: float} $color
     */
    private function boundedLines(float $x, float $y, float $width, string $text, string $font, float $size, array $color, float $limit): float
    {
        $lineHeight = $size * 1.25;
        foreach ($this->wrap($text, $width, $font, $size) as $line) {
            if ($y + $lineHeight > $limit) {
                break;
            }
            $this->pdf->text($x, $y + 0.75 * $size, $line, $font, $size, $color);
            $y += $lineHeight;
        }
        return $y;
    }

    /**
     * Tabelle mit Seitenumbruch und wiederholter Kopfzeile (Vormedikation).
     *
     * @param list<array{title: string, width: float}> $columns
     * @param list<list<string>> $rows
     */
    private function flowTable(float $y, array $columns, array $rows): float
    {
        $size = self::SIZE_TABLE;
        $lineHeight = $size * self::LINE;
        $header = function (float $lineY) use ($columns, $size, $lineHeight): float {
            $cellX = self::LEFT;
            foreach ($columns as $column) {
                $this->pdf->text($cellX + self::CELL_PAD, $lineY + self::CELL_PAD + 0.75 * $size, $column['title'], 'bold', $size, self::INK);
                $cellX += $column['width'];
            }
            $lineY += $lineHeight + 2 * self::CELL_PAD;
            $this->pdf->line(self::LEFT, $lineY, self::RIGHT, $lineY, self::RULE, 0.7);
            return $lineY + 1.5;
        };
        $y = $header($this->ensureSpace($y, $lineHeight * 3.0));

        foreach ($rows as $row) {
            $cells = [];
            $maxLines = 1;
            foreach ($columns as $index => $column) {
                $lines = $this->wrap((string) ($row[$index] ?? ''), $column['width'] - 2 * self::CELL_PAD - 2.0, 'regular', $size);
                $cells[] = $lines;
                $maxLines = max($maxLines, count($lines));
            }
            $height = $maxLines * $lineHeight + 2 * self::CELL_PAD;
            if ($y + $height > self::BOTTOM_LIMIT) {
                $this->pdf->addPage();
                $y = $header(self::NEXT_TOP);
            }
            $cursor = self::LEFT;
            foreach ($columns as $index => $column) {
                foreach ($cells[$index] as $lineIndex => $line) {
                    $this->pdf->text($cursor + self::CELL_PAD, $y + self::CELL_PAD + $lineIndex * $lineHeight + 0.75 * $size, $line, 'regular', $size, self::INK);
                }
                $cursor += $column['width'];
            }
            $y += $height;
            $this->pdf->line(self::LEFT, $y, self::RIGHT, $y, self::RULE, 0.3);
            $y += 1.5;
        }
        return $y + 3.0;
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

    // --------------------------------------------------------------------- Vorlagenwerte

    private function zoneText(string $zone, string $key): string
    {
        return self::clean(LetterTemplate::fill((string) ($this->template['zones'][$zone]['texts'][$key] ?? ''), $this->values));
    }

    private function zoneOption(string $zone, string $key, bool $default = false): bool
    {
        return ($this->template['zones'][$zone]['options'][$key] ?? $default) === true;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockValue(array $block, string $key): string
    {
        return self::clean(LetterTemplate::fill((string) ($block['texts'][$key] ?? ''), $this->values));
    }

    /**
     * Text der ersten aktiven Instanz eines Bausteins (fuer Metadaten).
     */
    private function blockText(string $type, string $key): string
    {
        foreach ((array) ($this->template['blocks'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === $type && ($block['enabled'] ?? true) === true) {
                return $this->blockValue($block, $key);
            }
        }
        return '';
    }

    /**
     * Entfernt Trennzeichen, die durch leere Platzhalter verwaist sind (z. B. "Name · ").
     */
    private static function clean(string $text): string
    {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = (string) preg_replace('/(\s*·\s*){2,}/u', ' · ', $line);
            $line = (string) preg_replace('/^\s*·\s*|\s*·\s*$/u', '', $line);
            $line = (string) preg_replace('/\s*,\s*$/u', ',', $line);
            $lines[] = rtrim($line);
        }
        return trim(implode("\n", $lines), "\n");
    }

    private function period(string $from, string $to): string
    {
        if ($from === '' && $to === '') {
            return $this->empty;
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
        return $value === '' ? $this->empty : DateInput::format(substr($value, 0, 10));
    }

    private function orEmpty(string $value): string
    {
        $value = trim($value);
        return $value === '' ? $this->empty : $value;
    }
}
