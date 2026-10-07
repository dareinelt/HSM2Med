-- HSM2Med – Migration 002: Patientenausweis
--
-- Grundsaetze (analog zu Migration 001):
--  * Der Patientenausweis ist ein unveraenderliches Dokument. Das erzeugte PDF wird als
--    MEDIUMBLOB mit SHA-256 archiviert und nie neu berechnet; Aenderungen an den Stammdaten
--    koennen bereits erstellte Ausweise daher nicht mehr veraendern.
--  * Die Patientenidentitaet ist die Kombination Nachname + Vorname + Geburtsdatum
--    (patients.identity_key). Patienten-ID/Seriennummer sind nur Zusatzangaben und duerfen
--    die Identitaet nicht allein bestimmen.
--  * patient_card_master_data haelt den aktuellen, vom Benutzer bestaetigten Datenstand.
--    Der Ausweis selbst haelt seinen eigenen Snapshot (JSON) und ist davon unabhaengig.
--  * Leere Werte werden als '' gespeichert, NULL bedeutet "Feld nicht vorhanden".
--  * Fremdschluessel durchgaengig ON DELETE RESTRICT; Ausweise werden nicht geloescht.

-- Identitaet im Patienten-Stammdatensatz (Nachname + Vorname + Geburtsdatum).
-- identity_key ist NULL, solange eine der drei Angaben fehlt. Der Index ist bewusst NICHT
-- eindeutig: Mehrere Patienten mit gleichem Namen und Geburtsdatum sind moeglich (z. B. bei
-- wechselnder Patienten-ID) und muessen vom Benutzer entschieden werden. Der Import wird
-- dadurch nie an einer Eindeutigkeitsverletzung scheitern.
ALTER TABLE patients
    ADD COLUMN last_name  VARCHAR(255) NULL AFTER patient_name,
    ADD COLUMN first_name VARCHAR(255) NULL AFTER last_name,
    ADD COLUMN identity_key VARCHAR(512) COLLATE utf8mb4_bin
        AS (IF(last_name IS NULL OR first_name IS NULL OR date_of_birth IS NULL, NULL,
               CONCAT(LOWER(TRIM(last_name)), '|', LOWER(TRIM(first_name)), '|',
                      DATE_FORMAT(date_of_birth, '%Y-%m-%d')))) STORED
        COMMENT 'Nachname|Vorname|Geburtsdatum (normalisiert), NULL wenn unvollstaendig',
    ADD KEY idx_patients_identity (identity_key);

