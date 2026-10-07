<?php

declare(strict_types=1);

namespace App\PatientCard;

/**
 * Normalisierung von Patientennamen und Bildung des Identitaetsschluessels.
 *
 * Der Identitaetsschluessel entspricht exakt dem generierten Spaltenwert
 * patients.identity_key: LOWER(TRIM(Nachname)) | LOWER(TRIM(Vorname)) | JJJJ-MM-TT.
 * Die Identitaet eines Patienten ist damit Nachname + Vorname + Geburtsdatum;
 * Patienten-ID, Seriennummer oder Berichts-ID sind nur Zusatzangaben.
 */
final class PatientName
{
    /**
     * Trimmt, entfernt geschuetzte Leerzeichen und reduziert Mehrfachleerzeichen.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));
        return $value === '' ? null : $value;
    }

    /**
     * Zerlegt "NACHNAME, VORNAME" (Merlin-Export) fehlertolerant.
     * Ohne Komma gilt der gesamte Wert als Nachname; der Vorname bleibt dann leer
     * und muss im Assistenten ergaenzt werden.
     *
     * @return array{last: ?string, first: ?string}
     */
    public static function split(?string $patientName): array
    {
        $name = self::normalize($patientName);
        if ($name === null) {
            return ['last' => null, 'first' => null];
        }
        if (str_contains($name, ',')) {
            [$last, $first] = explode(',', $name, 2);
            return ['last' => self::normalize($last), 'first' => self::normalize($first)];
        }
        return ['last' => $name, 'first' => null];
    }

    /**
     * Anzeigeform "NACHNAME, VORNAME" wie im Merlin-Export.
     */
    public static function display(?string $lastName, ?string $firstName): string
    {
        $last = self::normalize($lastName);
        $first = self::normalize($firstName);
        if ($last === null) {
            return $first ?? '';
        }
        return $first === null ? $last : $last . ', ' . $first;
    }

    /**
     * Identitaetsschluessel oder null, wenn eine der drei Angaben fehlt.
     */
    public static function identityKey(?string $lastName, ?string $firstName, ?string $dateOfBirth): ?string
    {
        $last = self::normalize($lastName);
        $first = self::normalize($firstName);
        $dob = self::normalize($dateOfBirth);
        if ($last === null || $first === null || $dob === null) {
            return null;
        }
        return mb_strtolower($last, 'UTF-8') . '|' . mb_strtolower($first, 'UTF-8') . '|' . $dob;
    }
}
