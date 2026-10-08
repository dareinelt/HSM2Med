<?php

declare(strict_types=1);

namespace App\Letter;

use DateTimeImmutable;

/**
 * Erfundene Beispieldaten fuer die Vorschau einer Briefvorlage im Vorlageneditor. Enthaelt
 * keine Patientendaten; nur Name, Anschrift, Kontaktangaben und Ruecksendeangaben stammen aus
 * den aktuellen Praxis-Informationen, damit Briefkopf und Ruecksendeangabe realistisch
 * erscheinen.
 */
final class LetterSample
{
    /**
     * @param array<string, mixed> $settings aktuelle Stammdaten (patient_card_settings)
     * @return array<string, mixed>
     */
    public static function snapshot(array $settings, DateTimeImmutable $generatedAt): array
    {
        $stamp = $generatedAt->format('Y-m-d H:i:s');
        $center = trim((string) ($settings['center_name'] ?? ''));
        $record = static fn (string $text, int $version): array => [
            'version' => $version,
            'text' => $text,
            'author_name' => 'Beispiel-Anwender',
            'version_created_at' => $stamp,
            'entries' => [],
        ];
        return [
            'letter_version' => LetterService::LETTER_VERSION,
            'letter_template_version' => '',
            'generated_at' => $stamp,
            'sequence_no' => 1,
            'document' => [
                'document_number' => 'HSM2Med-Brief-' . $generatedAt->format('Ymd') . '-000000-001',
                'letter_date' => $generatedAt->format('Y-m-d'),
                'created_at' => $stamp,
            ],
            'master' => [
                'settings_version_id' => 0,
                'settings_version' => 1,
                'center_name' => $center === '' ? 'Nachsorgezentrum (Name in den Stammdaten hinterlegen)' : $center,
                'center_address' => trim((string) ($settings['center_address'] ?? '')),
                'practice_phone' => trim((string) ($settings['practice_phone'] ?? '')),
                'practice_fax' => trim((string) ($settings['practice_fax'] ?? '')),
                'practice_email' => trim((string) ($settings['practice_email'] ?? '')),
                'practice_website' => trim((string) ($settings['practice_website'] ?? '')),
                'return_name' => trim((string) ($settings['return_name'] ?? '')),
                'return_street' => trim((string) ($settings['return_street'] ?? '')),
                'return_postal_code' => trim((string) ($settings['return_postal_code'] ?? '')),
                'return_city' => trim((string) ($settings['return_city'] ?? '')),
                'logo_sha256' => '',
                'logo_filename' => '',
            ],
            'patient' => [
                'patient_id' => 0,
                'patient_name' => 'MUSTERMANN, ERIKA',
                'last_name' => 'MUSTERMANN',
                'first_name' => 'ERIKA',
                'date_of_birth' => '1950-04-12',
                'patient_identifier' => 'BEISPIEL-001',
                'address' => [
                    'street' => 'Musterstraße 1',
                    'postal_code' => '12345',
                    'city' => 'Beispielstadt',
                    'phone' => '01234 567890',
                ],
            ],
            'anamnesis' => $record('Beispieltext der Anamnese. Hier erscheint der aktuelle Stand des Bausteins „Anamnese“ aus der Patientenakte.', 3),
            'premedication' => [
                'version' => 2,
                'text' => '',
                'author_name' => 'Beispiel-Anwender',
                'version_created_at' => $stamp,
                'entries' => [
                    ['substance' => 'Beispielpräparat A', 'dose' => '5', 'unit' => 'mg', 'schedule' => '1-0-0', 'reason' => 'Beispielgrund', 'from' => '2024-01-01', 'to' => ''],
                    ['substance' => 'Beispielpräparat B', 'dose' => '100', 'unit' => 'mg', 'schedule' => '0-0-1', 'reason' => 'Beispielgrund', 'from' => '', 'to' => ''],
                ],
            ],
            'epicrisis' => $record('Beispieltext der Epikrise. Hier erscheint der aktuelle Stand des Bausteins „Epikrise“.', 1),
            'befund' => $record('Beispieltext des Befunds. Hier erscheint der aktuelle Stand des Bausteins „Befund“ aus der Patientenakte.', 2),
            'device_check' => $record('', 0),
            'report' => [
                'report_id' => 0,
                'meta' => 'Bericht vom ' . $generatedAt->format('d.m.Y') . ' · Beispieldaten',
                'rows' => [
                    ['label' => 'Gerät', 'value' => 'Beispielgerät DR'],
                    ['label' => 'Betriebsart', 'value' => 'DDD'],
                    ['label' => 'Batteriestatus', 'value' => 'OK'],
                ],
                'leads' => [
                    ['label' => 'Sonde RA', 'rows' => [
                        ['label' => 'Impedanz', 'value' => '520 Ohm'],
                        ['label' => 'Reizschwelle', 'value' => '0,75 V / 0,4 ms'],
                    ]],
                ],
                'groups' => [],
            ],
            'appendix' => [
                'device_type_label' => 'Herzschrittmacher',
                'sections' => [
                    ['key' => 'basis', 'label' => 'Grundeinstellungen', 'rows' => [
                        ['label' => 'Betriebsart', 'value' => 'DDD'],
                        ['label' => 'Grundfrequenz', 'value' => '60/min'],
                        ['label' => 'Maximale Synchronfrequenz', 'value' => '130/min'],
                    ]],
                ],
                'notes' => 'Beispielbemerkung zur Abfrage.',
                'filled' => 3,
                'mrt_label' => 'MRT-Tauglichkeit: Beispielangabe',
            ],
            'mrt' => ['available' => false],
            'source' => ['patient_id' => 0, 'report_id' => null, 'settings_version_id' => 0, 'card_id' => null, 'record_versions' => []],
        ];
    }

    /**
     * Beispiel-Empfaenger einer Empfaengerart mit Anschrift und Anrede aus erfundenen
     * Stammdaten; zeigt im Editor, wie Anschriftfeld und Anrede im Brief erscheinen.
     *
     * @return array<string, mixed> Snapshot-Teil „recipient"
     */
    public static function recipient(string $type): array
    {
        $patient = ['last_name' => 'MUSTERMANN', 'first_name' => 'ERIKA', 'patient_name' => 'MUSTERMANN, ERIKA'];
        $master = [
            'street' => 'Musterstraße 1',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'salutation' => LetterSalutation::FRAU,
            'physician_name' => 'Dr. med. Beispieldoktor',
            'physician_practice' => 'Praxis am Markt',
            'physician_street' => 'Marktplatz 2',
            'physician_postal_code' => '12345',
            'physician_city' => 'Beispielstadt',
            'physician_salutation' => LetterSalutation::KOLLEGE,
            'referrer_name' => 'Dr. med. Beispieldoktorin',
            'referrer_practice' => 'Klinik für Beispielmedizin',
            'referrer_street' => 'Klinikstraße 3',
            'referrer_postal_code' => '12345',
            'referrer_city' => 'Beispielstadt',
            'referrer_salutation' => LetterSalutation::KOLLEGIN,
        ];
        return LetterRecipient::snapshotPart(LetterRecipient::resolve($type, $patient, $master));
    }
}
