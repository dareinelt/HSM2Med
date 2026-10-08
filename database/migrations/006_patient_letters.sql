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
