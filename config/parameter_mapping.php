<?php

declare(strict_types=1);

/*
 * Parameter-Mapping fuer Merlin-Exporte.
 *
 * WICHTIG: Die Schluessel in "by_id" sind Quellformat-Parameter-IDs des Merlin-Exports.
 * Sie sind KEINE IEEE-11073- oder sonstigen Standard-IDs. Eine Standardzuordnung darf nur
 * explizit und dokumentiert unter "standard_codes" erfolgen.
 *
 * Aufloesungsreihenfolge: by_id -> by_name (exakt, ohne Gross-/Kleinschreibung) -> name_patterns
 * -> fallback_category. Kein Parameter wird verworfen.
 *
 * Bei inhaltlichen Aenderungen "version" erhoehen. Bereits importierte Berichte bleiben
 * unveraendert, da Kategorie und Bezeichnung beim Import im Bericht gespeichert werden.
 */
return [
    'version' => '1.0.0',

    'categories' => [
        'patient'    => ['label' => 'Patient', 'sort' => 10],
        'device'     => ['label' => 'Gerät', 'sort' => 20],
        'battery'    => ['label' => 'Batterie', 'sort' => 30],
        'atrium'     => ['label' => 'Atrium', 'sort' => 40],
        'ventricle'  => ['label' => 'Ventrikel / RV', 'sort' => 50],
        'pacing'     => ['label' => 'Stimulation / Programmierung', 'sort' => 60],
        'statistics' => ['label' => 'Pacing-Statistik', 'sort' => 70],
        'arrhythmia' => ['label' => 'Arrhythmie / Ereignisse', 'sort' => 80],
        'alerts'     => ['label' => 'Alarme / Benachrichtigungen', 'sort' => 85],
        'mri'        => ['label' => 'MRI', 'sort' => 90],
        'programmer' => ['label' => 'Programmiergerät / Software', 'sort' => 100],
        'other'      => ['label' => 'Sonstige / Nicht kategorisiert', 'sort' => 999],
    ],

    'fallback_category' => 'other',

    // Explizite Zuordnung ueber die Merlin-Parameter-ID (hat Vorrang).
    // Wert: Kategorie-Schluessel oder ['category' => ..., 'display_name' => ...]
    'by_id' => [
        // Programmiergeraet / Software
        '100' => 'programmer', // Programmer Marketing Name
        '101' => 'programmer', // Programmer Model Number
        '102' => 'programmer', // Programmer Serial Number
        '103' => 'programmer', // Application Software Version Number
        '104' => 'programmer', // Application Build Number
        '106' => 'programmer', // Programmer Language

        // Geraet
        '105' => 'device',     // Session Timestamp
        '200' => 'device',     // Device Model Name
        '201' => 'device',     // Device Model Number
        '202' => 'device',     // Device Serial Number
        '203' => 'device',     // Device Last Interrogation Date and Time
        '2442' => 'device',    // Implant Date: Device

        // Patient
        '204' => 'patient',    // Patient ID
        '2430' => 'patient',   // Patient Name
        '2431' => 'patient',   // Patient Date of Birth
        '2432' => 'patient',   // Follow-up Physician
        '2440' => 'patient',   // Ejection Fraction
        '2441' => 'patient',   // Indications for Implant: List

        // Batterie
        '321' => 'battery',    // Magnet Response
        '501' => 'battery',    // Magnet Rate
        '519' => 'battery',    // Unloaded Battery Voltage
        '520' => 'battery',    // Battery Current
        '533' => 'battery',    // Longevity Estimate

        // Atrium
        '208' => 'atrium',     // Atrial Lead Type
        '2456' => 'atrium',    // Manufacturer: Atrial Lead
        '2457' => 'atrium',    // Model Number: SJM Atrial Lead
        '2459' => 'atrium',    // Implant Date: Atrial Lead
        '2468' => 'atrium',    // Atrial Lead Serial Number
        '2721' => 'atrium',    // Atrial Signal Amplitude
        '2726' => 'atrium',    // Test Polarity of Atrial Signal Amplitude
        '2727' => 'atrium',    // Safety Margin of Atrial Signal Amplitude
        '2904' => 'atrium',    // A Sense Polarity
        '2906' => 'atrium',    // A Pace Polarity

        // Ventrikel / RV
        '211' => 'ventricle',  // RV Lead Type
        '305' => 'ventricle',  // RV Pulse Width
        '306' => 'ventricle',  // RV Pulse Amplitude
        '307' => 'ventricle',  // Ventricular Sense Configuration
        '308' => 'ventricle',  // Ventricular Sensitivity
        '309' => 'ventricle',  // Ventricular Pace Refractory
        '353' => 'ventricle',  // RV AutoCapture
        '507' => 'ventricle',  // RV Pacing Lead Impedance
        '873' => 'ventricle',  // RV Lead Monitoring
        '874' => 'ventricle',  // RV Lead Monitoring: Upper Limit
        '875' => 'ventricle',  // RV Lead Monitoring: Lower Limit
        '1606' => 'ventricle', // RV. Capture Test Threshold Amplitude
        '1607' => 'ventricle', // RV. Capture Test Pulse Width
        '1608' => 'ventricle', // RV. Capture Test Pulse Configuration
        '2004' => 'ventricle', // Ventricular Sensing Chamber
        '2008' => 'ventricle', // RV Pulse Configuration
        '2221' => 'ventricle', // Ventricular Sense Refractory
        '2460' => 'ventricle', // Manufacturer: RV Lead
        '2461' => 'ventricle', // Model Number: SJM RV Pace/Sense Lead
        '2463' => 'ventricle', // Implant Date: RV Lead
        '2470' => 'ventricle', // RV Lead Serial Number
        '2604' => 'ventricle', // V. Pulse Amp Decrement Capture Test: Capture Threshold (Pulse Amp)
        '2605' => 'ventricle', // V. Pulse Amp Decrement Capture Test: Test Pulse Width
        '2722' => 'ventricle', // Ventricular Signal Amplitude
        '2724' => 'ventricle', // Test Polarity of Ventricular Signal Amplitude
        '2725' => 'ventricle', // Safety Margin of Ventricular Signal Amplitude
        '2905' => 'ventricle', // V Sense Polarity
        '2907' => 'ventricle', // V Pace Polarity
        '2913' => 'ventricle', // V. Pulse Amp Decrement Capture Test: Pulse Configuration
        '9035' => 'ventricle', // Ventricular AutoSense

        // Stimulation / Programmierung
        '301' => 'pacing',     // Mode
        '302' => 'pacing',     // Base Rate
        '303' => 'pacing',     // Hysteresis Rate
        '354' => 'pacing',     // Rest Rate
        '361' => 'pacing',     // Ventricular Triggering
        '370' => 'pacing',     // Auto Intrinsic Conduction Search
        '373' => 'pacing',     // Pre-Vent. Atrial Blanking (Pre-VAB)
        '389' => 'pacing',     // Rate Responsive PVARP/VREF
        '390' => 'pacing',     // Shortest PVARP/VREF
        '401' => 'pacing',     // Activity Sensor
        '402' => 'pacing',     // Activity Sensor: Threshold
        '403' => 'pacing',     // Measured Average Sensor
        '404' => 'pacing',     // Activity Sensor: Slope
        '406' => 'pacing',     // Maximum Sensor Rate
        '407' => 'pacing',     // Reaction Time
        '409' => 'pacing',     // Activity Sensor: Recovery Time
        '413' => 'pacing',     // Measured Auto Slope
        '2024' => 'pacing',    // Maximum Pacing Rate

        // Pacing-Statistik
        '2681' => 'statistics', // Event Histogram Percent Paced In Ventricle
        '2682' => 'statistics', // Event Histogram Percent Paced In Atrium
        '2708' => 'statistics', // Atrial Paced - Lifetime
        '2709' => 'statistics', // Ventricular Paced - Lifetime (RVP)
        '2754' => 'statistics', // Total Number of AT/AF Episodes Since Last Cleared
        '2755' => 'statistics', // Total Time in AT/AF Recent Week

        // Arrhythmie / Ereignisse
        '848' => 'arrhythmia',  // ATHR Threshold
        '2404' => 'arrhythmia', // Episode Settings: Custom EGM 1 Anode
        '2405' => 'arrhythmia', // Episode Settings: Custom EGM 1 Cathode
        '2680' => 'arrhythmia', // Event Histogram Auto Mode Switch Count
        '9002' => 'arrhythmia', // High Ventricular Rate Alert
        '9042' => 'arrhythmia', // Episode Settings: Custom EGM 2 Anode
        '9043' => 'arrhythmia', // Episode Settings: Custom EGM 2 Cathode
        '9046' => 'arrhythmia', // VT Episode Trigger Priority
        '9048' => 'arrhythmia', // VT/VF Detection Rate
        '9049' => 'arrhythmia', // VT/VF No. of Consecutive Cycles
        '9056' => 'arrhythmia', // Magnet Response Trigger
        '9059' => 'arrhythmia', // Noise Reversion Trigger

        // Alarme / Benachrichtigungen
        '395' => 'alerts',     // Suspend Notification during Rest
        '9014' => 'alerts',    // Percent Pacing Alert Duration
        '9015' => 'alerts',    // Percent Ventricular Pacing Alert
        '9018' => 'alerts',    // Percentage Ventricular Pacing Limit

        // MRI
        '600' => 'mri', // MRI Mode
        '601' => 'mri', // MRI Base Rate
        '602' => 'mri', // MRI Paced AV Delay
        '603' => 'mri', // MRI Atrial Pulse Amplitude
        '604' => 'mri', // MRI RV Pulse Amplitude
        '605' => 'mri', // MRI Atrial Pulse Width
        '606' => 'mri', // MRI RV Pulse Width
        '607' => 'mri', // MRI Atrial Pulse Configuration
        '608' => 'mri', // MRI RV Pulse Configuration
        '609' => 'mri', // MRI Activator Ready Status
    ],

    // Fallback: exakte Bezeichnung (Gross-/Kleinschreibung egal)
    'by_name' => [
        'manufacturer: device' => 'device',
        'device manufacturer' => 'device',
        'manufacturer' => 'device',
    ],

    // Fallback: Muster auf die Bezeichnung (erste Uebereinstimmung gewinnt)
    'name_patterns' => [
        ['pattern' => '/^MRI\b/i', 'category' => 'mri'],
        ['pattern' => '/^(Programmer|Application)\b/i', 'category' => 'programmer'],
        ['pattern' => '/^Patient\b/i', 'category' => 'patient'],
        ['pattern' => '/^Device\b|^Session Timestamp$|^Implant Date: Device$/i', 'category' => 'device'],
        ['pattern' => '/Battery|Longevity|^Magnet (Rate|Response)$/i', 'category' => 'battery'],
        ['pattern' => '/Alert|Notification/i', 'category' => 'alerts'],
        ['pattern' => '/Histogram|Paced - Lifetime|Percent Paced|AT\/AF/i', 'category' => 'statistics'],
        ['pattern' => '/Episode|VT\/VF|\bVT\b|\bVF\b|Mode Switch|Noise Reversion|ATHR/i', 'category' => 'arrhythmia'],
        ['pattern' => '/Atrial Lead|^Atrial |^A (Sense|Pace)|^RA\b/i', 'category' => 'atrium'],
        ['pattern' => '/^RV\b|RV Lead|^Ventricular |^V\.? (Sense|Pace|Pulse)/i', 'category' => 'ventricle'],
        ['pattern' => '/^LV\b|LV Lead/i', 'category' => 'ventricle'],
        ['pattern' => '/Rate|Mode|AV Delay|PVARP|VREF|Hysteresis|Sensor|Blanking|Pulse (Amplitude|Width|Configuration)/i', 'category' => 'pacing'],
    ],

    // Felder fuer die Kopfdaten des Berichts (Patient / Geraet). Erst ID, dann Bezeichnung.
    'fields' => [
        'patient_name'            => ['ids' => ['2430'], 'names' => ['Patient Name']],
        'patient_identifier'      => ['ids' => ['204'], 'names' => ['Patient ID']],
        'patient_dob'             => ['ids' => ['2431'], 'names' => ['Patient Date of Birth']],
        'device_manufacturer'     => ['ids' => [], 'names' => ['Manufacturer: Device', 'Device Manufacturer', 'Manufacturer']],
        'device_model_name'       => ['ids' => ['200'], 'names' => ['Device Model Name']],
        'device_model_number'     => ['ids' => ['201'], 'names' => ['Device Model Number']],
        'device_serial'           => ['ids' => ['202'], 'names' => ['Device Serial Number']],
        'device_implant_date'     => ['ids' => ['2442'], 'names' => ['Implant Date: Device']],
        'mode'                    => ['ids' => ['301'], 'names' => ['Mode']],
        'base_rate'               => ['ids' => ['302'], 'names' => ['Base Rate']],
        'session_timestamp'       => ['ids' => ['105'], 'names' => ['Session Timestamp']],
        'interrogation_timestamp' => ['ids' => ['203'], 'names' => ['Device Last Interrogation Date and Time']],
    ],

    // Sonden-Erkennung ueber die Parameterbezeichnung ("chamber" = Kammerbezeichnung der Quelle)
    'lead_fields' => [
        'manufacturer'  => '/^Manufacturer:\s*(?<chamber>.+?)\s+Lead$/i',
        'model_number'  => '/^Model Number:\s*(?<label>(?:SJM\s+)?(?<chamber>.+?)\s+Lead)$/i',
        'implant_date'  => '/^Implant Date:\s*(?<chamber>.+?)\s+Lead$/i',
        'serial_number' => '/^(?<chamber>.+?)\s+Lead Serial Number$/i',
        'lead_type'     => '/^(?<chamber>.+?)\s+Lead Type$/i',
    ],

    'lead_chambers' => [
        ['pattern' => '/\b(Atrial|Atrium|RA)\b/i', 'key' => 'atrial', 'label' => 'Atrium'],
        ['pattern' => '/\bRV\b/', 'key' => 'rv', 'label' => 'Rechter Ventrikel (RV)'],
        ['pattern' => '/\bLV\b/', 'key' => 'lv', 'label' => 'Linker Ventrikel (LV)'],
    ],

    // Explizit dokumentierte Standardzuordnungen (z.B. IEEE 11073-10101). Derzeit keine.
    // Format: '<merlin-id>' => ['system' => 'IEEE 11073-10101', 'code' => '...', 'source' => 'Dokumentationsverweis']
    'standard_codes' => [],
];
