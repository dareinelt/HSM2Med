<?php

declare(strict_types=1);

namespace App\Patient;

use RuntimeException;

/**
 * Fachlicher Fehler der Patientenakte mit Feldfehlern fuer die Anzeige.
 * Die Meldungen enthalten ausschliesslich Feldnamen und Regeln, niemals Patientendaten.
 */
final class PatientException extends RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors Feldname => Meldung
     */
    public function __construct(string $message, private readonly array $fieldErrors = [])
    {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    public static function validation(array $fieldErrors): self
    {
        return new self('Bitte die markierten Angaben prüfen.', $fieldErrors);
    }

    public static function rule(string $field, string $message): self
    {
        return new self('Bitte die markierten Angaben prüfen.', [$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
