<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Art der Patientenzuordnung eines analysierten Berichts.
 */
enum PatientResolutionMode: string
{
    /** Vollstaendige Identitaet in der Datei trifft genau einen vorhandenen Patienten. */
    case IdentitySingle = 'identity_single';

    /** Vollstaendige Identitaet in der Datei trifft mehrere vorhandene Patienten (Benutzerauswahl Pflicht). */
    case IdentityMultiple = 'identity_multiple';

    /** Vollstaendige Identitaet ohne Identitaetstreffer und ohne kollidierende Patienten-ID -> neuer Patient. */
    case NewPatient = 'new_patient';

    /** Vollstaendige Identitaet ohne Identitaetstreffer, aber Patienten-ID gehoert bereits einem anderen Patienten. */
    case IdentifierConflict = 'identifier_conflict';

    /** Teil-Identitaet in der Datei (z. B. fehlendes Geburtsdatum oder fehlender Vorname) -> Benutzerauswahl Pflicht. */
    case IncompleteIdentity = 'incomplete_identity';

    /** Keinerlei Patientendaten in der Datei -> Zuordnung zum aktiven Patienten. */
    case ActivePatientFallback = 'active_patient_fallback';

    /** Keinerlei Patientendaten in der Datei und kein aktiver Patient -> Bericht ohne Patientenzuordnung. */
    case NoPatient = 'no_patient';

    public function requiresDecision(): bool
    {
        return match ($this) {
            self::IdentityMultiple, self::IdentifierConflict, self::IncompleteIdentity => true,
            self::IdentitySingle, self::NewPatient, self::ActivePatientFallback, self::NoPatient => false,
        };
    }
}
