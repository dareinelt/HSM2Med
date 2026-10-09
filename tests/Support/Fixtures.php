<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Testdaten gemaess Abschnitt 34 der Anforderungen (Werte aus der anonymisierten Beispieldatei).
 */
final class Fixtures
{
    public const string FS = "\x1C";

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function specRecords(): array
    {
        return [
            ['306', 'RV Pulse Amplitude', '2.5', 'V'],
            ['2457', 'Model Number: SJM Atrial Lead', '2088TC Tendril STS', ''],
            ['2432', 'Follow-up Physician', '', ''],
            ['2680', 'Event Histogram Auto Mode Switch Count', '0.0', ''],
            ['1606', 'RV. Capture Test Threshold Amplitude', '0.5', 'V'],
            ['101', 'Programmer Model Number', '3650', ''],
            ['875', 'RV Lead Monitoring: Lower Limit', '200', 'Ohms'],
            ['9015', 'Percent Ventricular Pacing Alert', 'On', ''],
            ['203', 'Device Last Interrogation Date and Time', '10/07/2026 07:03:24', ''],
            ['2459', 'Implant Date: Atrial Lead', '06/18/2024 00:00:00', ''],
            ['9048', 'VT/VF Detection Rate', '175', 'bpm'],
            ['520', 'Battery Current', '9', 'uA'],
            ['2221', 'Ventricular Sense Refractory', '250', 'ms'],
            ['519', 'Unloaded Battery Voltage', '2.97792', 'V'],
            ['305', 'RV Pulse Width', '0.4', 'ms'],
            ['103', 'Application Software Version Number', '3330 v28.9.2 rev1', ''],
            ['104', 'Application Build Number', 'upsw-IRChinaSKU_Unity_3650-250617', ''],
            ['9018', 'Percentage Ventricular Pacing Limit', '40', '%'],
            ['605', 'MRI Atrial Pulse Width', '1.0', 'ms'],
            ['2463', 'Implant Date: RV Lead', '06/18/2024 00:00:00', ''],
            ['2722', 'Ventricular Signal Amplitude', '4.9', 'mV'],
            ['413', 'Measured Auto Slope', '10', ''],
            ['2024', 'Maximum Pacing Rate', '130', 'bpm'],
            ['848', 'ATHR Threshold', '1.0', 'mV'],
            ['361', 'Ventricular Triggering', 'Off', ''],
            ['2755', 'Total Time in AT/AF Recent Week', '0', ''],
            ['1607', 'RV. Capture Test Pulse Width', '0.4', 'ms'],
            ['604', 'MRI RV Pulse Amplitude', '5.0', 'V'],
            ['201', 'Device Model Number', '2152', ''],
            ['302', 'Base Rate', '60', 'bpm'],
            ['2008', 'RV Pulse Configuration', 'Bipolar', ''],
            ['2431', 'Patient Date of Birth', '10/21/1938 00:00:00', ''],
            ['308', 'Ventricular Sensitivity', '2.0', 'mV'],
            ['309', 'Ventricular Pace Refractory', '250', 'ms'],
            ['307', 'Ventricular Sense Configuration', 'Bipolar', ''],
            ['353', 'RV AutoCapture', 'Off', ''],
            ['2709', 'Ventricular Paced - Lifetime (RVP)', '82.0', '%'],
            ['2442', 'Implant Date: Device', '06/18/2024 00:00:00', ''],
            ['2754', 'Total Number of AT/AF Episodes Since Last Cleared', '0', ''],
            ['501', 'Magnet Rate', '100.0', 'ppm'],
            ['873', 'RV Lead Monitoring', 'Monitor', ''],
            ['2904', 'A Sense Polarity', 'Bipolar', ''],
            ['2708', 'Atrial Paced - Lifetime', '6.4', '%'],
            ['105', 'Session Timestamp', '10/07/2026 07:03:24', ''],
            ['2470', 'RV Lead Serial Number', 'EEM126412', ''],
            ['601', 'MRI Base Rate', '85', 'bpm'],
            ['874', 'RV Lead Monitoring: Upper Limit', '2000', 'Ohms'],
            ['2681', 'Event Histogram Percent Paced In Ventricle', '67.0', '%'],
            ['208', 'Atrial Lead Type', 'Bipolar', ''],
            ['2461', 'Model Number: SJM RV Pace/Sense Lead', '2088TC Tendril STS', ''],
            ['406', 'Maximum Sensor Rate', '130', 'bpm'],
            ['301', 'Mode', 'VVI', ''],
            ['200', 'Device Model Name', 'Endurity Core', ''],
            ['9049', 'VT/VF No. of Consecutive Cycles', '5', ''],
            ['606', 'MRI RV Pulse Width', '1.0', 'ms'],
            ['202', 'Device Serial Number', '5809481', ''],
            ['533', 'Longevity Estimate', '8.9', 'yrs'],
            ['321', 'Magnet Response', 'Battery Test', ''],
            ['2456', 'Manufacturer: Atrial Lead', 'St. Jude Medical', ''],
            ['211', 'RV Lead Type', 'Bipolar', ''],
            ['2468', 'Atrial Lead Serial Number', 'EEL193668', ''],
            ['602', 'MRI Paced AV Delay', '120', 'ms'],
            ['303', 'Hysteresis Rate', 'Off', ''],
            ['603', 'MRI Atrial Pulse Amplitude', '5.0', 'V'],
            ['204', 'Patient ID', '10358141', ''],
            ['2430', 'Patient Name', 'LASTNAME, FIRSTNAME', ''],
            ['2460', 'Manufacturer: RV Lead', 'St. Jude Medical', ''],
            ['100', 'Programmer Marketing Name', 'Merlin', ''],
            ['2004', 'Ventricular Sensing Chamber', 'RV', ''],
            ['600', 'MRI Mode', 'DOO', ''],
            ['2721', 'Atrial Signal Amplitude', '2.2', 'mV'],
            ['507', 'RV Pacing Lead Impedance', '462.5', 'Ohm'],
            ['2604', 'V. Pulse Amp Decrement Capture Test: Capture Threshold (Pulse Amp)', '0.5', 'V'],
            ['2605', 'V. Pulse Amp Decrement Capture Test: Test Pulse Width', '0.4', 'ms'],
            ['2913', 'V. Pulse Amp Decrement Capture Test: Pulse Configuration', 'B', ''],
        ];
    }

