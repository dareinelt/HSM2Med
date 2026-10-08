<?php

declare(strict_types=1);

namespace App\Patient;

use App\PatientCard\PatientCardRepository;
use App\Report\ReportService;
use App\Support\DateInput;

/**
 * Vorbelegung der Schrittmacher-/ICD-Abfrage aus dem zuletzt importierten Bericht.
 *
 * Grundsaetze:
 *  * Quelle ist ausschliesslich der neueste mit dem Patienten verknuepfte Bericht. Es wird
 *    nichts geschaetzt und nichts erfunden: Felder ohne Fundstelle bleiben leer.
 *  * Die Aufloesung entspricht der des Patientenausweises (Parameter-IDs vor
 *    Parameterbezeichnungen, mehrere Quellen mit 'glue' verbunden, Messwerte ueber
 *    PatientCardRepository::measurementValues gebuendelt gelesen).
 *  * Vorbelegte Werte sind Vorschlaege: sie ueberschreiben nie eine vorhandene Eingabe und
 *    bleiben im Formular aenderbar.
 *  * Der Ausweis bleibt unberuehrt; die Abfrage ist nach der Uebernahme die alleinige Quelle.
 */
final class DeviceCheckPrefill
{
    /** Feldpfad der Angabe zur MRT-Tauglichkeit (Vertrag der Abfragevorlage). */
    private const string MRT_FIELD = 'device.mrt_compatibility';

    /** Zuordnung der Kammerbezeichnung des Berichts zur Lokalisation der Sonde. */
    private const array LEAD_LOCATIONS = ['atrial' => 'RA', 'rv' => 'RV', 'lv' => 'LV'];

    public function __construct(
        private readonly PatientRepository $patients,
        private readonly PatientCardRepository $cards,
        private readonly ReportService $reports,
    ) {
    }

    /**
     * Aus dem letzten Bericht ableitbare Werte des Bausteins.
     *
     * @return array{
     *     values: array<string, string>,
     *     leads: list<array<string, string>>,
     *     report_id: int|null,
     *     report_label: string,
     *     filled: int
     * }
     */
    public function fromLastReport(int $patientId, string $deviceType, DeviceCheckTemplate $template): array
    {
        $empty = ['values' => [], 'leads' => [], 'report_id' => null, 'report_label' => '', 'filled' => 0];
        if (!$template->hasDeviceType($deviceType)) {
            return $empty;
        }

        $values = [];
        $leads = [];
        $reportId = null;
        $reportLabel = '';

        $reports = $this->patients->reportsByPatient($patientId);
        $candidate = isset($reports[0]['id']) ? (int) $reports[0]['id'] : 0;
        $report = $candidate > 0 ? $this->reports->load($candidate) : null;

        if ($report !== null) {
            $reportId = $candidate;
            $measurements = $this->cards->measurementValues(
                [$reportId],
                $template->parameterIds(),
                $template->parameterNames(),
            );
            $byId = $measurements[$reportId]['ids'] ?? [];
            $byName = $measurements[$reportId]['names'] ?? [];

            $summary = [];
            foreach ($report->summarySection('device') as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $summary['device.' . $label] = $value;
                }
            }

            foreach ($template->fields($deviceType) as $key => $field) {
                if ($field['from_card'] !== null) {
                    continue;
                }
                $value = $field['from_summary'] !== null
                    ? ($summary[$field['from_summary']] ?? '')
                    : self::fromSources($field['sources'], $field['glue'], $byId, $byName);
                $value = self::acceptable($value, $field);
                if ($value !== '') {
                    $values[$key] = $value;
                }
            }

            $leads = $this->leads($report->leads(), $template, $deviceType);
            $reportLabel = self::reportLabel($reportId, $report->summarySection('follow_up'));
        }

        // Der Ausweis ist die fuehrende Quelle der Angaben zur MRT-Tauglichkeit.
        foreach ($this->fromCard($patientId, $template) as $key => $value) {
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return [
            'values' => $values,
            'leads' => $leads,
            'report_id' => $reportId,
            'report_label' => $reportLabel,
            'filled' => count($values) + array_sum(array_map('count', $leads)),
        ];
    }

    /**
     * Angaben zur MRT-Tauglichkeit des neuesten Ausweises samt Hinweis, ob sie gelten.
     *
     * Der Ausweis ist die fuehrende Quelle, solange er eine Angabe enthaelt: dann wird sie im
     * Formular der Abfrage nur lesend uebernommen. Ohne Angabe im Ausweis bleibt die Abfrage
     * erfassbar (und der naechste Ausweis wird daraus vorbelegt).
     *
     * @return array{locked: bool, values: array<string, string>}
     */
    public function cardMrt(int $patientId, DeviceCheckTemplate $template): array
    {
        $found = $this->fromCard($patientId, $template);
        if (($found[self::MRT_FIELD] ?? '') === '') {
            return ['locked' => false, 'values' => []];
        }
        $values = [];
        foreach ($template->allFields() as $key => $field) {
            if ($field['from_card'] !== null) {
                $values[$key] = $found[$key] ?? '';
            }
        }
        return ['locked' => true, 'values' => $values];
    }

