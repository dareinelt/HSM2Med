-- HSM2Med – Migration 001: initiales Schema
-- MySQL 9.7 LTS, InnoDB, utf8mb4
--
-- Grundsaetze:
--  * report_parameters ist ein unveraenderlicher Snapshot je Bericht (Name, Wert, Einheit,
--    Kategorie, Position und Rohdatensatz werden zum Importzeitpunkt kopiert).
--  * Leere Werte werden als '' gespeichert, NULL bedeutet "Feld nicht vorhanden".
--  * Parameter-IDs sind Quellformat-IDs des Merlin-Exports, keine Standard-IDs.

CREATE TABLE patients (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_identifier VARCHAR(191) COLLATE utf8mb4_bin NULL COMMENT 'Patient ID aus der Quelldatei',
    patient_name       VARCHAR(512) NULL,
    date_of_birth      DATE NULL COMMENT 'normalisiert; Original in date_of_birth_raw',
    date_of_birth_raw  VARCHAR(64) NULL,
    created_at         DATETIME NOT NULL,
    updated_at         DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patients_identifier (patient_identifier),
    KEY idx_patients_name (patient_name(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE devices (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id       BIGINT UNSIGNED NULL,
    manufacturer     VARCHAR(255) NULL COMMENT 'nur wenn in der Quelldatei enthalten',
    model_name       VARCHAR(255) NULL,
    model_number     VARCHAR(191) COLLATE utf8mb4_bin NULL,
    serial_number    VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    model_number_key VARCHAR(191) COLLATE utf8mb4_bin AS (COALESCE(model_number, '')) STORED NOT NULL,
    implant_date     DATE NULL,
    implant_date_raw VARCHAR(64) NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_serial_model (serial_number, model_number_key),
    KEY idx_devices_patient (patient_id),
    CONSTRAINT fk_devices_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE leads (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id        BIGINT UNSIGNED NOT NULL,
    chamber          VARCHAR(64) NOT NULL COMMENT 'abgeleitet aus dem Parameternamen, z.B. atrial, rv',
    chamber_source   VARCHAR(255) NOT NULL COMMENT 'Kammerbezeichnung wie in der Quelldatei',
    manufacturer     VARCHAR(255) NULL,
    model_label      VARCHAR(255) NULL COMMENT 'Bezeichnung aus dem Parameternamen, z.B. SJM Atrial Lead',
    model_number     VARCHAR(255) NULL,
    serial_number    VARCHAR(191) COLLATE utf8mb4_bin NULL,
    lead_type        VARCHAR(255) NULL,
    implant_date     DATE NULL,
    implant_date_raw VARCHAR(64) NULL,
    created_at       DATETIME NOT NULL,
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_leads_device_chamber (device_id, chamber),
    KEY idx_leads_serial (serial_number),
    CONSTRAINT fk_leads_device FOREIGN KEY (device_id) REFERENCES devices (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE imports (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename           VARCHAR(255) NOT NULL COMMENT 'bereinigter Original-Dateiname (nur Anzeige)',
    file_hash          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 der Originaldatei',
    file_size          BIGINT UNSIGNED NOT NULL,
    encoding           VARCHAR(32) NOT NULL,
    archive_filename   VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '<sha256>.txt im Import-Archiv',
    imported_at        DATETIME NOT NULL,
    parser_version     VARCHAR(16) NOT NULL,
    record_count       INT UNSIGNED NOT NULL,
    valid_record_count INT UNSIGNED NOT NULL,
    warning_count      INT UNSIGNED NOT NULL DEFAULT 0,
    error_count        INT UNSIGNED NOT NULL,
    status             ENUM('completed', 'completed_with_warnings', 'completed_with_errors', 'failed') NOT NULL,
    failure_reason     VARCHAR(500) NULL,
    PRIMARY KEY (id),
    KEY idx_imports_hash (file_hash),
    KEY idx_imports_imported_at (imported_at),
    KEY idx_imports_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE import_errors (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id       BIGINT UNSIGNED NOT NULL,
    severity        ENUM('error', 'warning') NOT NULL,
    error_code      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message         VARCHAR(1000) NOT NULL,
    record_position INT UNSIGNED NULL,
    raw_record      MEDIUMTEXT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_import_errors_import (import_id, severity),
    CONSTRAINT fk_import_errors_import FOREIGN KEY (import_id) REFERENCES imports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE parameter_definitions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_parameter_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Merlin-Quellformat-ID',
    source_name         TEXT NOT NULL COMMENT 'zuletzt gesehene Bezeichnung',
    category_key        VARCHAR(64) NOT NULL COMMENT 'aktuelle Zuordnung (Berichte nutzen ihren eigenen Snapshot)',
    mapping_source      ENUM('id', 'name', 'pattern', 'none') NOT NULL,
    mapping_version     VARCHAR(16) NOT NULL,
    standard_system     VARCHAR(64) NULL COMMENT 'nur explizit dokumentierte Standardzuordnung, sonst NULL',
    standard_code       VARCHAR(64) NULL,
    occurrence_count    INT UNSIGNED NOT NULL DEFAULT 0,
    first_seen_at       DATETIME NOT NULL,
    last_seen_at        DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_parameter_definitions_source (source_parameter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reports (
    id                           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_id                    BIGINT UNSIGNED NOT NULL,
    patient_id                   BIGINT UNSIGNED NULL,
    device_id                    BIGINT UNSIGNED NULL,
    session_timestamp            DATETIME NULL,
    session_timestamp_raw        VARCHAR(64) NULL,
    interrogation_timestamp      DATETIME NULL,
    interrogation_timestamp_raw  VARCHAR(64) NULL,
    report_version               SMALLINT UNSIGNED NOT NULL,
    parser_version               VARCHAR(16) NOT NULL,
    mapping_version              VARCHAR(16) NOT NULL,
    patient_name_snapshot        VARCHAR(512) NULL,
    patient_identifier_snapshot  VARCHAR(191) NULL,
    patient_dob_snapshot         VARCHAR(64) NULL,
    device_manufacturer_snapshot VARCHAR(255) NULL,
    device_model_name_snapshot   VARCHAR(255) NULL,
    device_model_number_snapshot VARCHAR(191) NULL,
    device_serial_snapshot       VARCHAR(191) NULL,
    summary_snapshot             JSON NOT NULL COMMENT 'Kopf-/Zusammenfassungsdaten zum Importzeitpunkt',
    parameter_count              INT UNSIGNED NOT NULL,
    created_at                   DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reports_import (import_id),
    KEY idx_reports_session (session_timestamp),
    KEY idx_reports_created (created_at),
    KEY idx_reports_patient (patient_id),
    KEY idx_reports_device (device_id),
    KEY idx_reports_patient_identifier (patient_identifier_snapshot),
    KEY idx_reports_device_serial (device_serial_snapshot),
    CONSTRAINT fk_reports_import FOREIGN KEY (import_id) REFERENCES imports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reports_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reports_device FOREIGN KEY (device_id) REFERENCES devices (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE report_leads (
    report_id BIGINT UNSIGNED NOT NULL,
    lead_id   BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (report_id, lead_id),
    KEY idx_report_leads_lead (lead_id),
    CONSTRAINT fk_report_leads_report FOREIGN KEY (report_id) REFERENCES reports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_report_leads_lead FOREIGN KEY (lead_id) REFERENCES leads (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE report_parameters (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id               BIGINT UNSIGNED NOT NULL,
    parameter_definition_id BIGINT UNSIGNED NOT NULL,
    parameter_id            VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Merlin-Quellformat-ID',
    parameter_name          MEDIUMTEXT NOT NULL COMMENT 'Originalbezeichnung',
    display_name            MEDIUMTEXT NOT NULL,
    value                   MEDIUMTEXT NULL COMMENT 'Originalwert; leer = leerer String',
    unit                    MEDIUMTEXT NULL COMMENT 'Originaleinheit; leer = leerer String',
    category                VARCHAR(64) NOT NULL COMMENT 'Kategorie-Schluessel (Snapshot)',
    category_label          VARCHAR(128) NOT NULL COMMENT 'Kategorie-Bezeichnung (Snapshot)',
    category_sort           SMALLINT UNSIGNED NOT NULL,
    mapping_source          ENUM('id', 'name', 'pattern', 'none') NOT NULL,
    original_position       INT UNSIGNED NOT NULL,
    raw_record              MEDIUMTEXT NOT NULL,
    created_at              DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_report_parameters_position (report_id, original_position),
    KEY idx_report_parameters_category (report_id, category_sort, original_position),
    KEY idx_report_parameters_parameter (parameter_id),
    KEY idx_report_parameters_definition (parameter_definition_id),
    CONSTRAINT fk_report_parameters_report FOREIGN KEY (report_id) REFERENCES reports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_report_parameters_definition FOREIGN KEY (parameter_definition_id) REFERENCES parameter_definitions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