    /**
     * Baut eine Merlin-Datei: jedes Feld mit 0x1C abgeschlossen, Datensaetze durch $eol getrennt.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $records
     */
    public static function build(array $records, string $eol = "\n"): string
    {
        $out = '';
        foreach ($records as $record) {
            $out .= implode(self::FS, $record) . self::FS . $eol;
        }
        return $out;
    }

    public static function specFile(string $eol = "\n"): string
    {
        return self::build(self::specRecords(), $eol);
    }

    public static function sampleFilePath(): string
    {
        return dirname(__DIR__) . '/fixtures/merlin_sample.log';
    }

    public static function sampleFile(): string
    {
        return (string) file_get_contents(self::sampleFilePath());
    }

    /** Biotronik-XML-Export nach IEEE 11073-10103 (anonymisiertes Beispiel, Creator "BioICSConverter"). */
    public static function biotronikFilePath(): string
    {
        return dirname(__DIR__) . '/fixtures/BIOTRONIC_ANN.xml';
    }

    public static function biotronikFile(): string
    {
        return (string) file_get_contents(self::biotronikFilePath());
    }

    /** Wie der Hersteller sie ausliefert: Praefix BIOIEEE_ vor dem Dateinamen. */
    public static function biotronikUploadName(): string
    {
        return 'BIOIEEE_ANN.xml';
    }
}