    /**
     * Angaben zur MRT-Tauglichkeit aus dem neuesten Ausweis des Patienten.
     *
     * Der Ausweis ist die fuehrende Quelle: er wird im Ausweis-Assistenten erfasst und hier
     * nur uebernommen. Gibt es noch keinen Ausweis, bleibt das Feld leer und kann in der
     * Abfrage erfasst werden.
     *
     * @return array<string, string> Feldpfad => Wert
     */
    public function fromCard(int $patientId, DeviceCheckTemplate $template): array
    {
        $values = [];
        $cards = $this->cards->cardsByPatient($patientId);
        $cardId = isset($cards[0]['id']) ? (int) $cards[0]['id'] : 0;
        if ($cardId === 0) {
            return $values;
        }
        $card = $this->cards->card($cardId, true);
        $snapshot = is_array($card) ? json_decode((string) ($card['snapshot'] ?? ''), true) : null;
        $device = is_array($snapshot) && is_array($snapshot['device'] ?? null) ? $snapshot['device'] : [];

        foreach ($template->allFields() as $key => $field) {
            if ($field['from_card'] === null) {
                continue;
            }
            $value = $device[$field['from_card']] ?? '';
            $value = is_string($value) ? trim($value) : '';
            $value = self::acceptable($value, $field);
            if ($value !== '') {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /**
     * Prueft einen Wert gegen Typ, Auswahl und Laenge des Feldes.
     *
     * @param array<string, mixed> $field
     */
    private static function acceptable(string $value, array $field): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($field['type'] === 'date') {
            $iso = DateInput::parse($value);
            return $iso === null ? '' : DateInput::format($iso);
        }
        if ($field['options'] !== null && !in_array($value, $field['options'], true)) {
            return '';
        }
        return mb_strlen($value) > $field['maxlength'] ? mb_substr($value, 0, $field['maxlength']) : $value;
    }

    /**
     * Beschriftung des Berichts: "Bericht Nr. 12 vom 07.10.2026", wenn ein Datum vorliegt.
     *
     * @param list<array<string, mixed>> $followUp
     */
    private static function reportLabel(int $reportId, array $followUp): string
    {
        $label = sprintf('Bericht Nr. %d', $reportId);
        foreach ($followUp as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if (($row['label'] ?? '') === 'Untersuchung' && $value !== '') {
                return $label . ' vom ' . $value;
            }
        }
        return $label;
    }

    /**
     * @param list<array<string, mixed>> $reportLeads
     * @return list<array<string, string>>
     */
    private function leads(array $reportLeads, DeviceCheckTemplate $template, string $deviceType): array
    {
        $allowed = $template->leadFieldMap($deviceType);
        $leads = [];
        foreach ($reportLeads as $reportLead) {
            if (!is_array($reportLead)) {
                continue;
            }
            $lead = [];
            // Wie auf dem Ausweis: Modellnummer und Modellbezeichnung zusammen.
            $model = trim(trim((string) ($reportLead['model_number'] ?? '')) . ' ' . trim((string) ($reportLead['model_label'] ?? '')));
            if (isset($allowed['leads.model']) && $model !== '') {
                $lead['model'] = mb_substr($model, 0, $allowed['leads.model']['maxlength']);
            }
            $chamber = (string) ($reportLead['chamber'] ?? '');
            $location = self::LEAD_LOCATIONS[$chamber] ?? '';
            if (isset($allowed['leads.location']) && $location !== '') {
                $lead['location'] = $location;
            }
            $implant = trim((string) ($reportLead['implant_date_display'] ?? ''));
            if (isset($allowed['leads.implant_date']) && $implant !== '') {
                $iso = DateInput::parse($implant);
                if ($iso !== null) {
                    $lead['implant_date'] = DateInput::format($iso);
                }
            }
            if ($lead !== []) {
                $leads[] = $lead;
            }
        }
        return $leads;
    }

    /**
     * Erste nicht leere Fundstelle gewinnt: Parameter-IDs vor Parameterbezeichnungen.
     * Mehrere Quellen werden mit 'glue' verbunden (z. B. Amplitude und Pulsbreite zu "1,5/0,4").
     *
     * @param list<array{ids: list<string>, names: list<string>}> $sources
     * @param array<string, string> $byId
     * @param array<string, string> $byName
     */
    private static function fromSources(array $sources, string $glue, array $byId, array $byName): string
    {
        $parts = [];
        foreach ($sources as $source) {
            $value = '';
            foreach ($source['ids'] as $id) {
                $value = trim($byId[$id] ?? '');
                if ($value !== '') {
                    break;
                }
            }
            if ($value === '') {
                foreach ($source['names'] as $name) {
                    $value = trim($byName[$name] ?? '');
                    if ($value !== '') {
                        break;
                    }
                }
            }
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode($glue, $parts);
    }
}
