-- HSM2Med – Migration 018: Kennwortwechsel bei der naechsten Anmeldung
--
-- Grundsaetze:
--  * Legt die Administration ein Konto an oder setzt sie ein Kennwort neu, kennt die betroffene
--    Person dieses Kennwort nicht. Sie muss es bei der naechsten Anmeldung ersetzen; bis dahin
--    sind alle Bereiche ausser "Kennwort aendern" und "Abmelden" gesperrt.
--  * Bestandskonten bleiben unveraendert (0): die Migration zwingt niemanden ohne Anlass zu
--    einem neuen Kennwort.
--  * Das Merkmal wird beim Setzen des eigenen Kennworts automatisch zurueckgesetzt
--    (UserService::changeOwnPassword()).

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = muss das Kennwort bei der naechsten Anmeldung aendern'
        AFTER password_changed_at;
