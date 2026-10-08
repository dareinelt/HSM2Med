-- HSM2Med – Migration 011: Baustein "Befund"
--
-- Grundsaetze (analog zu Migration 003 und 005):
--  * Der Befund ist ein zusaetzlicher Bausteintyp der Akte (befund). Er folgt denselben Regeln:
--    ein Behaelter je Patient (patient_records), unveraenderliche Fassungen in
--    patient_record_versions, inhaltsgleiche Speicherungen erzeugen keine neue Fassung.
--  * Es werden keine neuen Tabellen und keine neuen Spalten benoetigt. Der Befund ist ein
--    Freitext des Arztes; er liegt wie bei Anamnese, Epikrise und Notiz in content_text.
--  * Der Baustein ersetzt im Brief den bisherigen Befundteil "Befund (Bericht)": dort steht
--    kuenftig der eingefrorene Befund des Arztes. Der Befundteil des Berichts erscheint im
--    Brief als eigener Baustein "Berichte" auf einer neuen Seite.
--  * Die Aufzaehlung ist die einzige Stelle, an der ein neuer Bausteintyp ergaenzt werden muss;
--    der neue Wert 'befund' muss exakt dem Wert von PatientRecordType::Befund entsprechen.
--  * Bestehende Akteneintraege bleiben unberuehrt: die Erweiterung einer ENUM-Spalte am Ende
--    der Werteliste aendert vorhandene Zeilen nicht.

ALTER TABLE patient_records
    MODIFY COLUMN record_type ENUM('anamnesis','premedication','epicrisis','note','device_check','befund') NOT NULL
        COMMENT 'Bausteintyp der Akte';
