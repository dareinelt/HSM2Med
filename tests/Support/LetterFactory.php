<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Baut einen vollstaendigen Brief-Snapshot (Struktur aus LetterService::snapshot()), damit der
 * PDF-Generator ohne Datenbank getestet werden kann.
 */
final class LetterFactory
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function snapshot(array $overrides = []): array
    {
        return self::merge(self::base(), $overrides);
    }

    /**
     * Wie array_replace_recursive, aber leere Arrays und Listen ersetzen den Bestand
     * (array_replace_recursive wuerde z.B. 'sections' => [] ignorieren).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && $value !== []
                && !array_is_list($value)
                && isset($base[$key])
                && is_array($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }

    /**
     * Anhangstabelle einer Abfrage mit den im Wunschkatalog genannten Abschnitten.
     *
     * @return array<string, mixed>
     */
    public static function appendix(array $overrides = []): array
    {
        return self::merge([
            'present' => true,
            'device_type' => 'dual_chamber_pm',
            'device_type_label' => 'Zweikammer-Schrittmacher',
            'sections' => [
                [
                    'key' => 'device',
                    'label' => 'Gerät',
                    'rows' => [
                        ['label' => 'Hersteller', 'value' => 'Beispielhersteller'],
                        ['label' => 'Modell', 'value' => 'PM-2000'],
                        ['label' => 'MRT-Tauglichkeit (aus Patientenausweis Nr. 1)', 'value' => 'MRT-bedingt tauglich'],
                    ],
                ],
                [
                    'key' => 'battery',
                    'label' => 'Batterie',
                    'rows' => [
                        ['label' => 'Status', 'value' => 'ERI – Austausch empfohlen'],
                        ['label' => 'Verbleibende Laufzeit', 'value' => '6 Monate'],
                        ['label' => 'Magnetfrequenz', 'value' => '98/min'],
                    ],
                ],
                [
                    'key' => 'measurements',
                    'label' => 'Messdaten',
                    'rows' => [
                        ['label' => 'Sonde 1 – Impedanz', 'value' => '512 Ohm'],
                        ['label' => 'Sonde 1 – Wahrnehmung', 'value' => '3,2 mV'],
                        ['label' => 'Sonde 1 – Reizschwelle', 'value' => '0,5 V bei 0,4 ms'],
                        ['label' => 'Sonde 2 – Impedanz', 'value' => '640 Ohm'],
                    ],
                ],
                [
                    'key' => 'bradycardia',
                    'label' => 'Programmierung – Bradykardie',
                    'rows' => [
                        ['label' => 'Betriebsart', 'value' => 'DDD'],
                        ['label' => 'Untere Grenzfrequenz', 'value' => '60/min'],
                        ['label' => 'Hysteresefrequenz', 'value' => '50/min'],
                        ['label' => 'Max. Synchronfrequenz', 'value' => '130/min'],
                        ['label' => 'Max. Sensorfrequenz', 'value' => '120/min'],
                        ['label' => 'PMT-Intervention', 'value' => 'ein'],
                        ['label' => 'R-Funktion', 'value' => 'aus'],
                    ],
                ],
                [
                    'key' => 'ra',
                    'label' => 'Vorhofsonde (RA)',
                    'rows' => [
                        ['label' => 'Output', 'value' => '3,0 V bei 0,4 ms'],
                        ['label' => 'Empfindlichkeit', 'value' => '0,5 mV'],
                        ['label' => 'Wahrnehmungspolarität', 'value' => 'bipolar'],
                        ['label' => 'Stimulationspolarität', 'value' => 'bipolar'],
                        ['label' => 'Ausblendzeit', 'value' => '28 ms'],
                        ['label' => 'Refraktärzeit', 'value' => '200 ms'],
                        ['label' => 'Refraktärzeit (PVARP)', 'value' => '250 ms'],
                    ],
                ],
                [
                    'key' => 'rv',
                    'label' => 'Ventrikelsonde (RV)',
                    'rows' => [
                        ['label' => 'Output', 'value' => '3,5 V bei 0,4 ms'],
                        ['label' => 'Empfindlichkeit', 'value' => '2,0 mV'],
                        ['label' => 'Refraktärzeit', 'value' => '230 ms'],
                    ],
                ],
                [
                    'key' => 'av',
                    'label' => 'AV-Zeiten',
                    'rows' => [
                        ['label' => 'Stimuliertes AV-Intervall', 'value' => '180 ms'],
                        ['label' => 'Wahrgenommenes AV-Intervall', 'value' => '150 ms'],
                        ['label' => 'AV-Suchhysterese', 'value' => 'ein'],
                        ['label' => 'ModeSwitch Betriebsart', 'value' => 'DDI'],
                        ['label' => 'ModeSwitch Frequenz', 'value' => '170/min'],
                    ],
                ],
            ],
            'notes' => "Kontrolle in 3 Monaten.\nSondenmessung ohne Auffälligkeit.",
            'filled' => 31,
            'mrt_label' => 'MRT-Tauglichkeit aus Patientenausweis Nr. 1',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'letter_version' => 1,
            'letter_template_version' => '1.0',
            'generated_at' => '2026-10-07 08:00:00',
            'sequence_no' => 1,
            'document' => [
                'document_number' => 'BRIEF-0001-2026-0001',
                'letter_date' => '2026-10-07',
                'created_at' => '2026-10-07 08:00:00',
            ],
            'master' => [
                'settings_version_id' => 1,
                'settings_version' => 1,
                'center_name' => 'Nachsorgezentrum Beispielstadt',
                'center_address' => "Musterweg 5\n12345 Beispielstadt\nTelefon 01234/56789",
                'logo_sha256' => '',
                'logo_filename' => '',
            ],
            'patient' => [
                'patient_id' => 1,
                'patient_name' => 'LASTNAME, FIRSTNAME',
                'last_name' => 'LASTNAME',
                'first_name' => 'FIRSTNAME',
                'date_of_birth' => '1938-10-21',
                'patient_identifier' => '10358141',
                'address' => [
                    'street' => 'Musterstraße 12',
                    'postal_code' => '12345',
                    'city' => 'Beispielstadt',
                    'phone' => '01234/56789',
                ],
            ],
            'anamnesis' => [
                'present' => true,
                'record_id' => 1,
                'version' => 3,
                'version_created_at' => '2026-10-01 09:30:00',
                'author_name' => 'Dr. med. Beispiel',
                'text' => 'Seit 2019 bekanntes Sick-Sinus-Syndrom mit Synkopen. Keine Herzoperationen.',
                'entries' => [],
            ],
            'premedication' => [
                'present' => true,
                'record_id' => 2,
                'version' => 2,
                'version_created_at' => '2026-10-02 10:00:00',
                'author_name' => 'Dr. med. Beispiel',
                'text' => '',
                'entries' => [
                    ['substance' => 'Metoprolol', 'dose' => '47,5', 'unit' => 'mg', 'schedule' => '1-0-0', 'from' => '2024-01-01', 'to' => ''],
                    ['substance' => 'Ramipril', 'dose' => '5', 'unit' => 'mg', 'schedule' => '1-0-0', 'from' => '2023-05-01', 'to' => ''],
                ],
            ],
            'epicrisis' => [
                'present' => true,
                'record_id' => 3,
                'version' => 1,
                'version_created_at' => '2026-10-03 11:15:00',
                'author_name' => 'Dr. med. Beispiel',
                'text' => 'Kontrollierte Abfrage im Rahmen der Nachsorge. Keine Sondenauffälligkeit.',
                'entries' => [],
            ],
            'device_check' => [
                'present' => true,
                'record_id' => 4,
                'version' => 5,
                'version_created_at' => '2026-10-06 08:45:00',
                'author_name' => 'Dr. med. Beispiel',
                'text' => "Abfrage: Zweikammer-Schrittmacher\nBatterie: Status ERI",
                'entries' => [],
            ],
            'report' => [
                'report_id' => 1,
                'report_version' => 3,
                'meta' => 'Bericht Nr. 1 vom 07.10.2026 · Sitzung 07.10.2026 07:03:24 · Datei MERLIN__ANN_5809481.log',
                'rows' => [
                    ['label' => 'Hersteller', 'value' => 'Beispielhersteller'],
                    ['label' => 'Modell', 'value' => 'PM-2000'],
                    ['label' => 'Seriennummer', 'value' => 'SN-123456'],
                ],
                'leads' => [
                    [
                        'label' => 'Sonde RA',
                        'rows' => [
                            ['label' => 'Modell', 'value' => 'LEAD-RA-1'],
                            ['label' => 'Implantation', 'value' => '01.02.2019'],
                        ],
                    ],
                ],
                'groups' => [
                    [
                        'label' => 'Batterie',
                        'rows' => [
                            ['label' => 'Status', 'value' => 'ERI'],
                            ['label' => 'Verbleibende Laufzeit', 'value' => '6 Monate'],
                        ],
                    ],
                ],
                'filled' => 7,
            ],
            'appendix' => self::appendix(),
            'mrt' => [
                'available' => true,
                'value' => 'MRT-bedingt tauglich',
                'note' => 'Nur mit Auflagen, jährliche Kontrolle der Sonde.',
                'source_label' => 'aus Patientenausweis Nr. 1',
                'card_id' => 1,
                'card_sequence' => 1,
                'card_created_at' => '2026-09-30 12:00:00',
            ],
            'source' => [
                'patient_id' => 1,
                'report_id' => 1,
                'settings_version_id' => 1,
                'card_id' => 1,
                'record_versions' => [
                    'anamnesis' => ['record_id' => 1, 'version' => 3],
                    'premedication' => ['record_id' => 2, 'version' => 2],
                    'epicrisis' => ['record_id' => 3, 'version' => 1],
                    'device_check' => ['record_id' => 4, 'version' => 5],
                ],
            ],
        ];
    }
}
