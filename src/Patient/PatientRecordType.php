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
    case DeviceCheck = 'device_check';

    public function label(): string
    {
        return match ($this) {
            self::Anamnesis => 'Anamnese',
            self::Premedication => 'Vormedikation',
            self::Epicrisis => 'Epikrise',
            self::Note => 'Notiz',
            self::DeviceCheck => 'Schrittmacher-/ICD-Abfrage',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Anamnesis => 'Beschwerden, Vorerkrankungen, Implantationsgrund',
            self::Premedication => 'Dauermedikation als Tabelle, Ergänzungen als Freitext',
            self::Epicrisis => 'Zusammenfassung des Verlaufs',
            self::Note => 'Freie Anmerkung zur Akte',
            self::DeviceCheck => 'Gerät, Sonden, Messdaten und Programmierung – Vorlage des ärztlichen Dienstes',
        };
    }

    /**
     * Vormedikation wird als Tabelle erfasst, alle uebrigen Bausteine als Freitext.
     */
    public function isStructured(): bool
    {
        return $this === self::Premedication;
    }

    /**
     * Die Abfrage wird nach der Vorlage config/device_check_template.php erfasst; Aufbau und
     * Geltungsbereich der Felder haengen von der Art des Geraets ab.
     */
    public function isDeviceCheck(): bool
    {
        return $this === self::DeviceCheck;
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
