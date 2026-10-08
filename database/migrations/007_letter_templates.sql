-- HSM2Med – Migration 007: versionierte Briefvorlagen (DIN 5008)
--
-- Grundsaetze:
--  * Eine Briefvorlage beschreibt Aufbau (Reihenfolge der Bausteine) und alle festen Texte des
--    Briefes. Jede gespeicherte Aenderung ergibt eine neue, unveraenderliche Fassung
--    (version_no fortlaufend). Die aktuelle Vorlage ist die Fassung mit der hoechsten Nummer.
--  * Der Inhalt (JSON) wird beim Erstellen eines Briefes zusaetzlich in dessen Snapshot
--    eingefroren. template_version_id verweist auf die verwendete Fassung; Briefe aus der Zeit
--    vor dieser Migration (Brief-Fassung 1) haben keine Vorlagenfassung (NULL) und werden mit
--    dem damaligen festen Aufbau reproduziert.
--  * source_letter_id verweist bei einer Neuausfertigung auf den historischen Brief, dessen
--    Datengrundlage verwendet wurde. Bestehende Briefe werden nicht veraendert.

CREATE TABLE letter_template_versions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_no      INT UNSIGNED NOT NULL COMMENT 'fortlaufende Fassung der Briefvorlage, beginnend bei 1',
    name            VARCHAR(200) NOT NULL,
    comment         VARCHAR(500) NULL COMMENT 'Aenderungsnotiz zur Fassung',
    schema_version  SMALLINT UNSIGNED NOT NULL COMMENT 'Aufbau des Vorlagen-JSON',
    content         JSON NOT NULL COMMENT 'Bausteine, Reihenfolge und feste Texte',
    content_sha256  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_letter_template_versions_no (version_no),
    KEY idx_letter_template_versions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE patient_letters
    ADD COLUMN template_version_id BIGINT UNSIGNED NULL COMMENT 'verwendete Fassung der Briefvorlage' AFTER settings_version_id,
    ADD COLUMN source_letter_id BIGINT UNSIGNED NULL COMMENT 'Neuausfertigung: Brief mit der Datengrundlage' AFTER template_version_id,
    ADD KEY idx_patient_letters_template (template_version_id),
    ADD KEY idx_patient_letters_source (source_letter_id),
    ADD CONSTRAINT fk_patient_letters_template_version FOREIGN KEY (template_version_id) REFERENCES letter_template_versions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_patient_letters_source_letter FOREIGN KEY (source_letter_id) REFERENCES patient_letters (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;
