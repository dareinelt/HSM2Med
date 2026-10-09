<?php

declare(strict_types=1);

namespace App\PatientCard;

use DateTimeImmutable;

/**
 * Erfundene Beispieldaten fuer die Live-Vorschau einer Ausweisvorlage im Vorlageneditor.
 * Enthaelt keine Patientendaten; Name des Zentrums und Anschrift stammen aus den aktuellen
 * Ausweis-Stammdaten, damit Kopf- und Fusszeile realistisch erscheinen.
 *
 * Der Aufbau entspricht exakt PatientCardService::snapshot(), damit der PDF-Generator die
 * Vorschau mit demselben Code erzeugt wie den spaeteren Ausweis.
 */
final class PatientCardSample
{
    /**
     * @param array<string, mixed> $settings aktuelle Ausweis-Stammdaten (patient_card_settings)
     * @param array<string, mixed> $template Fassung der Ausweisvorlage (wird im Beispiel eingefroren)
     * @return array<string, mixed>
     */
    public static function snapshot(array $settings, DateTimeImmutable $generatedAt, array $template): array
    {
        $stamp = $generatedAt->format('Y-m-d H:i:s');
        $date = $generatedAt->format('Y-m-d');
        $display = $generatedAt->format('d.m.Y');
        $center = trim((string) ($settings['center_name'] ?? ''));
        $columns = [
            ['report_id' => 3, 'date' => $date, 'date_display' => $display, 'current' => true],
            ['report_id' => 2, 'date' => '2026-04-07', 'date_display' => '07.04.2026', 'current' => false],
            ['report_id' => 1, 'date' => '2025-10-07', 'date_display' => '07.10.2025', 'current' => false],
        ];

        return [
            'card_version' => PatientCardService::CARD_VERSION,
            'patient_card_version' => PatientCardService::PATIENT_CARD_VERSION,
            'generated_at' => $stamp,
            'sequence_no' => 1,
            'card_no' => 1,
            'settings_version_id' => 0,
            'template' => [
                'id' => (int) ($template['id'] ?? 0),
                'version_no' => (int) ($template['version_no'] ?? 0),
                'name' => (string) ($template['name'] ?? PatientCardTemplate::LABEL),
                'content' => PatientCardTemplate::normalize($template['content'] ?? null),
            ],
            'settings' => [
                'center_name' => $center === '' ? 'Nachsorgezentrum (Name in den Stammdaten hinterlegen)' : $center,
                'center_address' => trim((string) ($settings['center_address'] ?? '')),
                'notice_text' => (string) ($settings['notice_text'] ?? ''),
                'flight_notice_de' => (string) ($settings['flight_notice_de'] ?? ''),
                'flight_notice_en' => (string) ($settings['flight_notice_en'] ?? ''),
                'logo_sha256' => '',
                'logo_filename' => '',
            ],
            'patient' => [
                'last_name' => 'MUSTERMANN',
                'first_name' => 'ERIKA',
                'patient_name' => 'MUSTERMANN, ERIKA',
                'date_of_birth' => '1950-04-12',
                'date_of_birth_display' => '12.04.1950',
                'date_of_birth_raw' => '04/12/1950 00:00:00',
                'patient_identifier' => 'BEISPIEL-001',
                'street' => 'Musterstraße 1',
                'postal_code' => '12345',
                'city' => 'Beispielstadt',
                'phone' => '01234 567890',
                'indication' => 'Beispielindikation',
            ],
            'device' => [
                'manufacturer' => 'Beispielhersteller',
                'model_name' => 'Beispielgerät DR',
                'model_number' => '1234',
                'serial_number' => 'BEISPIEL-5809481',
                'implant_date' => '2024-06-18',
                'implant_date_display' => '18.06.2024',
                'implant_location' => 'links pektoral',
                'mrt_compatibility' => 'MRT-bedingt tauglich',
                'mrt_compatibility_note' => 'Beispielangabe zur MRT-Tauglichkeit.',
                'mode' => 'DDD',
                'base_rate' => '60',
            ],
            'leads' => [
                [
                    'chamber_label' => 'Ventrikel',
                    'manufacturer' => 'Beispielhersteller',
                    'model_number' => 'Beispielsonde V',
                    'model_label' => 'RV Pace/Sense Lead',
                    'serial_number' => 'BEISPIEL-EEM126412',
                    'lead_type' => 'Bipolar',
                    'implant_date_display' => '18.06.2024',
                ],
                [
                    'chamber_label' => 'Atrium',
                    'manufacturer' => 'Beispielhersteller',
                    'model_number' => 'Beispielsonde A',
                    'model_label' => 'Atrial Lead',
                    'serial_number' => 'BEISPIEL-EEL193668',
                    'lead_type' => 'Bipolar',
                    'implant_date_display' => '18.06.2024',
                ],
            ],
            'emergency_contact' => [
                'name' => 'Angehörige Beispielperson',
                'phone' => '0170 1234567',
            ],
            'physician' => [
                'name' => 'Dr. med. Beispieldoktor',
                'practice' => 'Gemeinschaftspraxis am Markt',
                'postal_code' => '12345',
                'city' => 'Beispielstadt',
                'phone' => '01234 11111',
            ],
            'follow_up' => [
                'report_id' => 3,
                'report_label' => 'Bericht Nr. 3 vom ' . $display,
                'report_date' => $date,
                'report_date_display' => $display,
                'report_filename' => 'MERLIN__ANN_BEISPIEL.log',
                'next_control_date' => '2027-04-07',
                'next_control_display' => '07.04.2027',
                'control_physician' => 'Dr. med. Beispieldoktor',
            ],
            'history' => [
                [
                    'report_id' => 3,
                    'date' => $date,
                    'date_display' => $display,
                    'report_label' => 'Bericht Nr. 3 (aktuelle Untersuchung)',
                    'physician' => 'Dr. med. Beispieldoktor',
                    'center' => $center,
                    'filename' => 'MERLIN__ANN_BEISPIEL.log',
                ],
                [
                    'report_id' => 2,
                    'date' => '2026-04-07',
                    'date_display' => '07.04.2026',
                    'report_label' => 'Bericht Nr. 2',
                    'physician' => 'Dr. med. Beispieldoktor',
                    'center' => $center,
                    'filename' => 'MERLIN__ANN_BEISPIEL_2026-04.log',
                ],
                [
                    'report_id' => 1,
                    'date' => '2025-10-07',
                    'date_display' => '07.10.2025',
                    'report_label' => 'Bericht Nr. 1',
                    'physician' => '',
                    'center' => $center,
                    'filename' => 'MERLIN__ANN_BEISPIEL_2025-10.log',
                ],
            ],
            'measurements' => self::measurements($columns),
            'source' => [
                'report_id' => 3,
                'import_id' => 0,
                'filename' => 'MERLIN__ANN_BEISPIEL.log',
                'session_timestamp' => $stamp,
                'parser_version' => '1.0',
                'mapping_version' => '1.0',
                'report_version' => 1,
            ],
        ];
    }

