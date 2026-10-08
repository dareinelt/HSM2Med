<?php

declare(strict_types=1);

/*
 * Vorlage der Messwerttabelle auf Seite 2 des Patientenausweises.
 *
 * Die Tabelle bildet die Vorlage 1:1 ab: eine Beschriftungsspalte und je Spalte eine
 * Untersuchung. Erste Spalte ist die aktuelle Untersuchung (der Bericht, auf dem der
 * Ausweis beruht), danach folgen die sechs letzten frueheren Untersuchungen desselben
 * Patienten (neueste zuerst).
 *
 * "sources" beschreibt, woher der Wert einer Zeile stammt:
 *   'ids'   => Parameter-IDs des Merlin-Quellformats (haben Vorrang)
 *   'names' => Parameterbezeichnungen (exakte Uebereinstimmung, Gross-/Kleinschreibung egal)
 * Mehrere Quellen werden mit 'glue' verbunden, z.B. Amplitude und Pulsbreite zu "0.5/0.4".
 *
 * Zeilen ohne Quelle und Zellen ohne Wert in einer Untersuchung bleiben leer. Die Vorlage
 * wird dadurch 1:1 abgebildet, ohne Werte zu erfinden. Neue Zuordnungen koennen hier
 * zentral ergaenzt werden; bereits erzeugte Ausweise bleiben unveraendert, weil die
 * aufgeloeste Tabelle im Snapshot des Ausweises gespeichert wird.
 *
 * Bei inhaltlichen Aenderungen "version" erhoehen.
 */
