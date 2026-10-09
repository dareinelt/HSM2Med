-- HSM2Med – Migration 014: versionierte Vorlagen des Patientenausweises
--
-- Grundsaetze (analog zu Migration 007/009 fuer die Briefvorlagen):
--  * Eine Ausweisvorlage beschreibt Aufbau (Reihenfolge der Bausteine), Zonen und alle festen
--    Texte des Patientenausweises. Jede gespeicherte Aenderung ergibt eine neue, unveraenderliche
--    Fassung (version_no fortlaufend). Die aktuelle Vorlage ist die Fassung mit der hoechsten
--    Nummer. Fassungen werden nie ueberschrieben.
--  * Der Inhalt (JSON) wird beim Erstellen eines Ausweises zusaetzlich in dessen Snapshot
--    eingefroren. template_version_id verweist auf die verwendete Fassung; Ausweise aus der Zeit
--    vor dieser Migration (Ausweisfassung 2) haben keine Vorlagenfassung (NULL) und werden mit
--    dem damaligen festen Aufbau reproduziert. Vorlagenaenderungen wirken sich daher nicht
--    rueckwirkend auf bereits erstellte Ausweise aus.

CREATE TABLE patient_card_template_versions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_no      INT UNSIGNED NOT NULL COMMENT 'fortlaufende Fassung der Ausweisvorlage, beginnend bei 1',
    name            VARCHAR(200) NOT NULL,
    comment         VARCHAR(500) NULL COMMENT 'Aenderungsnotiz zur Fassung',
    schema_version  SMALLINT UNSIGNED NOT NULL COMMENT 'Aufbau des Vorlagen-JSON',
    content         JSON NOT NULL COMMENT 'Zonen, Bausteine, Reihenfolge und feste Texte',
    content_sha256  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_card_template_versions_no (version_no),
    KEY idx_patient_card_template_versions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE patient_cards
    ADD COLUMN template_version_id BIGINT UNSIGNED NULL COMMENT 'verwendete Fassung der Ausweisvorlage' AFTER settings_version_id,
    ADD KEY idx_patient_cards_template (template_version_id),
    ADD CONSTRAINT fk_patient_cards_template_version FOREIGN KEY (template_version_id) REFERENCES patient_card_template_versions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT;

-- Recht fuer den Ausweisvorlagen-Editor. Die Rechtematrix entstand in Migration 013; ein neues
-- Recht wird hier nachgetragen, damit bereits eingerichtete Installationen es erhalten.
-- Verteilung wie bei den Ausweis-Stammdaten: Verwaltung (admin) und aerztlicher Dienst (arzt).
INSERT INTO user_group_permissions (group_id, permission)
SELECT g.id, p.permission
FROM user_groups g
CROSS JOIN (SELECT 'patient_card_templates' AS permission) p
WHERE g.code IN ('admin', 'arzt');