    /**
     * Platzhalterwerte fuer die Live-Vorschau im Vorlageneditor. Es werden dieselben Werte
     * verwendet wie beim Erzeugen eines Ausweises (PatientCardTemplate::values()), ergaenzt um
     * die Seitenangabe der Fusszeile.
     *
     * @param array<string, mixed> $settings aktuelle Ausweis-Stammdaten (patient_card_settings)
     * @return array<string, string>
     */
    public static function placeholderValues(array $settings): array
    {
        $generatedAt = new DateTimeImmutable();
        $snapshot = self::snapshot($settings, $generatedAt, ['content' => PatientCardTemplate::default()]);

        return PatientCardTemplate::values($snapshot, $generatedAt) + ['page' => '1', 'pages' => '2'];
    }

    /**
     * Messwerttabelle mit erfundenen Werten der aktuellen und zweier frueherer Untersuchungen.
     *
     * @param list<array<string, mixed>> $columns
     * @return array<string, mixed>
     */
    private static function measurements(array $columns): array
    {
        $template = MeasurementTemplate::default(dirname(__DIR__, 2));
        $values = [
            3 => [
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
            2 => [
                'ids' => ['519' => '2.82', '520' => '3.05', '507' => '648', '301' => 'DDD', '302' => '60'],
                'names' => [],
            ],
            1 => [
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
