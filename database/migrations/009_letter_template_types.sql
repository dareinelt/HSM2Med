-- HSM2Med – Migration 009: Briefvorlagen je Empfaengerart und Anreden in den Stammdaten
--
-- Grundsaetze:
--  * Briefvorlagen werden getrennt je Empfaengerart gepflegt: Patient, Hausarzt und
--    ueberweisender Arzt haben eigene Fassungen (template_type). version_no laeuft daher
--    je Empfaengerart eigenstaendig. Bestehende Fassungen gelten als Vorlage fuer Patienten.
--  * Die Anrede des Briefes wird nicht in der Vorlage gepflegt, sondern in den Stammdaten des
--    Patienten - je Empfaengerart getrennt (salutation, physician_salutation,
--    referrer_salutation). Der Baustein "Anrede" der Vorlage enthaelt den Platzhalter
--    {salutation}; fehlt die Angabe, wird "Sehr geehrte Damen und Herren," ausgegeben.

ALTER TABLE letter_template_versions
    ADD COLUMN template_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'patient'
        COMMENT 'patient, family_doctor oder referring_physician' AFTER version_no;

ALTER TABLE letter_template_versions
    DROP INDEX uq_letter_template_versions_no;

ALTER TABLE letter_template_versions
    ADD UNIQUE KEY uq_letter_template_versions_type_no (template_type, version_no);

ALTER TABLE patient_card_master_data
    ADD COLUMN salutation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'Anrede des Patienten: herr, frau oder divers' AFTER patient_id,
    ADD COLUMN physician_salutation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'Anrede des Hausarztes: kollege, kollegin oder unpersoenlich' AFTER physician_phone,
    ADD COLUMN referrer_salutation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'Anrede des ueberweisenden Arztes: kollege, kollegin oder unpersoenlich' AFTER referrer_phone;

-- Bereits gespeicherte Vorlagen enthalten die Anrede als festen Text. Da die Anrede nun aus den
-- Stammdaten kommt, wird der Text des Bausteins "Anrede" (Position 2 der Standardreihenfolge)
-- durch den Platzhalter {salutation} ersetzt. Eigene Anreden einzelner Vorlagen werden dabei
-- bewusst mit ersetzt; die Anrede wird ab dieser Fassung in den Stammdaten gepflegt.
UPDATE letter_template_versions
    SET content = JSON_SET(content, '$.blocks[1].texts.text', '{salutation}')
    WHERE JSON_UNQUOTE(JSON_EXTRACT(content, '$.blocks[1].type')) = 'salutation'
      AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.blocks[1].texts.text')) <> '{salutation}';
