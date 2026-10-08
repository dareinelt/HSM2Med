<?php

declare(strict_types=1);

/*
 * Vorlage des Aktenbausteins "Schrittmacher-/ICD-Abfrage" (record_type = device_check).
 *
 * Die Vorlage bildet den Wunschkatalog des aerztlichen Dienstes 1:1 ab. Sie bestimmt
 *   * welche Abschnitte und Felder es gibt,
 *   * welcher Geraetetyp welche Abschnitte und Felder sieht ("devices"),
 *   * welche Feldwerte aus dem importierten Bericht vorbelegt werden koennen ("sources",
 *     "from_summary") und welche aus dem neuesten Patientenausweis ("from_card").
 *
 * Feldwerte sind Freitext mit der Einheit in der Beschriftung (z.B. "Output (V/ms)" ->
 * "1,5/0,4"). Es findet keine Zahleninterpretation und keine medizinische Bewertung statt.
 *
 * "devices" nennt die Geraetetypen, fuer die ein Abschnitt oder Feld gilt; fehlt die Angabe,
 * gilt der Eintrag fuer alle Geraetetypen. Nicht zutreffende Werte werden weder gespeichert
 * noch ausgegeben.
 *
 * "sources" loest Werte aus dem Bericht auf (gleiche Regeln wie config/patient_card_measurements.php):
 *   'ids'   => Parameter-IDs des Merlin-Quellformats (haben Vorrang)
 *   'names' => Parameterbezeichnungen (exakte Uebereinstimmung, Gross-/Kleinschreibung egal)
 * Mehrere Quellen werden mit 'glue' verbunden (z.B. Amplitude und Pulsbreite zu "1,5/0,4").
 * "from_summary" liest einen Wert aus der Berichtszusammenfassung ("<Abschnitt>.<Zeile>").
 *
 * Bei inhaltlichen Aenderungen "version" erhoehen. Die Fassung der Vorlage wird im Inhalt
 * jeder Fassung mitgespeichert; bereits gespeicherte Abfragen bleiben dadurch unveraendert.
 */

use App\PatientCard\PatientCardInput;

