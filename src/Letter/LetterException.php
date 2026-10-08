<?php

declare(strict_types=1);

namespace App\Letter;

use RuntimeException;

/**
 * Fachlicher Fehler im Brief-Assistenten mit Feldfehlern fuer die Anzeige.
 * Die Meldungen enthalten ausschliesslich Feldnamen und Regeln, niemals Patientendaten.
 */
final class LetterException extends RuntimeException
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
        return new self('Bitte die markierten Angaben pruefen.', $fieldErrors);
    }

    public static function rule(string $field, string $message): self
    {
        return new self('Bitte die markierten Angaben pruefen.', [$field => $message]);
    }

    /**
     * @return array<string, string>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
