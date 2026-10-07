<?php

declare(strict_types=1);

namespace App\Import;

use App\Report\ReportSummary;
use App\Support\MerlinDate;

/**
 * Fachliche/technische Plausibilitaetspruefung vor dem Speichern.
 * Es erfolgt keine medizinische Bewertung der Werte.
 */
final class ImportValidator
{
    /** Spaltenlaengen der Stammdaten-/Suchspalten (Zeichen). */
    public const array FIELD_LIMITS = [
        'patient_name' => 512,
        'patient_identifier' => 191,
        'patient_dob' => 64,
        'device_manufacturer' => 255,
        'device_model_name' => 255,
        'device_model_number' => 191,
        'device_serial' => 191,
        'device_implant_date' => 64,
        'session_timestamp' => 64,
        'interrogation_timestamp' => 64,
    ];

    public const array LEAD_FIELD_LIMITS = [
        'chamber_source' => 255,
        'manufacturer' => 255,
        'model_label' => 255,
        'model_number' => 255,
        'serial_number' => 191,
        'lead_type' => 255,
        'implant_date' => 64,
    ];

    public function validate(ParseResult $result, ReportSummary $summary): ValidationResult
    {
        $blocking = [];
        $warnings = [];

        foreach ($result->errors() as $issue) {
            if ($issue->position === null) {
                $blocking[] = $issue->message;
            }
        }
        if ($result->validRecordCount() === 0 && $blocking === []) {
            $blocking[] = 'Die Datei enthaelt keine gueltigen Datensaetze.';
        }

        $seen = [];
        foreach ($result->records as $record) {
            if (isset($seen[$record->parameterId])) {
                $warnings[] = ImportIssue::warning(
                    'duplicate_parameter_id',
                    sprintf('Parameter-ID %s ist mehrfach vorhanden (erstmals Position %d); alle Vorkommen werden gespeichert.', $record->parameterId, $seen[$record->parameterId]),
                    $record->position,
                    $record->rawRecord,
                );
                continue;
            }
            $seen[$record->parameterId] = $record->position;
        }

        if ($result->validRecordCount() > 0) {
            if ($summary->nonEmpty('device_serial') === null) {
                $warnings[] = ImportIssue::warning('missing_device_serial', 'Keine Geraete-Seriennummer in der Datei; der Bericht wird ohne Geraetezuordnung gespeichert.');
            }
            if ($summary->nonEmpty('patient_identifier') === null && $summary->nonEmpty('patient_name') === null) {
                $warnings[] = ImportIssue::warning('missing_patient', 'Weder Patient-ID noch Patientenname vorhanden; der Bericht wird ohne Patientenzuordnung gespeichert.');
            }
            foreach (['patient_dob' => 'Geburtsdatum', 'device_implant_date' => 'Implantationsdatum', 'session_timestamp' => 'Sitzungszeitpunkt', 'interrogation_timestamp' => 'Abfragezeitpunkt'] as $key => $label) {
                $value = $summary->nonEmpty($key);
                if ($value !== null && MerlinDate::parse($value) === null) {
                    $warnings[] = ImportIssue::warning('unparsed_date', sprintf('%s "%s" hat kein erkanntes Datumsformat; der Originalwert wird gespeichert.', $label, $value));
                }
            }
        }

        foreach (self::FIELD_LIMITS as $key => $limit) {
            $value = $summary->value($key);
            if ($value !== null && mb_strlen($value) > $limit) {
                $blocking[] = sprintf('Kopfdatenfeld "%s" ist laenger als %d Zeichen.', $key, $limit);
            }
        }
        foreach ($summary->leads as $lead) {
            foreach (self::LEAD_FIELD_LIMITS as $key => $limit) {
                if ($lead[$key] !== null && mb_strlen((string) $lead[$key]) > $limit) {
                    $blocking[] = sprintf('Sondenfeld "%s" ist laenger als %d Zeichen.', $key, $limit);
                }
            }
        }

        return new ValidationResult($blocking, $warnings);
    }
}
