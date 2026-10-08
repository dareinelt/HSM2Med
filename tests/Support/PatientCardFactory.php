<?php

declare(strict_types=1);

namespace Tests\Support;

use App\PatientCard\MeasurementTemplate;

/**
 * Baut einen vollstaendigen Ausweis-Snapshot (Struktur aus PatientCardService::snapshot()),
 * damit der PDF-Generator ohne Datenbank getestet werden kann.
 */
final class PatientCardFactory
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
     * (array_replace_recursive wuerde z.B. 'leads' => [] ignorieren).
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
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return [
            'card_version' => 2,
            'patient_card_version' => '2.0',
            'generated_at' => '2026-10-07 08:00:00',
            'sequence_no' => 1,
            'card_no' => 1,
            'settings_version_id' => 1,
            'settings' => [
                'center_name' => 'Nachsorgezentrum Beispielstadt',
                'center_address' => "Musterweg 5\n12345 Beispielstadt\nTelefon 01234/56789",
                'notice_text' => 'Dieser Ausweis enthält Angaben zum implantierten Schrittmachersystem. Bitte bei jeder Untersuchung vorlegen.',
                'flight_notice_de' => 'Das Gerät kann Metalldetektoren auslösen. Bitte den Ausweis bei der Sicherheitskontrolle vorzeigen.',
                'flight_notice_en' => 'The device may trigger metal detectors. Please show this card at the security checkpoint.',
                'logo_sha256' => '',
                'logo_filename' => '',
            ],
            'patient' => [
                'last_name' => 'LASTNAME',
                'first_name' => 'FIRSTNAME',
                'patient_name' => 'LASTNAME, FIRSTNAME',
                'date_of_birth' => '1938-10-21',
                'date_of_birth_display' => '21.10.1938',
                'date_of_birth_raw' => '10/21/1938 00:00:00',
                'patient_identifier' => '10358141',
                'street' => 'Musterstraße 12',
                'postal_code' => '12345',
                'city' => 'Beispielstadt',
                'phone' => '01234/56789',
                'indication' => 'Bradykardie',
            ],
            'device' => [
                'manufacturer' => 'Abbott',
                'model_name' => 'Endurity Core',
                'model_number' => '2152',
                'serial_number' => '5809481',
                'implant_date' => '2024-06-18',
                'implant_date_display' => '18.06.2024',
                'implant_location' => 'links pektoral',
                'mode' => 'VVI',
                'base_rate' => '60',
            ],
            'leads' => [
                [
                    'chamber_label' => 'Ventrikel',
                    'manufacturer' => 'St. Jude Medical',
                    'model_number' => '2088TC Tendril STS',
                    'model_label' => 'RV Pace/Sense Lead',
                    'serial_number' => 'EEM126412',
                    'lead_type' => 'Bipolar',
                    'implant_date_display' => '18.06.2024',
                ],
                [
                    'chamber_label' => 'Atrium',
                    'manufacturer' => 'St. Jude Medical',
                    'model_number' => '2088TC Tendril STS',
                    'model_label' => 'Atrial Lead',
                    'serial_number' => 'EEL193668',
                    'lead_type' => 'Bipolar',
                    'implant_date_display' => '18.06.2024',
                ],
            ],
            'emergency_contact' => [
                'name' => 'Angehörige Beispielperson',
                'phone' => '0170/1234567',
            ],
            'physician' => [
                'name' => 'Dr. med. Hausarzt',
                'practice' => 'Gemeinschaftspraxis am Markt',
                'postal_code' => '12345',
                'city' => 'Beispielstadt',
                'phone' => '01234/11111',
            ],
            'follow_up' => [
                'report_id' => 2,
                'report_label' => 'Bericht Nr. 2 vom 07.10.2026',
                'report_date' => '2026-10-07',
                'report_date_display' => '07.10.2026',
                'report_filename' => 'MERLIN__ANN_5809481.log',
                'next_control_date' => '2027-04-07',
                'next_control_display' => '07.04.2027',
                'control_physician' => 'Dr. med. Kontrolle',
            ],
            'history' => [
                [
                    'report_id' => 2,
                    'date' => '2026-10-07',
                    'date_display' => '07.10.2026',
                    'report_label' => 'Bericht Nr. 2 (aktuelle Untersuchung)',
                    'physician' => 'Dr. med. Kontrolle',
                    'center' => 'Nachsorgezentrum Beispielstadt',
                    'filename' => 'MERLIN__ANN_5809481.log',
                ],
                [
                    'report_id' => 1,
                    'date' => '2026-04-07',
                    'date_display' => '07.04.2026',
                    'report_label' => 'Bericht Nr. 1',
                    'physician' => 'Dr. med. Kontrolle',
                    'center' => 'Nachsorgezentrum Beispielstadt',
                    'filename' => 'MERLIN__ANN_5809481_2026-04.log',
                ],
                [
                    'report_id' => 3,
                    'date' => '2026-01-15',
                    'date_display' => '15.01.2026',
                    'report_label' => 'Bericht Nr. 3',
                    'physician' => '',
                    'center' => 'Nachsorgezentrum Beispielstadt',
                    'filename' => 'MERLIN__ANN_5809481_2026-01.log',
                ],
            ],
            'measurements' => self::measurements(),
            'source' => [
                'report_id' => 2,
                'import_id' => 2,
                'filename' => 'MERLIN__ANN_5809481.log',
                'session_timestamp' => '2026-10-07 07:03:24',
                'parser_version' => '1.0',
                'mapping_version' => '1.0',
                'report_version' => 1,
            ],
        ];
    }

    /**
     * Messwerttabelle fuer Seite 2: die echte Vorlage (config/patient_card_measurements.php),
     * aufgeloest mit Beispieldaten der aktuellen und zweier frueherer Untersuchungen.
     *
     * @return array<string, mixed>
     */
    private static function measurements(): array
    {
        $template = MeasurementTemplate::default(dirname(__DIR__, 2));
        $columns = [
            ['report_id' => 2, 'date' => '2026-10-07', 'date_display' => '07.10.2026', 'current' => true],
            ['report_id' => 1, 'date' => '2026-04-07', 'date_display' => '07.04.2026', 'current' => false],
            ['report_id' => 3, 'date' => '2026-01-15', 'date_display' => '15.01.2026', 'current' => false],
        ];
        $values = [
            2 => [
                'ids' => [
                    '519' => '2.79', '520' => '3.20', '501' => '90', '533' => '8.4',
                    '507' => '612', '2721' => '3.1', '2722' => '12.4', '1606' => '0.5', '1607' => '0.4',
                    '301' => 'DDD', '302' => '60', '303' => '50', '2024' => '130', '406' => '120',
                    '354' => '50', '2904' => 'Bipolar', '2906' => 'Bipolar', '390' => '250',
                    '306' => '2.5', '305' => '0.4', '308' => '2.0', '307' => 'Bipolar', '2008' => 'Bipolar',
                    '309' => '250', '370' => 'On',
                ],
                'names' => ['battery status' => 'OK'],
            ],
            1 => [
                'ids' => ['519' => '2.82', '520' => '3.05', '507' => '648', '301' => 'DDD', '302' => '60'],
                'names' => [],
            ],
            3 => [
                'ids' => [],
                'names' => ['rv pacing lead impedance' => '701', 'mode' => 'VVI'],
            ],
        ];
        return [
            'template_version' => $template->version(),
            'column_count' => $template->columnCount(),
            'columns' => $columns,
            'sections' => $template->resolve($columns, $values),
        ];
    }
}