return [
    'version' => '1.0.0',

    // Anzahl der Spalten: aktuelle Untersuchung + sechs fruehere Untersuchungen.
    'columns' => 7,

    'sections' => [
        [
            'label' => 'Messungen',
            'groups' => [
                [
                    'label' => 'Batterie',
                    'rows' => [
                        ['label' => 'Status', 'sources' => [['names' => ['Battery Status']]]],
                        ['label' => 'Spannung [V]', 'sources' => [['ids' => ['519'], 'names' => ['Unloaded Battery Voltage']]]],
                        ['label' => 'Strom [µA]', 'sources' => [['ids' => ['520'], 'names' => ['Battery Current']]]],
                        ['label' => 'Impedanz [Ohm]', 'sources' => [['names' => ['Battery Impedance']]]],
                        ['label' => 'Magnetfreq. [1/min]', 'sources' => [['ids' => ['501'], 'names' => ['Magnet Rate']]]],
                        ['label' => 'Ladezeit', 'sources' => [['names' => ['Charge Time', 'Battery Charge Time']]]],
                        ['label' => 'Verbl. Laufzeit', 'sources' => [['ids' => ['533'], 'names' => ['Longevity Estimate']]]],
                    ],
                ],
                [
                    'label' => 'Elektroden',
                    'rows' => [
                        [
                            'label' => 'Impedanz [Ohm]',
                            'chamber' => 'RA',
                            'sources' => [['names' => ['A Pacing Lead Impedance', 'Atrial Pacing Lead Impedance']]],
                        ],
                        [
                            'label' => 'Impedanz [Ohm]',
                            'chamber' => 'RV',
                            'sources' => [['ids' => ['507'], 'names' => ['RV Pacing Lead Impedance']]],
                        ],
                        [
                            'label' => 'Wahrnehmung [mV]',
                            'chamber' => 'RA',
                            'sources' => [['ids' => ['2721'], 'names' => ['Atrial Signal Amplitude']]],
                        ],
                        [
                            'label' => 'Wahrnehmung [mV]',
                            'chamber' => 'RV',
                            'sources' => [['ids' => ['2722'], 'names' => ['Ventricular Signal Amplitude']]],
                        ],
                        [
                            'label' => 'Reizschwelle [V/ms]',
                            'chamber' => 'RA',
                            'glue' => '/',
                            'sources' => [
                                ['names' => ['A. Pulse Amp Decrement Capture Test: Capture Threshold (Pulse Amp)']],
                                ['names' => ['A. Pulse Amp Decrement Capture Test: Test Pulse Width']],
                            ],
                        ],
                        [
                            'label' => 'Reizschwelle [V/ms]',
                            'chamber' => 'RV',
                            'glue' => '/',
                            'sources' => [
                                ['ids' => ['1606'], 'names' => ['RV. Capture Test Threshold Amplitude']],
                                ['ids' => ['1607'], 'names' => ['RV. Capture Test Pulse Width']],
                            ],
                        ],
                        ['label' => 'Sonstige Messungen', 'sources' => []],
                    ],
                ],
            ],
        ],
        [
            'label' => 'Programmierung',
            'groups' => [
                [
                    'label' => 'Bradykardie',
                    'rows' => [
                        ['label' => 'Programmierung', 'sources' => []],
                        ['label' => 'Betriebsart', 'sources' => [['ids' => ['301'], 'names' => ['Mode']]]],
                        ['label' => 'Untere Grenzfrequenz [1/min]', 'sources' => [['ids' => ['302'], 'names' => ['Base Rate']]]],
                        ['label' => 'Hysteresefrequenz [1/min]', 'sources' => [['ids' => ['303'], 'names' => ['Hysteresis Rate']]]],
                        ['label' => 'Max. Synch.freq. [1/min]', 'sources' => [['ids' => ['2024'], 'names' => ['Maximum Pacing Rate']]]],
                        ['label' => 'Max. Sensor.freq. [1/min]', 'sources' => [['ids' => ['406'], 'names' => ['Maximum Sensor Rate']]]],
                        ['label' => 'PMT-Intervention', 'sources' => [['names' => ['PMT Intervention', 'PMT Response']]]],
                        ['label' => 'R-Funktion', 'sources' => [['names' => ['Rate Response', 'Rate Responsive']]]],
                        ['label' => 'Ruhe-/Nachtfunktion', 'sources' => [['ids' => ['354'], 'names' => ['Rest Rate']]]],
                        ['label' => 'VES-Reaktion', 'sources' => [['names' => ['PVC Response', 'VES Response']]]],
                        ['label' => 'Ventrikel Stimulation', 'sources' => []],
                        ['label' => 'VV-Zeit', 'sources' => []],
                    ],
                ],
                [
                    'label' => 'RA',
                    'rows' => [
                        [
                            'label' => 'Output [V/ms]',
                            'glue' => '/',
                            'sources' => [
                                ['names' => ['A Pulse Amplitude', 'Atrial Pulse Amplitude']],
                                ['names' => ['A Pulse Width', 'Atrial Pulse Width']],
                            ],
                        ],
                        ['label' => 'Empfindlichkeit [mV]', 'sources' => [['names' => ['A Sensitivity', 'Atrial Sensitivity']]]],
                        ['label' => 'Wahrnehmungspolarität', 'sources' => [['ids' => ['2904'], 'names' => ['A Sense Polarity']]]],
                        ['label' => 'Stimulationspolarität', 'sources' => [['ids' => ['2906'], 'names' => ['A Pace Polarity']]]],
                        ['label' => 'Ausblendzeit [ms]', 'sources' => [['names' => ['A Blanking', 'Atrial Blanking']]]],
                        ['label' => 'Refraktärzeit [ms]', 'sources' => [['names' => ['A Refractory', 'Atrial Refractory']]]],
                        ['label' => 'Refraktärzeit (PVARP) [ms]', 'sources' => [['ids' => ['390'], 'names' => ['Shortest PVARP/VREF']]]],
                    ],
                ],
                [
                    'label' => 'RV',
                    'rows' => [
                        [
                            'label' => 'Output [V/ms]',
                            'glue' => '/',
                            'sources' => [
                                ['ids' => ['306'], 'names' => ['RV Pulse Amplitude']],
                                ['ids' => ['305'], 'names' => ['RV Pulse Width']],
                            ],
                        ],
                        ['label' => 'Empfindlichkeit [mV]', 'sources' => [['ids' => ['308'], 'names' => ['Ventricular Sensitivity']]]],
                        ['label' => 'Wahrnehmungspolarität', 'sources' => [['ids' => ['307'], 'names' => ['Ventricular Sense Configuration']]]],
                        ['label' => 'Stimulationspolarität', 'sources' => [['ids' => ['2008'], 'names' => ['RV Pulse Configuration']]]],
                        ['label' => 'Ausblendzeit [ms]', 'sources' => [['names' => ['Ventricular Blanking', 'RV Blanking']]]],
                        ['label' => 'Refraktärzeit [ms]', 'sources' => [['ids' => ['309'], 'names' => ['Ventricular Pace Refractory']]]],
                    ],
                ],
                [
                    'label' => 'AV',
                    'rows' => [
                        ['label' => 'Stim. AV-Intervall [ms]', 'sources' => [['names' => ['Paced AV Delay']]]],
                        ['label' => 'Wahr. AV-Intervall [ms]', 'sources' => [['names' => ['Sensed AV Delay']]]],
                        ['label' => 'AV-Suchhysterese', 'sources' => [['ids' => ['370'], 'names' => ['Auto Intrinsic Conduction Search']]]],
                    ],
                ],
            ],
        ],
    ],
];
