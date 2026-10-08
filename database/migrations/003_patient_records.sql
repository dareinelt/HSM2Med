-- HSM2Med – Migration 003: Patientenakte (versionierte Bausteine)
--
-- Grundsaetze (analog zu den Migrationen 001 und 002):
--  * Ein Patient kann ohne Import angelegt werden. Anamnese, Vormedikation, Epikrise und
--    Notiz werden als eigene Bausteine zum Patienten gespeichert.
--  * Jeder Baustein ist versioniert und unveraenderlich: jede Aenderung erzeugt eine neue
--    Fassung in patient_record_versions. Fruehere Fassungen werden nie ueberschrieben oder
--    geloescht; die aktuelle Fassung ist die mit der hoechsten Versionsnummer.
--  * patient_records ist der Behaelter je Patient und Bausteintyp (genau einer je Kombination).
--    Der Inhalt liegt ausschliesslich in den unveraenderlichen Fassungen.
--  * content_text ist eine Textfassung des Inhalts (Anzeige, spaetere Verwendung im Brief);
--    content_hash (SHA-256 des kanonischen JSON) verhindert inhaltsgleiche neue Fassungen.
--  * Leere Werte werden als '' gespeichert, NULL bedeutet "Feld nicht vorhanden".
--  * Fremdschluessel durchgaengig ON DELETE RESTRICT; Akteneintraege werden nicht geloescht.
--  * Es gibt keine Benutzerverwaltung: author_name ist eine Freitextangabe ("erfasst von").

CREATE TABLE patient_records (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id  BIGINT UNSIGNED NOT NULL,
    record_type ENUM('anamnesis','premedication','epicrisis','note') NOT NULL
        COMMENT 'Bausteintyp der Akte',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_records_patient_type (patient_id, record_type),
    CONSTRAINT fk_patient_records_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE patient_record_versions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id    BIGINT UNSIGNED NOT NULL,
    version      INT UNSIGNED NOT NULL COMMENT 'fortlaufend ab 1',
    content      JSON NOT NULL COMMENT 'strukturierter Inhalt des Bausteins',
    content_text MEDIUMTEXT NOT NULL COMMENT 'Textfassung des Inhalts (Anzeige, Suche)',
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        COMMENT 'SHA-256 des kanonischen JSON',
    author_name  VARCHAR(255) NULL COMMENT 'Freitext "erfasst von" (keine Benutzerverwaltung)',
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_record_versions_record_version (record_id, version),
    CONSTRAINT fk_patient_record_versions_record FOREIGN KEY (record_id) REFERENCES patient_records (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
