-- HSM2Med – Migration 017: Unterschriften der Benutzer und Baustein "Unterschrift" im Brief
--
-- Grundsaetze:
--  * Jedes Benutzerkonto kann eine Unterschrift als Bild hinterlegen (JPG, PNG, BMP). Die
--    Anpassung an die im Brief vorgesehene Groesse erfolgt beim Erzeugen des PDF, nicht beim
--    Hochladen: die abgelegte Datei bleibt unveraendert erhalten.
--  * Die Bilder liegen wie die Logos der Stammdaten in einer eigenen Tabelle mit Pruefsumme,
--    damit gleiche Dateien nur einmal gespeichert werden. Ein Benutzer verweist ueber
--    signature_id auf sein Bild; eine ersetzte Unterschrift erzeugt eine neue Zeile, die alte
--    bleibt fuer bereits erstellte Briefe erhalten.
--  * Fuer die Zeile unter der Unterschrift werden Titel, Vorname und Nachname getrennt
--    gepflegt. display_name bleibt als Anzeigename bestehen (Statusleiste, Listen) und dient
--    als Rueckfall, solange Vor- und Nachname fehlen.
--  * Der Brief friert Unterschrift und Name im Snapshot ein (LetterService). Historische Briefe
--    bleiben dadurch reproduzierbar, auch wenn die Unterschrift spaeter ersetzt wird.
--  * Der Brieftext erhaelt den Baustein "signature". Neue Vorlagen enthalten ihn von Anfang an
--    (LetterTemplate::default()); bereits gespeicherte Fassungen werden hier einmalig ergaenzt:
--    der Baustein wird hinter der Grussformel eingefuegt. Der bisherige Text "Unterzeichner"
--    der Grussformel entfaellt nur, wenn er noch dem Standardwert {center_name} entspricht –
--    eigene Angaben bleiben unveraendert.
--  * Texte und Optionen des neuen Bausteins fuellen sich beim Lesen aus der Bausteindefinition
--    (LetterTemplate::normalize()), deshalb genuegt hier der Bausteintyp.
--  * Die Pruefsumme content_sha256 bleibt wie in Migration 009, 010 und 016 unveraendert: sie
--    beschreibt den Stand bei der Speicherung der Fassung, nicht die Aufwertung.

CREATE TABLE user_signatures (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sha256     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type  VARCHAR(64) NOT NULL,
    filename   VARCHAR(200) NOT NULL COMMENT 'bereinigter Originalname, nur zur Anzeige',
    width      INT UNSIGNED NOT NULL,
    height     INT UNSIGNED NOT NULL,
    content    MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_signatures_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    COMMENT='Unterschriften der Benutzer (Bilddateien)';

ALTER TABLE users
    ADD COLUMN title VARCHAR(64) NULL COMMENT 'Titel (z. B. Dr. med.)' AFTER display_name,
    ADD COLUMN first_name VARCHAR(128) NULL COMMENT 'Vorname' AFTER title,
    ADD COLUMN last_name VARCHAR(128) NULL COMMENT 'Nachname' AFTER first_name,
    ADD COLUMN signature_id BIGINT UNSIGNED NULL COMMENT 'Hinterlegte Unterschrift' AFTER is_active,
    ADD KEY idx_users_signature (signature_id),
    ADD CONSTRAINT fk_users_signature FOREIGN KEY (signature_id) REFERENCES user_signatures (id)
        ON DELETE SET NULL ON UPDATE RESTRICT;

UPDATE letter_template_versions
    SET content = JSON_ARRAY_INSERT(
        content,
        CONCAT(
            '$.blocks[',
            CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(
                JSON_UNQUOTE(JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'closing')),
                '[', -1), ']', 1) AS UNSIGNED) + 1,
            ']'
        ),
        JSON_OBJECT('id', 'signature', 'type', 'signature', 'enabled', true)
    )
WHERE JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'closing') IS NOT NULL
  AND JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'signature') IS NULL;

UPDATE letter_template_versions
    SET content = JSON_SET(
        content,
        JSON_UNQUOTE(JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].texts.signature'), 'one', '{center_name}')),
        ''
    )
WHERE JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].texts.signature'), 'one', '{center_name}') IS NOT NULL;
