<?php

declare(strict_types=1);

namespace App\Report;

use App\Import\ParsedRecord;
use App\Mapping\ParameterMapping;
use App\Support\MerlinDate;

/**
 * Leitet die Kopfdaten (Patient, Geraet, Sonden) aus den Datensaetzen ab und erzeugt den
 * Zusammenfassungs-Snapshot, der beim Import unveraenderlich im Bericht gespeichert wird.
 * Es werden ausschliesslich Werte aus der Quelldatei verwendet – nichts wird ergaenzt.
 */
final class ReportSummaryBuilder
{
    public const int SNAPSHOT_VERSION = 1;

    private const array LEAD_IDENTITY_FIELDS = ['manufacturer', 'model_number', 'serial_number', 'implant_date'];

    public function __construct(private readonly ParameterMapping $mapping)
    {
    }

    /**
     * @param list<ParsedRecord> $records
     */
    public function build(array $records): ReportSummary
    {
        $fields = [];
        foreach ($this->mapping->fields() as $key => $spec) {
            $fields[$key] = $this->findField($records, $spec['ids'], $spec['names']);
        }
        return new ReportSummary($fields, $this->extractLeads($records));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(ReportSummary $summary): array
    {
        $row = static function (string $label, string $key, bool $isDate = false, bool $dateOnly = false) use ($summary): array {
            $value = $summary->value($key);
            $display = $isDate ? MerlinDate::display($value, $dateOnly) : $value;
            return [
                'label' => $label,
                'value' => $display,
                'unit' => $summary->unit($key) ?? '',
                'original' => $display !== $value ? $value : null,
                'source_parameter_id' => $summary->sourceId($key),
            ];
        };

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'sections' => [
                [
                    'key' => 'patient',
                    'title' => 'Patient',
                    'rows' => [
                        $row('Name', 'patient_name'),
                        $row('Patient-ID', 'patient_identifier'),
                        $row('Geburtsdatum', 'patient_dob', true, true),
                    ],
                ],
                [
                    'key' => 'device',
                    'title' => 'Gerät',
                    'rows' => [
                        $row('Hersteller', 'device_manufacturer'),
                        $row('Modell', 'device_model_name'),
                        $row('Modellnummer', 'device_model_number'),
                        $row('Seriennummer', 'device_serial'),
                        $row('Implantation', 'device_implant_date', true, true),
                        $row('Modus', 'mode'),
                        $row('Grundfrequenz', 'base_rate'),
                        $row('Sitzungszeitpunkt', 'session_timestamp', true),
                        $row('Letzte Abfrage', 'interrogation_timestamp', true),
                    ],
                ],
            ],
            'leads' => array_map(static function (array $lead): array {
                $lead['implant_date_display'] = MerlinDate::display($lead['implant_date'], true);
                return $lead;
            }, $summary->leads),
        ];
    }

    /**
     * @param list<ParsedRecord> $records
     * @param list<string> $ids
     * @param list<string> $names
     * @return array{value: string, unit: string, parameter_id: string, position: int}|null
     */
    private function findField(array $records, array $ids, array $names): ?array
    {
        foreach ($ids as $id) {
            foreach ($records as $record) {
                if ($record->parameterId === $id) {
                    return $this->fieldFromRecord($record);
                }
            }
        }
        $wanted = array_map(static fn (string $n): string => mb_strtolower(trim($n)), $names);
        foreach ($wanted as $name) {
            foreach ($records as $record) {
                if (mb_strtolower(trim($record->name)) === $name) {
                    return $this->fieldFromRecord($record);
                }
            }
        }
        return null;
    }

    /**
     * @return array{value: string, unit: string, parameter_id: string, position: int}
     */
    private function fieldFromRecord(ParsedRecord $record): array
    {
        return [
            'value' => (string) $record->value,
            'unit' => (string) $record->unit,
            'parameter_id' => $record->parameterId,
            'position' => $record->position,
        ];
    }

    /**
     * @param list<ParsedRecord> $records
     * @return list<array<string, mixed>>
     */
    private function extractLeads(array $records): array
    {
        $leads = [];
        foreach ($records as $record) {
            foreach ($this->mapping->leadFields() as $field => $pattern) {
                if (preg_match($pattern, $record->name, $m) !== 1) {
                    continue;
                }
                $sourceChamber = trim($m['chamber']);
                $chamber = $this->mapping->leadChamber($sourceChamber);
                $key = $chamber['key'];
                $leads[$key] ??= [
                    'chamber' => $key,
                    'chamber_label' => $chamber['label'],
                    'chamber_source' => $sourceChamber,
                    'manufacturer' => null,
                    'model_label' => null,
                    'model_number' => null,
                    'serial_number' => null,
                    'lead_type' => null,
                    'implant_date' => null,
                    'source_parameter_ids' => [],
                    'first_position' => $record->position,
                ];
                if ($leads[$key][$field] === null) {
                    $leads[$key][$field] = (string) $record->value;
                    $leads[$key]['source_parameter_ids'][] = $record->parameterId;
                    if ($field === 'model_number' && isset($m['label'])) {
                        $leads[$key]['model_label'] = trim($m['label']);
                    }
                }
                break;
            }
        }

        // Nur Sonden mit mindestens einem identifizierenden, nicht leeren Wert
        $leads = array_filter($leads, static function (array $lead): bool {
            foreach (self::LEAD_IDENTITY_FIELDS as $field) {
                if ($lead[$field] !== null && trim($lead[$field]) !== '') {
                    return true;
                }
            }
            return false;
        });

        $order = ['atrial' => 0, 'rv' => 1, 'lv' => 2];
        uasort($leads, static fn (array $a, array $b): int =>
            [$order[$a['chamber']] ?? 99, $a['first_position']] <=> [$order[$b['chamber']] ?? 99, $b['first_position']]);

        return array_values($leads);
    }
}
