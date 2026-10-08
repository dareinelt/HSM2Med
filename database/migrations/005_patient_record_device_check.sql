-- HSM2Med – Migration 005: Baustein "Schrittmacher-/ICD-Abfrage"
--
-- Grundsaetze (analog zu Migration 003):
--  * Die Abfrage ist ein zusaetzlicher Bausteintyp der Akte (device_check). Sie folgt damit
--    denselben Regeln: ein Behaelter je Patient (patient_records), unveraenderliche Fassungen
--    in patient_record_versions, inhaltsgleiche Speicherungen erzeugen keine neue Fassung.
--  * Es werden keine neuen Tabellen und keine neuen Spalten benoetigt. Der Inhalt der Abfrage
--    ist strukturiertes JSON in patient_record_versions.content:
--      {"template":"1.0.0","device_type":"pacemaker","values":{...},"leads":[...],"notes":""}
--    Die Feldliste und die je Geraetetyp zulaessigen Abschnitte stehen in
--    config/device_check_template.php; die Fassung der Vorlage wird im Inhalt mitgefuehrt.
--  * content_text enthaelt weiterhin die Textfassung (Anzeige, Suche, spaeterer Brief).
--  * Die Aufzaehlung ist die einzige Stelle, an der ein neuer Bausteintyp ergaenzt werden muss;
--    der neue Wert 'device_check' muss exakt dem Wert von PatientRecordType::DeviceCheck
--    entsprechen.
--  * Bestehende Akteneintraege bleiben unberuehrt: die Erweiterung einer ENUM-Spalte aendert
--    vorhandene Zeilen nicht.

ALTER TABLE patient_records
    MODIFY COLUMN record_type ENUM('anamnesis','premedication','epicrisis','note','device_check') NOT NULL
        COMMENT 'Bausteintyp der Akte';
