-- HSM2Med – Migration 008: Briefempfaenger (Patient, Hausarzt, ueberweisender Arzt)
--
-- Grundsaetze:
--  * Die Stammdaten eines Patienten fuehren neben dem Hausarzt (jetzt mit Strasse) den
--    ueberweisenden Arzt. Beide liefern die Anschrift fuer das Anschriftfeld des Briefes.
--  * Der Brief-Assistent erzeugt je ausgewaehltem Empfaenger einen eigenen, unveraenderlichen
--    Brief. Die Anschrift wird im Snapshot eingefroren; recipient_type und recipient_name
--    dienen nur der Anzeige in Listen. Briefe vor dieser Migration haben keinen Empfaenger
--    (NULL) und behalten das Anschriftfeld ihrer Vorlage.

ALTER TABLE patient_card_master_data
    ADD COLUMN physician_street      VARCHAR(255) NULL COMMENT 'Hausarzt: Strasse und Hausnummer' AFTER physician_practice,
    ADD COLUMN referrer_name         VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Name' AFTER physician_phone,
    ADD COLUMN referrer_practice     VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Praxis' AFTER referrer_name,
    ADD COLUMN referrer_street       VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Strasse und Hausnummer' AFTER referrer_practice,
    ADD COLUMN referrer_postal_code  VARCHAR(32) NULL COMMENT 'Ueberweisender Arzt: Postleitzahl' AFTER referrer_street,
    ADD COLUMN referrer_city         VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Ort' AFTER referrer_postal_code,
    ADD COLUMN referrer_phone        VARCHAR(64) NULL COMMENT 'Ueberweisender Arzt: Telefon' AFTER referrer_city;

ALTER TABLE patient_letters
    ADD COLUMN recipient_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'patient, family_doctor oder referring_physician; NULL bei Briefen vor Migration 008' AFTER source_letter_id,
    ADD COLUMN recipient_name VARCHAR(512) NULL COMMENT 'Empfaenger fuer Listen (Anschrift im Snapshot)' AFTER recipient_type,
    ADD KEY idx_patient_letters_recipient (patient_id, recipient_type);
