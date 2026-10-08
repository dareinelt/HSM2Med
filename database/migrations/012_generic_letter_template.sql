-- HSM2Med – Migration 012: Briefvorlage fuer den generischen Arztbrief
--
-- Grundsaetze:
--  * Der generische Arztbrief ist eine vierte Empfaengerart (template_type = 'generic'). Er wird
--    verwendet, wenn weder Hausarzt noch ueberweisender Arzt eine Anschrift in den Stammdaten
--    haben; der Brief geht dann an "An die weiterbehandelnden Aerztinnen und Aerzte" und wird mit
--    "Sehr geehrte Kollegin, sehr geehrter Kollege," angeredet.
--  * Die Empfaengerart ist der neue Wert 'generic' in letter_template_versions.template_type. Der
--    Wert entspricht exakt LetterRecipient::GENERIC.
--  * Es werden keine neuen Tabellen und keine neuen Spalten benoetigt: die Vorlage nutzt dieselbe
--    Struktur (blocks, zones) wie die uebrigen Arten, die Anrede ist fest hinterlegt. Die
--    Vorlagenverwaltung legt die erste Fassung der neuen Art bei Bedarf selbst an.
--  * Bestehende Vorlagen und Fassungen bleiben unberuehrt; geaendert wird nur die Dokumentation
--    der Spalte, damit die zulaessigen Werte dort vollstaendig benannt sind.

ALTER TABLE letter_template_versions
    MODIFY COLUMN template_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'patient'
        COMMENT 'patient, family_doctor, referring_physician oder generic';
