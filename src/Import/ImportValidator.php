<?php

declare(strict_types=1);

namespace App\Import;

use App\PatientCard\PatientName;
use App\Report\ReportSummary;
use App\Support\SourceDate;

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

    /**
     * @param bool $hasActivePatient Ob ein aktiver Patient den Import kontextualisiert; dann kann
     *                               ein Bericht ohne Patientendaten diesem zugeordnet werden.
     */
    public function validate(ParseResult $result, ReportSummary $summary, bool $hasActivePatient = false): ValidationResult
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
            $identifier = $summary->nonEmpty('patient_identifier');
            $name = $summary->nonEmpty('patient_name');
            $dobRaw = $summary->nonEmpty('patient_dob');
            if ($identifier === null && $name === null && $dobRaw === null) {
                $warnings[] = ImportIssue::warning(
                    'missing_patient',
                    $hasActivePatient
                        ? 'Keine Patientendaten in der Datei; der Bericht wird dem aktiven Patienten zugeordnet.'
                        : 'Keine Patientendaten vorhanden; der Bericht wird ohne Patientenzuordnung gespeichert.',
                );
            } else {
                if ($dobRaw === null) {
                    $warnings[] = ImportIssue::warning(
                        'missing_patient_dob',
                        'Kein Geburtsdatum in der Datei vorhanden.',
                    );
                }
                $parts = PatientName::split($name);
                $dob = SourceDate::parse($dobRaw)?->format('Y-m-d');
                if (PatientName::identityKey($parts['last'], $parts['first'], $dob) === null) {
                    $warnings[] = ImportIssue::warning(
                        'incomplete_patient_identity',
                        'Unvollständige Patientenidentität in der Datei (erforderlich sind Nachname, Vorname und gültiges Geburtsdatum); die Zuordnung muss ausdrücklich bestätigt werden.',
                    );
                }
            }
            foreach (['patient_dob' => 'Geburtsdatum', 'device_implant_date' => 'Implantationsdatum', 'session_timestamp' => 'Sitzungszeitpunkt', 'interrogation_timestamp' => 'Abfragezeitpunkt'] as $key => $label) {
                $value = $summary->nonEmpty($key);
                if ($value !== null && SourceDate::parse($value) === null) {
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
