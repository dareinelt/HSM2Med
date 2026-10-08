-- HSM2Med – vollstaendiges Datenbankschema (MySQL 9.7)
-- AUTOMATISCH ERZEUGT mit: php bin/build-schema.php – nicht manuell bearbeiten.
-- Quelle: database/migrations/*.sql. Fuer Neuinstallationen ohne Migrator nutzbar:
--   mysql -u root -p hsm2med < database/schema.sql
-- Die Eintraege in schema_migrations verhindern eine erneute Ausfuehrung der Migrationen.

SET NAMES utf8mb4;

CREATE TABLE schema_migrations (
    version    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME NOT NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ===== Migration 001_initial =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('001_initial', '79cab9de337bbbdcf7eaa5a8c4bb359cde5a085dbb4a5044cf817afeac78ca63', NOW());

-- ===== Migration 002_patient_card =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('002_patient_card', 'f5c8d0c979c727521d1d199399da8f68e042b1cabb3e5606cfdbd3eb0340c243', NOW());

-- ===== Migration 003_patient_records =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('003_patient_records', '2e7a9d32b0dc201d7a884d0678aca23beb73aa0837fadea7b7216ad2414b9d3c', NOW());

-- ===== Migration 004_patient_card_mrt =====

-- HSM2Med – Migration 004: MRT-Tauglichkeit auf Seite 1 des Patientenausweises
--
-- Grundsaetze (analog zu Migration 002):
--  * Der Patientenausweis bleibt bei genau zwei DIN-A4-Seiten. Die Angabe zur MRT-Tauglichkeit
--    wird auf Seite 1 im Abschnitt "Implantate" gedruckt und braucht dort keinen zusaetzlichen
--    Seitenumbruch.
--  * Die Angabe steht im Merlin-Export nicht zur Verfuegung und wird daher vom Benutzer gepflegt.
--    Sie gehoert zu Geraet und Sonden und wird je Patient in patient_card_master_data gehalten
--    (Vorbelegung des Assistenten) und im unveraenderlichen Snapshot des Ausweises archiviert.
--  * mrt_compatibility enthaelt einen der festen Auswahlwerte
--    ('MRT-tauglich', 'MRT-bedingt tauglich', 'nicht MRT-tauglich', 'unbekannt').
--    Leer bedeutet "nicht angegeben"; die Gueltigkeit prueft die Anwendung
--    (PatientCardInput::MRT_VALUES).
--  * mrt_compatibility_note ist eine kurze Zusatzangabe (z. B. Bedingungen). Die Laenge ist
--    bewusst begrenzt, damit Seite 1 des Ausweises sicher passt
--    (PatientCardPdfGenerator::MAX_MRT_CHARS).
--  * Fremdschluessel und Unveraenderlichkeit bestehender Ausweise bleiben unberuehrt: bereits
--    erzeugte Ausweise enthalten die Angabe nicht und werden nicht neu berechnet.

ALTER TABLE patient_card_master_data
    ADD COLUMN mrt_compatibility VARCHAR(64) NULL
        COMMENT 'MRT-Tauglichkeit (Ausweis Seite 1), fester Auswahlwert' AFTER device_implant_location,
    ADD COLUMN mrt_compatibility_note VARCHAR(120) NULL
        COMMENT 'Zusatzangabe zur MRT-Tauglichkeit (z. B. Bedingungen)' AFTER mrt_compatibility;

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('004_patient_card_mrt', '8e9e51354f24dcbf67823043a8c4d5f75acddb498cf3f591ec8828506298aa98', NOW());

-- ===== Migration 005_patient_record_device_check =====

-- HSM2Med – Migration 005: Baustein "Schrittmacher-/ICD-Abfrage"
--
-- Grundsaetze (analog zu Migration 003):
--  * Die Abfrage ist ein zusaetzlicher Bausteintyp der Akte (device_check). Sie folgt damit
--    denselben Regeln: ein Behaelter je Patient (patient_records), unveraenderliche Fassungen
--    in patient_record_versions, inhaltsgleiche Speicherungen erzeugen keine neue Fassung.
--  * Es werden keine neuen Tabellen und keine neuen Spalten benoetigt. Der Inhalt der Abfrage
--    ist strukturiertes JSON in patient_record_versions.content:
--      {"template":"1.0.0","device_type":"pacemaker","values":{...},"leads":[...],"notes":""}
--    Die Feldliste und die je Geraetetyp zulaessigen Abschnitte stehen in
--    config/device_check_template.php; die Fassung der Vorlage wird im Inhalt mitgefuehrt.
--  * content_text enthaelt weiterhin die Textfassung (Anzeige, Suche, spaeterer Brief).
--  * Die Aufzaehlung ist die einzige Stelle, an der ein neuer Bausteintyp ergaenzt werden muss;
--    der neue Wert 'device_check' muss exakt dem Wert von PatientRecordType::DeviceCheck
--    entsprechen.
--  * Bestehende Akteneintraege bleiben unberuehrt: die Erweiterung einer ENUM-Spalte aendert
--    vorhandene Zeilen nicht.

ALTER TABLE patient_records
    MODIFY COLUMN record_type ENUM('anamnesis','premedication','epicrisis','note','device_check') NOT NULL
        COMMENT 'Bausteintyp der Akte';

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('005_patient_record_device_check', '4611796b2e6713c55e0288bf3c74ff53ee2fd8b53be0c4bae1044e94b451652a', NOW());

-- ===== Migration 006_patient_letters =====

-- HSM2Med – Migration 006: Brief zur Schrittmacher-/ICD-Abfrage
--
-- Grundsaetze (analog zu Migration 002):
--  * Ein Brief ist ein unveraenderliches Dokument: Snapshot (JSON) und PDF (MEDIUMBLOB +
--    SHA-256) werden gemeinsam in einer Transaktion gespeichert und nie ueberschrieben.
--  * Der Snapshot friert alles ein, was zum Drucken gebraucht wird: Patientendaten, Fassung
--    der Stammdaten (patient_card_settings_versions), die Fassungen der Bausteine (Anamnese,
--    Vormedikation, Epikrise, Schrittmacher-/ICD-Abfrage) und die Befunddaten des gewaehlten
--    Berichts. Das PDF ist damit allein aus dem Snapshot reproduzierbar.
--  * report_id ist optional (NULL): ein Brief kann auch ohne Bericht erstellt werden. Dann
--    entfaellt der Befundteil; die Bausteine werden trotzdem gedruckt.
--  * sequence_no ist die laufende Nummer je Patient; letter_version die Fassung je Patient und
--    zugrunde liegendem Bericht (ohne Bericht: je Patient). Beides dient der Nachvollziehbarkeit
--    und der Dokumentnummer im Fuss des Briefes.
--  * Der Brief darf mehrere Seiten haben (Anhang mit der vollstaendigen Tabelle der Abfrage).
--    Die Begrenzung auf zwei Seiten gilt ausschliesslich fuer den Patientenausweis.
--  * Es werden keine bestehenden Tabellen geaendert; bereits erzeugte Ausweise und
--    Akteneintraege bleiben unberuehrt.

CREATE TABLE patient_letters (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id          BIGINT UNSIGNED NOT NULL,
    report_id           BIGINT UNSIGNED NULL COMMENT 'zugrunde liegender Bericht (Befundteil), optional',
    settings_version_id BIGINT UNSIGNED NOT NULL COMMENT 'beim Erstellen gueltige Stammdaten-Fassung',
    sequence_no         INT UNSIGNED NOT NULL COMMENT 'laufende Nummer je Patient',
    letter_version      SMALLINT UNSIGNED NOT NULL COMMENT 'Fassung je Patient und Bericht, beginnend bei 1',
    last_name           VARCHAR(255) NOT NULL,
    first_name          VARCHAR(255) NOT NULL,
    date_of_birth       DATE NOT NULL,
    patient_name        VARCHAR(512) NOT NULL COMMENT 'Anzeige/Suche: Nachname, Vorname',
    letter_date         DATE NULL COMMENT 'Datum der zugrunde liegenden Untersuchung',
    snapshot            JSON NOT NULL COMMENT 'vollstaendiger, unveraenderlicher Datenstand des Briefes',
    pdf_filename        VARCHAR(200) NOT NULL,
    pdf_sha256          CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    pdf_size            INT UNSIGNED NOT NULL,
    pdf_content         MEDIUMBLOB NOT NULL,
    created_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_patient_letters_sequence (patient_id, sequence_no),
    KEY idx_patient_letters_report (report_id, letter_version),
    KEY idx_patient_letters_patient_date (patient_id, letter_date),
    KEY idx_patient_letters_created (created_at),
    CONSTRAINT fk_patient_letters_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_letters_report FOREIGN KEY (report_id) REFERENCES reports (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_letters_settings_version FOREIGN KEY (settings_version_id) REFERENCES patient_card_settings_versions (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('006_patient_letters', '2e38c5888efcac10f237f03e92cee447d008d381ca4e65078ff30c28bb1ce26a', NOW());

-- ===== Migration 007_letter_templates =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('007_letter_templates', 'fe40c5380d95491af646b90e748d080f3de291bdd51273c12a62a14b8bc0fd7f', NOW());

-- ===== Migration 008_letter_recipients =====

-- HSM2Med – Migration 008: Briefempfaenger (Patient, Hausarzt, ueberweisender Arzt)
--
-- Grundsaetze:
--  * Die Stammdaten eines Patienten fuehren neben dem Hausarzt (jetzt mit Strasse) den
--    ueberweisenden Arzt. Beide liefern die Anschrift fuer das Anschriftfeld des Briefes.
--  * Der Brief-Assistent erzeugt je ausgewaehltem Empfaenger einen eigenen, unveraenderlichen
--    Brief. Die Anschrift wird im Snapshot eingefroren; recipient_type und recipient_name
--    dienen nur der Anzeige in Listen. Briefe vor dieser Migration haben keinen Empfaenger
--    (NULL) und behalten das Anschriftfeld ihrer Vorlage.

ALTER TABLE patient_card_master_data
    ADD COLUMN physician_street      VARCHAR(255) NULL COMMENT 'Hausarzt: Strasse und Hausnummer' AFTER physician_practice,
    ADD COLUMN referrer_name         VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Name' AFTER physician_phone,
    ADD COLUMN referrer_practice     VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Praxis' AFTER referrer_name,
    ADD COLUMN referrer_street       VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Strasse und Hausnummer' AFTER referrer_practice,
    ADD COLUMN referrer_postal_code  VARCHAR(32) NULL COMMENT 'Ueberweisender Arzt: Postleitzahl' AFTER referrer_street,
    ADD COLUMN referrer_city         VARCHAR(255) NULL COMMENT 'Ueberweisender Arzt: Ort' AFTER referrer_postal_code,
    ADD COLUMN referrer_phone        VARCHAR(64) NULL COMMENT 'Ueberweisender Arzt: Telefon' AFTER referrer_city;

ALTER TABLE patient_letters
    ADD COLUMN recipient_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL
        COMMENT 'patient, family_doctor oder referring_physician; NULL bei Briefen vor Migration 008' AFTER source_letter_id,
    ADD COLUMN recipient_name VARCHAR(512) NULL COMMENT 'Empfaenger fuer Listen (Anschrift im Snapshot)' AFTER recipient_type,
    ADD KEY idx_patient_letters_recipient (patient_id, recipient_type);

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('008_letter_recipients', '97d7f995f74241442156f62f836c9e073de42642b6a00e9bec9251f27ffaf4c9', NOW());

-- ===== Migration 009_letter_template_types =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('009_letter_template_types', 'b6f27e90f66f7bcc0563bfd5d35b2a7784509fec075f319e0ec4ce780a2440a8', NOW());

-- ===== Migration 010_practice_settings =====

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

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('010_practice_settings', '4d4c11743d70e6613e9cd5759400de098ced06873e5994283d299ae39d825015', NOW());

-- ===== Migration 011_patient_record_befund =====

-- HSM2Med – Migration 011: Baustein "Befund"
--
-- Grundsaetze (analog zu Migration 003 und 005):
--  * Der Befund ist ein zusaetzlicher Bausteintyp der Akte (befund). Er folgt denselben Regeln:
--    ein Behaelter je Patient (patient_records), unveraenderliche Fassungen in
--    patient_record_versions, inhaltsgleiche Speicherungen erzeugen keine neue Fassung.
--  * Es werden keine neuen Tabellen und keine neuen Spalten benoetigt. Der Befund ist ein
--    Freitext des Arztes; er liegt wie bei Anamnese, Epikrise und Notiz in content_text.
--  * Der Baustein ersetzt im Brief den bisherigen Befundteil "Befund (Bericht)": dort steht
--    kuenftig der eingefrorene Befund des Arztes. Der Befundteil des Berichts erscheint im
--    Brief als eigener Baustein "Berichte" auf einer neuen Seite.
--  * Die Aufzaehlung ist die einzige Stelle, an der ein neuer Bausteintyp ergaenzt werden muss;
--    der neue Wert 'befund' muss exakt dem Wert von PatientRecordType::Befund entsprechen.
--  * Bestehende Akteneintraege bleiben unberuehrt: die Erweiterung einer ENUM-Spalte am Ende
--    der Werteliste aendert vorhandene Zeilen nicht.

ALTER TABLE patient_records
    MODIFY COLUMN record_type ENUM('anamnesis','premedication','epicrisis','note','device_check','befund') NOT NULL
        COMMENT 'Bausteintyp der Akte';

INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('011_patient_record_befund', 'aa6d358f471c5a358a6c925bbc1e4abfdc715751268ccc6c30193dd98a308ded', NOW());