-- Logo-Speicher: inhaltsadressiert und unveraenderlich (gleiche Datei = gleicher Datensatz).
CREATE TABLE patient_card_logos (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sha256     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mime_type  VARCHAR(64) NOT NULL,
    filename   VARCHAR(200) NOT NULL COMMENT 'bereinigter Originalname, nur zur Anzeige',
    width      INT UNSIGNED NOT NULL,
    height     INT UNSIGNED NOT NULL,
    content    MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_card_logos_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Aktuelle, global administrierbare Stammdaten (genau eine Zeile mit id = 1).
CREATE TABLE patient_card_settings (
    id               TINYINT UNSIGNED NOT NULL,
    center_name      VARCHAR(255) NULL COMMENT 'Nachsorgezentrum: Bezeichnung',
    center_address   TEXT NULL COMMENT 'Nachsorgezentrum: Adresse (mehrzeilig)',
    notice_text      TEXT NULL COMMENT 'Hinweise auf dem Ausweis',
    flight_notice_de TEXT NULL COMMENT 'Achtung Flugsicherheit (deutsch)',
    flight_notice_en TEXT NULL COMMENT 'Attention Airline Security (englisch)',
    logo_id          BIGINT UNSIGNED NULL,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_patient_card_settings_logo (logo_id),
    CONSTRAINT fk_patient_card_settings_logo FOREIGN KEY (logo_id) REFERENCES patient_card_logos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Unveraenderliche Stammdaten-Fassungen: jede Aenderung erzeugt eine neue Fassung.
-- Der Patientenausweis verweist auf die beim Erstellen gueltige Fassung.
CREATE TABLE patient_card_settings_versions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    center_name      VARCHAR(255) NULL,
    center_address   TEXT NULL,
    notice_text      TEXT NULL,
    flight_notice_de TEXT NULL,
    flight_notice_en TEXT NULL,
    logo_id          BIGINT UNSIGNED NULL,
    created_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_patient_card_settings_versions_logo (logo_id),
    CONSTRAINT fk_patient_card_settings_versions_logo FOREIGN KEY (logo_id) REFERENCES patient_card_logos (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Aktueller, bestaetigter Patientenausweis-Datenstand je Patient (Adresse, Notfallkontakt,
-- Hausarzt, Kontrolle). Der Ausweis selbst haelt seinen eigenen Snapshot.
CREATE TABLE patient_card_master_data (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id              BIGINT UNSIGNED NOT NULL,
    street                  VARCHAR(255) NULL,
    postal_code             VARCHAR(32) NULL,
    city                    VARCHAR(255) NULL,
    phone                   VARCHAR(64) NULL,
    indication              TEXT NULL,
    device_implant_location VARCHAR(255) NULL COMMENT 'Implantationsort des Geraets (nicht aus dem Merlin-Export ableitbar)',
    emergency_contact_name  VARCHAR(255) NULL,
    emergency_contact_phone VARCHAR(64) NULL,
    physician_name          VARCHAR(255) NULL,
    physician_practice      VARCHAR(255) NULL,
    physician_postal_code   VARCHAR(32) NULL,
    physician_city          VARCHAR(255) NULL,
    physician_phone         VARCHAR(64) NULL,
    control_physician       VARCHAR(255) NULL COMMENT 'Arzt zur Kontrolle',
    next_control_date       DATE NULL,
    next_control_raw        VARCHAR(64) NULL,
    created_at              DATETIME NOT NULL,
    updated_at              DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_card_master_data_patient (patient_id),
    CONSTRAINT fk_patient_card_master_data_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Patientenausweise: ein unveraenderliches Dokument je Erstellung.
-- Mehrere Ausweise pro Bericht sind moeglich (z. B. nach Korrektur der Patientendaten);
-- aeltere Ausweise bleiben unveraendert erhalten (card_version = 1, 2, ... je Bericht).
CREATE TABLE patient_cards (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id         BIGINT UNSIGNED NOT NULL,
    report_id          BIGINT UNSIGNED NOT NULL,
    settings_version_id BIGINT UNSIGNED NOT NULL COMMENT 'beim Erstellen gueltige Stammdaten-Fassung',
    sequence_no        INT UNSIGNED NOT NULL COMMENT 'laufende Nummer je Patient',
    card_version       SMALLINT UNSIGNED NOT NULL COMMENT 'Fassung je Bericht, beginnend bei 1',
    last_name          VARCHAR(255) NOT NULL,
    first_name         VARCHAR(255) NOT NULL,
    date_of_birth      DATE NOT NULL,
    patient_name       VARCHAR(512) NOT NULL COMMENT 'Anzeige/Suche: Nachname, Vorname',
    follow_up_date     DATE NULL COMMENT 'Datum der zugrunde liegenden Nachsorge',
    snapshot           JSON NOT NULL COMMENT 'vollstaendiger, unveraenderlicher Datenstand des Ausweises',
    pdf_filename       VARCHAR(200) NOT NULL,
    pdf_sha256         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pdf_size           INT UNSIGNED NOT NULL,
    pdf_content        MEDIUMBLOB NOT NULL,
    created_at         DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_cards_sequence (patient_id, sequence_no),
    KEY idx_patient_cards_report (report_id, card_version),
    KEY idx_patient_cards_patient_date (patient_id, follow_up_date),
    KEY idx_patient_cards_created (created_at),
    CONSTRAINT fk_patient_cards_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_cards_report FOREIGN KEY (report_id) REFERENCES reports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_cards_settings_version FOREIGN KEY (settings_version_id) REFERENCES patient_card_settings_versions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
