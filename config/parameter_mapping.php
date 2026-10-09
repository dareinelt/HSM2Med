<?php

declare(strict_types=1);

/*
 * Parameter-Mapping fuer Merlin-Exporte (.txt/.log) und Biotronik-XML-Exporte
 * (IEEE 11073-10103, Creator "BioICSConverter", Dateinamen z.B. BIOIEEE_ANN.xml).
 *
 * WICHTIG: Die Schluessel in "by_id" sind Quellformat-Parameter-IDs der Exportdatei
 * (Merlin: 1-8-stellige IDs, Biotronik: 6-stellige "code"-Attribute).
 * Sie sind KEINE IEEE-11073- oder sonstigen Standard-IDs. Eine Standardzuordnung darf nur
 * explizit und dokumentiert unter "standard_codes" erfolgen.
 *
 * Aufloesungsreihenfolge: by_id -> by_name (exakt, ohne Gross-/Kleinschreibung) -> name_patterns
 * -> fallback_category. Kein Parameter wird verworfen.
 *
 * Die Bezeichnungen in "display_name" benennen nur das Quellfeld (z.B. LOWRATE -> Grundfrequenz).
 * Es findet keine Bewertung, Rundung, Normalisierung oder Umrechnung statt.
 *
 * Bei inhaltlichen Aenderungen "version" erhoehen. Bereits importierte Berichte bleiben
 * unveraendert, da Kategorie und Bezeichnung beim Import im Bericht gespeichert werden.
 */