return [
    'version' => '1.0.0',

    // Geraetetypen in der Reihenfolge des Assistenten/Formulars.
    'device_types' => [
        'pacemaker' => 'Schrittmacher',
        'icd' => 'ICD',
        'crt_p' => 'CRT-P',
        'crt_d' => 'CRT-D',
    ],

    // Grenzen: Felder sind Freitext, deshalb bewusst grosszuegig, aber begrenzt.
    'max_leads' => 12,
    'max_value' => 120,
    'max_notes' => 4000,

    // Wiederholbare Sondenzeilen.
    'lead_fields' => [
        ['key' => 'model', 'label' => 'Modell', 'maxlength' => 128],
        ['key' => 'location', 'label' => 'Lokalisation', 'options' => ['RA', 'RV', 'LV', 'CS', 'andere']],
        ['key' => 'implant_date', 'label' => 'Implantationsdatum', 'type' => 'date'],
        ['key' => 'impedance', 'label' => 'Impedanz (Ohm)'],
        ['key' => 'sensing', 'label' => 'Wahrnehmung (mV)'],
        ['key' => 'threshold', 'label' => 'Reizschwelle (V/ms)'],
        ['key' => 'shock_impedance', 'label' => 'Schockimpedanz (Ohm)', 'devices' => ['icd', 'crt_d']],
    ],

    'sections' => [
        [
            'key' => 'device',
            'label' => 'Gerät',
            'fields' => [
                ['key' => 'manufacturer', 'label' => 'Hersteller', 'from_summary' => 'device.Hersteller'],
                ['key' => 'model', 'label' => 'Modell', 'from_summary' => 'device.Modell'],
                ['key' => 'serial', 'label' => 'Seriennummer', 'from_summary' => 'device.Seriennummer'],
                ['key' => 'implant_date', 'label' => 'Implantationsdatum', 'type' => 'date', 'from_summary' => 'device.Implantation'],
                ['key' => 'mrt_compatibility', 'label' => 'MRT-Tauglichkeit', 'options' => PatientCardInput::MRT_VALUES, 'from_card' => 'mrt_compatibility'],
                ['key' => 'mrt_compatibility_note', 'label' => 'MRT-Tauglichkeit: Zusatzangabe', 'maxlength' => PatientCardInput::MAX_MRT_NOTE_CHARS, 'from_card' => 'mrt_compatibility_note'],
            ],
        ],
        [
            'key' => 'leads',
            'label' => 'Sonden (Elektroden)',
            'repeat' => 'leads',
        ],
        [
            'key' => 'battery',
            'label' => 'Batterie',
            'fields' => [
                ['key' => 'status', 'label' => 'Status', 'sources' => [['names' => ['Battery Status']]]],
                ['key' => 'longevity', 'label' => 'Verbleibende Laufzeit', 'sources' => [['ids' => ['533'], 'names' => ['Longevity Estimate']]]],
                ['key' => 'magnet_rate', 'label' => 'Magnetfrequenz (1/min)', 'sources' => [['ids' => ['501'], 'names' => ['Magnet Rate']]]],
            ],
        ],
        [
            'key' => 'brady',
            'label' => 'Programmierung Bradykardie',
            'fields' => [
                ['key' => 'mode', 'label' => 'Betriebsart', 'sources' => [['ids' => ['301'], 'names' => ['Mode']]]],
                ['key' => 'lower_rate', 'label' => 'Untere Grenzfrequenz (1/min)', 'sources' => [['ids' => ['302'], 'names' => ['Base Rate']]]],
                ['key' => 'hysteresis_rate', 'label' => 'Hysteresefrequenz (1/min)', 'sources' => [['ids' => ['303'], 'names' => ['Hysteresis Rate']]]],
                ['key' => 'max_sync_rate', 'label' => 'max. Synch.frequenz (1/min)', 'sources' => [['ids' => ['2024'], 'names' => ['Maximum Pacing Rate']]]],
                ['key' => 'max_sensor_rate', 'label' => 'max. Sensorfrequenz (1/min)', 'sources' => [['ids' => ['406'], 'names' => ['Maximum Sensor Rate']]]],
                ['key' => 'pmt', 'label' => 'PMT-Intervention', 'sources' => [['names' => ['PMT Intervention', 'PMT Response']]]],
                ['key' => 'rate_response', 'label' => 'R-Funktion', 'sources' => [['names' => ['Rate Response', 'Rate Responsive']]]],
            ],
        ],
        [
            'key' => 'ra',
            'label' => 'RA (Vorhofsonde)',
            'fields' => [
                [
                    'key' => 'output',
                    'label' => 'Output (V/ms)',
                    'glue' => '/',
                    'sources' => [
                        ['names' => ['A Pulse Amplitude', 'Atrial Pulse Amplitude']],
                        ['names' => ['A Pulse Width', 'Atrial Pulse Width']],
                    ],
                ],
                ['key' => 'sensitivity', 'label' => 'Empfindlichkeit (mV)', 'sources' => [['names' => ['A Sensitivity', 'Atrial Sensitivity']]]],
                ['key' => 'sense_polarity', 'label' => 'Wahrnehmungspolarität', 'sources' => [['ids' => ['2904'], 'names' => ['A Sense Polarity']]]],
                ['key' => 'pace_polarity', 'label' => 'Stimulationspolarität', 'sources' => [['ids' => ['2906'], 'names' => ['A Pace Polarity']]]],
                ['key' => 'blanking', 'label' => 'Ausblendzeit (ms)', 'sources' => [['names' => ['A Blanking', 'Atrial Blanking']]]],
                ['key' => 'refractory', 'label' => 'Refraktärzeit (ms)', 'sources' => [['names' => ['A Refractory', 'Atrial Refractory']]]],
                ['key' => 'pvarp', 'label' => 'Refraktärzeit (PVARP) (ms)', 'sources' => [['ids' => ['390'], 'names' => ['Shortest PVARP/VREF']]]],
            ],
        ],
        [
            'key' => 'rv',
            'label' => 'RV (Ventrikelsonde)',
            'fields' => [
                [
                    'key' => 'output',
                    'label' => 'Output (V/ms)',
                    'glue' => '/',
                    'sources' => [
                        ['ids' => ['306'], 'names' => ['RV Pulse Amplitude']],
                        ['ids' => ['305'], 'names' => ['RV Pulse Width']],
                    ],
                ],
                ['key' => 'sensitivity', 'label' => 'Empfindlichkeit (mV)', 'sources' => [['ids' => ['308'], 'names' => ['Ventricular Sensitivity']]]],
                ['key' => 'sense_polarity', 'label' => 'Wahrnehmungspolarität', 'sources' => [['ids' => ['307'], 'names' => ['Ventricular Sense Configuration']]]],
                ['key' => 'pace_polarity', 'label' => 'Stimulationspolarität', 'sources' => [['ids' => ['2008'], 'names' => ['RV Pulse Configuration']]]],
                ['key' => 'blanking', 'label' => 'Ausblendzeit (ms)', 'sources' => [['names' => ['Ventricular Blanking', 'RV Blanking']]]],
                ['key' => 'refractory', 'label' => 'Refraktärzeit (ms)', 'sources' => [['ids' => ['309'], 'names' => ['Ventricular Pace Refractory']]]],
            ],
        ],
        [
            'key' => 'av',
            'label' => 'AV',
            'fields' => [
                ['key' => 'paced_delay', 'label' => 'Stim. AV-Intervall (ms)', 'sources' => [['names' => ['Paced AV Delay']]]],
                ['key' => 'sensed_delay', 'label' => 'wahrg. AV-Intervall (ms)', 'sources' => [['names' => ['Sensed AV Delay']]]],
                ['key' => 'search_hysteresis', 'label' => 'AV-Suchhysterese', 'sources' => [['ids' => ['370'], 'names' => ['Auto Intrinsic Conduction Search']]]],
                ['key' => 'modeswitch_mode', 'label' => 'ModeSwitch Betriebsart'],
                ['key' => 'modeswitch_rate', 'label' => 'ModeSwitch Frequenz (1/min)'],
            ],
        ],
        [
            'key' => 'lv',
            'label' => 'LV (linksventrikuläre Sonde)',
            'devices' => ['crt_p', 'crt_d'],
            'fields' => [
                ['key' => 'sensing', 'label' => 'Wahrnehmung (mV)'],
                ['key' => 'threshold', 'label' => 'Reizschwelle (V/ms)'],
                ['key' => 'impedance', 'label' => 'Impedanz (Ohm)'],
                ['key' => 'output', 'label' => 'Output (V/ms)'],
                ['key' => 'sensitivity', 'label' => 'Empfindlichkeit (mV)'],
                ['key' => 'sense_polarity', 'label' => 'Wahrnehmungspolarität'],
                ['key' => 'pace_polarity', 'label' => 'Stimulationspolarität'],
            ],
        ],
        [
            'key' => 'tachy',
            'label' => 'Tachykardie',
            'devices' => ['icd', 'crt_d'],
            'groups' => [
                [
                    'key' => 'vt1',
                    'label' => 'VT1',
                    'fields' => [
                        ['key' => 'rate', 'label' => 'Erkennung: Frequenz (1/min)'],
                        ['key' => 'cycle_length', 'label' => 'Erkennung: Zykluslänge (ms)'],
                        ['key' => 'therapy', 'label' => 'Therapie: Maßnahmen'],
                    ],
                ],
                [
                    'key' => 'vt2',
                    'label' => 'VT2',
                    'fields' => [
                        ['key' => 'rate', 'label' => 'Erkennung: Frequenz (1/min)'],
                        ['key' => 'cycle_length', 'label' => 'Erkennung: Zykluslänge (ms)'],
                        ['key' => 'therapy', 'label' => 'Therapie: Maßnahmen'],
                    ],
                ],
                [
                    'key' => 'vf',
                    'label' => 'VF',
                    'fields' => [
                        ['key' => 'rate', 'label' => 'Erkennung: Frequenz (1/min)'],
                        ['key' => 'cycle_length', 'label' => 'Erkennung: Zykluslänge (ms)'],
                        ['key' => 'therapy', 'label' => 'Therapie: Maßnahmen'],
                    ],
                ],
            ],
        ],
    ],
];
