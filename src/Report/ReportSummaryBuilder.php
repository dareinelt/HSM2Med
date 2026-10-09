<?php

declare(strict_types=1);

namespace App\Report;

use App\Import\ParsedRecord;
use App\Mapping\ParameterMapping;
use App\Support\SourceDate;

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
            $fields[$key] = $this->findField($records, $spec);
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
            $display = $isDate ? SourceDate::display($value, $dateOnly) : $value;
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
                $lead['implant_date_display'] = SourceDate::display($lead['implant_date'], true);
                return $lead;
            }, $summary->leads),
        ];
    }

    /**
     * Erster Treffer nach Quell-ID, danach nach Bezeichnung; ersatzweise zusammengesetzte Felder
     * (z.B. Nachname + Vorname).
     *
     * @param list<ParsedRecord> $records
     * @param array{ids: list<string>, names: list<string>, combine: ?array{names: list<string>, separator: string}} $spec
     * @return array{value: string, unit: string, parameter_id: string, position: int}|null
     */
    private function findField(array $records, array $spec): ?array
    {
        foreach ($spec['ids'] as $id) {
            foreach ($records as $record) {
                if ($record->parameterId === $id) {
                    return $this->fieldFromRecord($record);
                }
            }
        }

        $byName = $this->findByName($records, $spec['names']);
        if ($byName !== null) {
            return $byName;
        }

        $combine = $spec['combine'];
        if ($combine === null) {
            return null;
        }
        $values = [];
        $first = null;
        foreach ($combine['names'] as $name) {
            $found = $this->findByName($records, [$name]);
            if ($found === null || trim($found['value']) === '') {
                continue;
            }
            $first ??= $found;
            $values[] = $found['value'];
        }
        if ($first === null) {
            return null;
        }

        return [
            'value' => implode($combine['separator'], $values),
            'unit' => '',
            'parameter_id' => $first['parameter_id'],
            'position' => $first['position'],
        ];
    }

    /**
     * @param list<ParsedRecord> $records
     * @param list<string> $names
     * @return array{value: string, unit: string, parameter_id: string, position: int}|null
     */
    private function findByName(array $records, array $names): ?array
    {
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
        $leads = $this->extractSectionLeads($records);
        $leads = $this->extractPatternLeads($records, $leads);

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

    /**
     * Sonden aus ganzen XML-Abschnitten: alle Felder eines Abschnitts gehoeren zu einer Sonde.
     *
     * @param list<ParsedRecord> $records
     * @return array<string, array<string, mixed>>
     */
    private function extractSectionLeads(array $records): array
    {
        $leads = [];
        foreach ($this->mapping->leadSections() as $definition) {
            foreach ($this->groupBySection($records, $definition['section']) as $group) {
                $chamberRecord = $this->findFieldRecord($group, $definition['chamber']);
                if ($chamberRecord === null || trim((string) $chamberRecord->value) === '') {
                    continue;
                }
                $sourceChamber = trim((string) $chamberRecord->value);
                $chamber = $this->mapping->leadChamber($sourceChamber);
                $key = $chamber['key'];
                $leads[$key] ??= $this->emptyLead($key, $chamber['label'], $sourceChamber, $group[0]->position);

                foreach ($definition['fields'] as $field => $spec) {
                    if ($leads[$key][$field] !== null) {
                        continue;
                    }
                    $record = $this->findFieldRecord($group, $spec);
                    if ($record === null) {
                        continue;
                    }
                    $leads[$key][$field] = (string) $record->value;
                    $leads[$key]['source_parameter_ids'][] = $record->parameterId;
                    if ($field === 'model_number') {
                        $leads[$key]['model_label'] = (string) $record->value;
                    }
                }
            }
        }
        return $leads;
    }

    /**
     * Datensaetze nach Abschnittspfad gruppieren; gleichnamige Abschnitte sind durch "#n" getrennt.
     *
     * @param list<ParsedRecord> $records
     * @return list<list<ParsedRecord>>
     */
    private function groupBySection(array $records, string $section): array
    {
        $groups = [];
        foreach ($records as $record) {
            if ($record->section === '' || !$this->isSection($record->section, $section)) {
                continue;
            }
            $groups[$record->section][] = $record;
        }
        return array_values($groups);
    }

    private function isSection(string $section, string $wanted): bool
    {
        if ($section === $wanted) {
            return true;
        }
        return str_starts_with($section, $wanted) && str_starts_with(substr($section, strlen($wanted)), '#');
    }

    /**
     * @param list<ParsedRecord> $group
     * @param array{ids: list<string>, names: list<string>, combine: ?array{names: list<string>, separator: string}} $spec
     */
    private function findFieldRecord(array $group, array $spec): ?ParsedRecord
    {
        foreach ($spec['ids'] as $id) {
            foreach ($group as $record) {
                if ($record->parameterId === $id) {
                    return $record;
                }
            }
        }
        foreach ($spec['names'] as $name) {
            foreach ($group as $record) {
                if (mb_strtolower(trim($record->name)) === mb_strtolower(trim($name))) {
                    return $record;
                }
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLead(string $key, string $label, string $sourceChamber, int $position): array
    {
        return [
            'chamber' => $key,
            'chamber_label' => $label,
            'chamber_source' => $sourceChamber,
            'manufacturer' => null,
            'model_label' => null,
            'model_number' => null,
            'serial_number' => null,
            'lead_type' => null,
            'implant_date' => null,
            'source_parameter_ids' => [],
            'first_position' => $position,
        ];
    }

    /**
     * Sonden aus Textzeilen (Merlin-Format). Datensaetze aus XML-Abschnitten werden hier nicht
     * erneut ausgewertet, weil ihre Bezeichnungen nicht dem Merlin-Zeilenformat entsprechen.
     *
     * @param list<ParsedRecord> $records
     * @param array<string, array<string, mixed>> $leads
     * @return array<string, array<string, mixed>>
     */
    private function extractPatternLeads(array $records, array $leads): array
    {
        foreach ($records as $record) {
            if ($record->section !== '') {
                continue;
            }
            foreach ($this->mapping->leadFields() as $field => $pattern) {
                if (preg_match($pattern, $record->name, $m) !== 1) {
                    continue;
                }
                $sourceChamber = trim($m['chamber']);
                $chamber = $this->mapping->leadChamber($sourceChamber);
                $key = $chamber['key'];
                $leads[$key] ??= $this->emptyLead($key, $chamber['label'], $sourceChamber, $record->position);
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
        return $leads;
    }
}