return [
    'version' => '1.1.0',

    'categories' => [
        'patient'    => ['label' => 'Patient', 'sort' => 10],
        'device'     => ['label' => 'Gerät', 'sort' => 20],
        'battery'    => ['label' => 'Batterie', 'sort' => 30],
        'atrium'     => ['label' => 'Atrium', 'sort' => 40],
        'ventricle'  => ['label' => 'Ventrikel / RV', 'sort' => 50],
        'lead'       => ['label' => 'Sonde / Elektrode', 'sort' => 55],
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

        // --- Biotronik XML-Export (IEEE 11073-10103) ------------------------------------------
        // Schluessel = Attributwert "code" der <value>-Knoten; in Klammern das Quellfeld "name"
        // mit dem Abschnittspfad. Nur Benennung des Quellfelds, keine Bewertung.

        // Gerät (MDC/IDC/DEV)
        '720897' => ['category' => 'device', 'display_name' => 'Gerätetyp (TYPE)'],
        '720898' => ['category' => 'device', 'display_name' => 'Gerätemodell (MODEL)'],
        '720899' => ['category' => 'device', 'display_name' => 'Seriennummer des Geräts (SERIAL)'],
        '720900' => ['category' => 'device', 'display_name' => 'Hersteller des Geräts (MFG)'],
        '720901' => ['category' => 'device', 'display_name' => 'Implantationsdatum des Geräts (IMPLANT_DT)'],
        '720904' => ['category' => 'device', 'display_name' => 'Implantierendes Zentrum (IMPLANTING_FACILITY)'],

        // Sonde (MDC/IDC/LEAD)
        '720961' => ['category' => 'lead', 'display_name' => 'Sondenmodell (MODEL)'],
        '720962' => ['category' => 'lead', 'display_name' => 'Seriennummer der Sonde (SERIAL)'],
        '720963' => ['category' => 'lead', 'display_name' => 'Hersteller der Sonde (MFG)'],
        '720964' => ['category' => 'lead', 'display_name' => 'Implantationsdatum der Sonde (IMPLANT_DT)'],
        '720965' => ['category' => 'lead', 'display_name' => 'Polaritätstyp der Sonde (POLARITY_TYPE)'],
        '720966' => ['category' => 'lead', 'display_name' => 'Position der Sonde (LOCATION)'],

        // Sitzung (MDC/IDC/SESS)
        '721025' => ['category' => 'device', 'display_name' => 'Sitzungszeitpunkt (DTM)'],
        '721026' => ['category' => 'device', 'display_name' => 'Sitzungsart (TYPE)'],
        '721028' => ['category' => 'device', 'display_name' => 'Vorheriger Sitzungszeitpunkt (DTM_PREVIOUS)'],

        // Batterie (MDC/IDC/MSMT/BATTERY)
        '721216' => ['category' => 'battery', 'display_name' => 'Messzeitpunkt der Batterie (DTM)'],
        '721280' => ['category' => 'battery', 'display_name' => 'Batteriestatus (STATUS)'],
        '721536' => ['category' => 'battery', 'display_name' => 'Restkapazität der Batterie (REMAINING_PERCENTAGE)'],

        // RV-Sondenkanal: Messwerte (MDC/IDC/MSMT/LEADCHNL_RV)
        '721985' => ['category' => 'ventricle', 'display_name' => 'Status des RV-Sondenkanals (LEAD_CHANNEL_STATUS)'],
        '722053' => ['category' => 'ventricle', 'display_name' => 'Intrinsische Amplitude Maximum (INTR_AMPL_MAX)'],
        '722054' => ['category' => 'ventricle', 'display_name' => 'Intrinsische Amplitude Minimum (INTR_AMPL_MIN)'],
        '722055' => ['category' => 'ventricle', 'display_name' => 'Intrinsische Amplitude Mittelwert (INTR_AMPL_MEAN)'],
        '722113' => ['category' => 'ventricle', 'display_name' => 'Polarität der Wahrnehmung (POLARITY)'],
        '722177' => ['category' => 'ventricle', 'display_name' => 'Reizamplitude im Schwellentest (AMPLITUDE)'],
        '722241' => ['category' => 'ventricle', 'display_name' => 'Impulsbreite im Schwellentest (PULSEWIDTH)'],
        '722305' => ['category' => 'ventricle', 'display_name' => 'Messmethode des Schwellentests (MEASUREMENT_METHOD)'],
        '722369' => ['category' => 'ventricle', 'display_name' => 'Polarität im Schwellentest (POLARITY)'],
        '722433' => ['category' => 'ventricle', 'display_name' => 'Impedanz (VALUE)'],
        '722497' => ['category' => 'ventricle', 'display_name' => 'Polarität der Impedanzmessung (POLARITY)'],

        // Magnetantwort (MDC/IDC/SET/MAGNET)
        '729472' => ['category' => 'battery', 'display_name' => 'Magnetantwort (RESP)'],

        // RV-Sondenkanal: Wahrnehmung (MDC/IDC/SET/LEADCHNL_RV/SENSING)
        '729601' => ['category' => 'ventricle', 'display_name' => 'Polarität der Wahrnehmung (POLARITY)'],
        '729921' => ['category' => 'ventricle', 'display_name' => 'Anpassungsmodus der Wahrnehmung (ADAPTATION_MODE)'],

        // RV-Sondenkanal: Stimulation (MDC/IDC/SET/LEADCHNL_RV/PACING)
        '729985' => ['category' => 'ventricle', 'display_name' => 'Reizamplitude (AMPLITUDE)'],
        '730049' => ['category' => 'ventricle', 'display_name' => 'Impulsbreite (PULSEWIDTH)'],
        '730113' => ['category' => 'ventricle', 'display_name' => 'Polarität der Stimulation (POLARITY)'],
        '730433' => ['category' => 'ventricle', 'display_name' => 'Capture-Modus (CAPTURE_MODE)'],

        // Brady-Programmierung (MDC/IDC/SET/BRADY)
        '730752' => ['category' => 'pacing', 'display_name' => 'Brady-Modus (MODE)'],
        '730816' => ['category' => 'pacing', 'display_name' => 'Herstellermodus (VENDOR_MODE)'],
        '730880' => ['category' => 'pacing', 'display_name' => 'Low Rate / Grundfrequenz (LOWRATE)'],
        '730944' => ['category' => 'pacing', 'display_name' => 'Hysterese-Frequenz (HYSTRATE)'],
        '731008' => ['category' => 'pacing', 'display_name' => 'Nachtfrequenz (NIGHT_RATE)'],

        // Pacing-Statistik (MDC/IDC/STAT/BRADY)
        '737505' => ['category' => 'statistics', 'display_name' => 'Beginn des Auswertungszeitraums (DTM_START)'],
        '737506' => ['category' => 'statistics', 'display_name' => 'Ende des Auswertungszeitraums (DTM_END)'],
        '737536' => ['category' => 'statistics', 'display_name' => 'Ventrikulär stimulierte Schläge (RV_PERCENT_PACED)'],

        // Episoden (MDC/IDC/STAT/EPISODE)
        '737952' => ['category' => 'arrhythmia', 'display_name' => 'Episodentyp (TYPE)'],
        '737984' => ['category' => 'arrhythmia', 'display_name' => 'Herstellerspezifischer Episodentyp (VENDOR_TYPE)'],
        '738000' => ['category' => 'arrhythmia', 'display_name' => 'Anzahl der Episoden im Zeitraum (RECENT_COUNT)'],
        '738017' => ['category' => 'arrhythmia', 'display_name' => 'Beginn des Episodenzeitraums (RECENT_COUNT_DTM_START)'],
        '738018' => ['category' => 'arrhythmia', 'display_name' => 'Ende des Episodenzeitraums (RECENT_COUNT_DTM_END)'],
    ],

    // Fallback: exakte Bezeichnung (Gross-/Kleinschreibung egal)
    'by_name' => [
        'manufacturer: device' => 'device',
        'device manufacturer' => 'device',
        'manufacturer' => 'device',
        // Biotronik-Wertknoten ohne "code" (Abschnitt MDC/ATTR/PT und BIO/...)
        'name_family' => 'patient',
        'name_given' => 'patient',
        'dob' => 'patient',
        'sex' => 'patient',
        'contains_pii_data' => 'programmer',
        'sw_version_at_request' => 'programmer',
        'sw_version_at_followup' => 'programmer',
        'followup_id' => 'device',
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
    // "combine" fasst mehrere Quellfelder zu einem Wert zusammen (Biotronik: NAME_FAMILY + NAME_GIVEN).
    'fields' => [
        'patient_name'            => ['ids' => ['2430'], 'names' => ['Patient Name'], 'combine' => ['names' => ['NAME_FAMILY', 'NAME_GIVEN'], 'separator' => ', ']],
        'patient_identifier'      => ['ids' => ['204'], 'names' => ['Patient ID']],
        'patient_dob'             => ['ids' => ['2431'], 'names' => ['Patient Date of Birth', 'DOB']],
        'device_manufacturer'     => ['ids' => ['720900'], 'names' => ['Manufacturer: Device', 'Device Manufacturer', 'Manufacturer', 'MFG']],
        'device_model_name'       => ['ids' => ['200', '720898'], 'names' => ['Device Model Name']],
        'device_model_number'     => ['ids' => ['201'], 'names' => ['Device Model Number']],
        'device_serial'           => ['ids' => ['202', '720899'], 'names' => ['Device Serial Number']],
        'device_implant_date'     => ['ids' => ['2442', '720901'], 'names' => ['Implant Date: Device']],
        'mode'                    => ['ids' => ['301', '730752'], 'names' => ['Mode']],
        'base_rate'               => ['ids' => ['302', '730880'], 'names' => ['Base Rate']],
        'session_timestamp'       => ['ids' => ['105', '721025'], 'names' => ['Session Timestamp']],
        'interrogation_timestamp' => ['ids' => ['203', '721028'], 'names' => ['Device Last Interrogation Date and Time']],
    ],

    // Sonden-Erkennung ueber die Parameterbezeichnung ("chamber" = Kammerbezeichnung der Quelle)
    'lead_fields' => [
        'manufacturer'  => '/^Manufacturer:\s*(?<chamber>.+?)\s+Lead$/i',
        'model_number'  => '/^Model Number:\s*(?<label>(?:SJM\s+)?(?<chamber>.+?)\s+Lead)$/i',
        'implant_date'  => '/^Implant Date:\s*(?<chamber>.+?)\s+Lead$/i',
        'serial_number' => '/^(?<chamber>.+?)\s+Lead Serial Number$/i',
        'lead_type'     => '/^(?<chamber>.+?)\s+Lead Type$/i',
    ],

    // Sonden-Erkennung ueber ganze XML-Abschnitte (Biotronik): ein Abschnitt = eine Sonde.
    // Die Kammer stammt aus dem Feld "chamber" innerhalb desselben Abschnitts.
    'lead_sections' => [
        [
            'section' => 'MDC/IDC/LEAD',
            'chamber' => ['ids' => ['720966'], 'names' => ['LOCATION']],
            'fields' => [
                'manufacturer'  => ['ids' => ['720963'], 'names' => ['MFG']],
                'model_number'  => ['ids' => ['720961'], 'names' => ['MODEL']],
                'serial_number' => ['ids' => ['720962'], 'names' => ['SERIAL']],
                'implant_date'  => ['ids' => ['720964'], 'names' => ['IMPLANT_DT']],
            ],
        ],
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
