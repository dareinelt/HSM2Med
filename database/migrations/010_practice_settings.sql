-- HSM2Med – Migration 010: Praxis-Informationen und Ruecksendeangaben
--
-- Grundsaetze:
--  * Praxis-Informationen und Ruecksendeangaben werden im Bereich "System" gepflegt und gelten
--    fuer alle Briefe und Ausweise. Es gibt genau eine Datenquelle (patient_card_settings);
--    jede Aenderung erzeugt weiterhin eine neue, unveraenderliche Fassung.
--  * center_name und center_address sind bereits vorhanden und werden zur Praxis (Name und
--    Anschrift). Die Spaltennamen bleiben unveraendert, damit bestehende Ausweise, Briefe und
--    Stammdaten-Fassungen ohne Umrechnung weiterlesen koennen.
--  * Neu sind die Kontaktangaben der Praxis und die abweichende Ruecksendeangabe. Ist keine
--    Ruecksendeangabe hinterlegt, verwenden die Briefe automatisch die Praxisanschrift.
--  * Der Baustein "Ruecksendeangabe" der Briefvorlage enthielt bisher fest
--    "{center_name} · {center_address_line}". Er wird auf den Platzhalter
--    "{return_address_line}" umgestellt, der beide Faelle abbildet. Eigene Texte einzelner
--    Vorlagen bleiben unveraendert.

ALTER TABLE patient_card_settings
    ADD COLUMN practice_phone VARCHAR(64) NULL COMMENT 'Telefon der Praxis' AFTER center_address,
    ADD COLUMN practice_fax VARCHAR(64) NULL COMMENT 'Fax der Praxis' AFTER practice_phone,
    ADD COLUMN practice_email VARCHAR(255) NULL COMMENT 'E-Mail der Praxis' AFTER practice_fax,
    ADD COLUMN practice_website VARCHAR(255) NULL COMMENT 'Internetseite der Praxis' AFTER practice_email,
    ADD COLUMN return_name VARCHAR(255) NULL COMMENT 'Ruecksendeangabe: Name' AFTER practice_website,
    ADD COLUMN return_street VARCHAR(255) NULL COMMENT 'Ruecksendeangabe: Strasse' AFTER return_name,
    ADD COLUMN return_postal_code VARCHAR(32) NULL COMMENT 'Ruecksendeangabe: Postleitzahl' AFTER return_street,
    ADD COLUMN return_city VARCHAR(255) NULL COMMENT 'Ruecksendeangabe: Ort' AFTER return_postal_code;

ALTER TABLE patient_card_settings_versions
    ADD COLUMN practice_phone VARCHAR(64) NULL AFTER center_address,
    ADD COLUMN practice_fax VARCHAR(64) NULL AFTER practice_phone,
    ADD COLUMN practice_email VARCHAR(255) NULL AFTER practice_fax,
    ADD COLUMN practice_website VARCHAR(255) NULL AFTER practice_email,
    ADD COLUMN return_name VARCHAR(255) NULL AFTER practice_website,
    ADD COLUMN return_street VARCHAR(255) NULL AFTER return_name,
    ADD COLUMN return_postal_code VARCHAR(32) NULL AFTER return_street,
    ADD COLUMN return_city VARCHAR(255) NULL AFTER return_postal_code;

-- Die Ruecksendeangabe kommt jetzt aus den Stammdaten; der bisherige feste Text der Vorlage
-- wird nur ersetzt, wenn er unveraendert dem Standard entspricht.
UPDATE letter_template_versions
    SET content = JSON_SET(content, '$.zones.return_address.texts.text', '{return_address_line}')
    WHERE JSON_UNQUOTE(JSON_EXTRACT(content, '$.zones.return_address.texts.text')) = '{center_name} · {center_address_line}';

-- Neue Option des Briefkopfs: die Kontaktangaben der Praxis (Telefon, Fax, E-Mail, Website)
-- werden nur gedruckt, wenn die Vorlage das vorsieht.
UPDATE letter_template_versions
    SET content = JSON_SET(content, '$.zones.letterhead.options.show_contact', CAST('true' AS JSON))
    WHERE JSON_EXTRACT(content, '$.zones.letterhead.options.show_contact') IS NULL;
