<?php

declare(strict_types=1);

namespace App\Patient;

/**
 * Bausteintypen der Patientenakte.
 *
 * Die Werte entsprechen exakt der Spalte patient_records.record_type; die Aufzaehlung ist
 * damit die einzige Stelle, an der ein neuer Bausteintyp ergaenzt werden muss.
 */
enum PatientRecordType: string
{
    case Anamnesis = 'anamnesis';
    case Premedication = 'premedication';
    case Epicrisis = 'epicrisis';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Anamnesis => 'Anamnese',
            self::Premedication => 'Vormedikation',
            self::Epicrisis => 'Epikrise',
            self::Note => 'Notiz',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Anamnesis => 'Beschwerden, Vorerkrankungen, Implantationsgrund',
            self::Premedication => 'Dauermedikation als Tabelle, Ergänzungen als Freitext',
            self::Epicrisis => 'Zusammenfassung des Verlaufs',
            self::Note => 'Freie Anmerkung zur Akte',
        };
    }

    /**
     * Vormedikation wird als Tabelle erfasst, alle uebrigen Bausteine als Freitext.
     */
    public function isStructured(): bool
    {
        return $this === self::Premedication;
    }

    public static function fromValue(?string $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
