-- HSM2Med – Migration 004: MRT-Tauglichkeit auf Seite 1 des Patientenausweises
--
-- Grundsaetze (analog zu Migration 002):
--  * Der Patientenausweis bleibt bei genau zwei DIN-A4-Seiten. Die Angabe zur MRT-Tauglichkeit
--    wird auf Seite 1 im Abschnitt "Implantate" gedruckt und braucht dort keinen zusaetzlichen
--    Seitenumbruch.
--  * Die Angabe steht im Merlin-Export nicht zur Verfuegung und wird daher vom Benutzer gepflegt.
--    Sie gehoert zu Geraet und Sonden und wird je Patient in patient_card_master_data gehalten
--    (Vorbelegung des Assistenten) und im unveraenderlichen Snapshot des Ausweises archiviert.
--  * mrt_compatibility enthaelt einen der festen Auswahlwerte
--    ('MRT-tauglich', 'MRT-bedingt tauglich', 'nicht MRT-tauglich', 'unbekannt').
--    Leer bedeutet "nicht angegeben"; die Gueltigkeit prueft die Anwendung
--    (PatientCardInput::MRT_VALUES).
--  * mrt_compatibility_note ist eine kurze Zusatzangabe (z. B. Bedingungen). Die Laenge ist
--    bewusst begrenzt, damit Seite 1 des Ausweises sicher passt
--    (PatientCardPdfGenerator::MAX_MRT_CHARS).
--  * Fremdschluessel und Unveraenderlichkeit bestehender Ausweise bleiben unberuehrt: bereits
--    erzeugte Ausweise enthalten die Angabe nicht und werden nicht neu berechnet.

ALTER TABLE patient_card_master_data
    ADD COLUMN mrt_compatibility VARCHAR(64) NULL
        COMMENT 'MRT-Tauglichkeit (Ausweis Seite 1), fester Auswahlwert' AFTER device_implant_location,
    ADD COLUMN mrt_compatibility_note VARCHAR(120) NULL
        COMMENT 'Zusatzangabe zur MRT-Tauglichkeit (z. B. Bedingungen)' AFTER mrt_compatibility;
