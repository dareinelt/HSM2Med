<?php

declare(strict_types=1);

namespace App\Letter;

/**
 * Gepruefte und normalisierte Eingaben des Brief-Assistenten.
 *
 * Der Brief entsteht ausschliesslich aus vorhandenen Daten: Patient (Pflicht), optional ein
 * Bericht (Befundteil) sowie die aktuellen Fassungen der Bausteine. Es gibt deshalb keine
 * Freitextfelder; geprueft werden Auswahl und die beiden Bestaetigungen vor dem Erzeugen.
 */
final class LetterInput
{
    private function __construct(
        public readonly int $patientId,
        public readonly ?int $reportId,
        public readonly bool $confirmData,
        public readonly bool $confirmLetter,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @throws LetterException bei fehlenden oder ungueltigen Angaben
     */
    public static function fromPost(array $post): self
    {
        $errors = [];
        $int = static function (string $key) use ($post): ?int {
            $value = $post[$key] ?? '';
            $value = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
            if ($value === '' || preg_match('/^\d{1,19}$/', $value) !== 1) {
                return null;
            }
            return (int) $value;
        };

        $patientId = $int('patient_id');
        if ($patientId === null || $patientId <= 0) {
            $errors['patient_id'] = 'Bitte den Patienten auswählen, für den der Brief erstellt wird.';
        }
        $reportId = $int('report_id');

        if ($errors !== []) {
            throw LetterException::validation($errors);
        }

        return new self(
            (int) $patientId,
            $reportId !== null && $reportId > 0 ? $reportId : null,
            ($post['confirm_data'] ?? '') === '1',
            ($post['confirm_letter'] ?? '') === '1',
        );
    }

    /**
     * Auswahlzustand fuer die erneute Anzeige nach einem Fehler.
     *
     * @return array{patient_id: int, report_id: ?int, confirm_data: bool, confirm_letter: bool}
     */
    public function selection(): array
    {
        return [
            'patient_id' => $this->patientId,
            'report_id' => $this->reportId,
            'confirm_data' => $this->confirmData,
            'confirm_letter' => $this->confirmLetter,
        ];
    }
}
