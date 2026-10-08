<?php

declare(strict_types=1);

namespace App\Patient;

/**
 * Der Patientenvorgang ist fuehrend: Der aktive Patient wird je Sitzung gehalten.
 *
 * Ohne aktiven Patienten lassen sich die patientenbezogenen Vorgaenge (Import,
 * Patientenausweis, Brief) nicht starten. Das Anlegen eines Patienten waehlt ihn
 * automatisch als aktiven Patienten. Die Auswahl wird nie stillschweigend auf einen
 * anderen Patienten umgestellt.
 */
final class ActivePatient
{
    private const string SESSION_KEY = 'active_patient_id';

    public function id(): ?int
    {
        $value = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        return null;
    }

    public function isSelected(): bool
    {
        return $this->id() !== null;
    }

    public function select(int $patientId): void
    {
        if ($patientId <= 0) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = $patientId;
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Der aktive Patient, sofern er noch existiert. Eine veraltete Auswahl wird verworfen.
     *
     * @return array<string, mixed>|null
     */
    public function patient(PatientService $patients): ?array
    {
        $id = $this->id();
        if ($id === null) {
            return null;
        }
        $patient = $patients->patient($id);
        if ($patient === null) {
            $this->clear();
        }
        return $patient;
    }

    /**
     * Kurzangaben fuer Titel- und Statusleiste.
     *
     * @return array{id:int,name:string,birth:string,identifier:string}|null
     */
    public function summary(PatientService $patients): ?array
    {
        $patient = $this->patient($patients);
        if ($patient === null) {
            return null;
        }
        return [
            'id' => (int) $patient['id'],
            'name' => (string) $patient['patient_name'],
            'birth' => (string) ($patient['date_of_birth'] ?? ''),
            'identifier' => (string) ($patient['patient_identifier'] ?? ''),
        ];
    }
}
