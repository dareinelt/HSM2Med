-- HSM2Med – Migration 013: Benutzerverwaltung, Gruppen und Rechte
--
-- Grundsaetze:
--  * Ab dieser Migration ist die Anwendung durch eine Anmeldung geschuetzt. Benutzer, Gruppen
--    und die Rechte je Gruppe liegen in der Datenbank.
--  * Kennwoerter werden ausschliesslich als Hash gespeichert (password_hash, bcrypt). Es gibt
--    keine Spalte fuer Klartextkennwoerter. Das Kennwort des ersten Administrators kommt
--    ausschliesslich aus der Umgebung (ADMIN_USERNAME/ADMIN_PASSWORD) und wird von
--    bin/seed-admin.php gesetzt – niemals aus dem Quelltext.
--  * Drei vorgegebene Gruppen werden angelegt: Admin, MFA und Arzt (is_system = 1). Sie koennen
--    nicht geloescht werden; ihre Rechte sind in der Oberflaeche pflegbar.
--  * Rechte sind eine Matrix Gruppe x Bereich. Die Bereiche entsprechen den Kennungen des
--    Funktionsbandes (Ribbon::sections()) zuzueglich 'letter_templates' (Vorlageneditor, eine
--    eigenstaendige Seite) und 'users' (Benutzerverwaltung). Fehlt eine Zeile, ist der Bereich
--    fuer die Gruppe gesperrt.
--  * Benutzer werden nie geloescht, sondern deaktiviert (is_active = 0), damit die Zuordnung
--    historischer Vorgaenge erhalten bleibt. Gruppenmitgliedschaften haengen am Benutzer
--    (ON DELETE CASCADE), weil sie keine fachlichen Daten tragen.
--  * failed_attempts/locked_until ermoeglichen eine Sperre nach zu vielen Fehlanmeldungen ohne
--    zusaetzliche Tabelle.

CREATE TABLE users (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username            VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        COMMENT 'Anmeldename, kleingeschrieben, eindeutig',
    display_name        VARCHAR(128) NOT NULL COMMENT 'Anzeigename (Vor- und Nachname)',
    password_hash       VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
        COMMENT 'password_hash(), niemals Klartext',
    is_active           TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = deaktiviert, Anmeldung gesperrt',
    failed_attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Fehlanmeldungen seit dem letzten Erfolg',
    locked_until        DATETIME NULL COMMENT 'Sperre nach zu vielen Fehlanmeldungen',
    last_login_at       DATETIME NULL COMMENT 'Zeitpunkt der letzten erfolgreichen Anmeldung',
    password_changed_at DATETIME NULL COMMENT 'Zeitpunkt der letzten Kennwortaenderung',
    created_at          DATETIME NOT NULL,
    updated_at          DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Benutzer der Anwendung';

CREATE TABLE user_groups (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Technische Kennung, z. B. admin',
    label       VARCHAR(64) NOT NULL COMMENT 'Anzeigename der Gruppe',
    description VARCHAR(255) NULL COMMENT 'Kurzbeschreibung fuer die Benutzerverwaltung',
    is_system   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = vorgegebene Gruppe, nicht loeschbar',
    sort_order  SMALLINT NOT NULL DEFAULT 100 COMMENT 'Reihenfolge in Listen und Matrix',
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_groups_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Gruppen (Rollen) der Benutzerverwaltung';

CREATE TABLE user_group_members (
    user_id    INT UNSIGNED NOT NULL,
    group_id   TINYINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, group_id),
    KEY idx_user_group_members_group (group_id),
    CONSTRAINT fk_user_group_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_group_members_group FOREIGN KEY (group_id) REFERENCES user_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Zuordnung Benutzer zu Gruppen';

CREATE TABLE user_group_permissions (
    group_id   TINYINT UNSIGNED NOT NULL,
    permission VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Bereichskennung (Ribbon-Section)',
    PRIMARY KEY (group_id, permission),
    CONSTRAINT fk_user_group_permissions_group FOREIGN KEY (group_id) REFERENCES user_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Rechtematrix Gruppe x Bereich';

-- Vorgegebene Gruppen. Die Kennungen entsprechen den Konstanten in App\User\Permission.
INSERT INTO user_groups (code, label, description, is_system, sort_order, created_at, updated_at) VALUES
    ('admin', 'Admin', 'Vollzugriff einschliesslich Benutzerverwaltung', 1, 10, NOW(), NOW()),
    ('mfa', 'MFA', 'Medizinische Fachangestellte: Import, Akten, Ausweise und Briefe', 1, 20, NOW(), NOW()),
    ('arzt', 'Arzt', 'Aerztlicher Dienst: alle medizinischen Bereiche, Vorlagen und Systeminformationen', 1, 30, NOW(), NOW());

-- Admin: alle Bereiche.
INSERT INTO user_group_permissions (group_id, permission)
SELECT g.id, p.permission
FROM user_groups g
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL SELECT 'import' UNION ALL SELECT 'reports'
    UNION ALL SELECT 'imports' UNION ALL SELECT 'patients' UNION ALL SELECT 'patient_cards'
    UNION ALL SELECT 'patient_card_settings' UNION ALL SELECT 'letters' UNION ALL SELECT 'letter_templates'
    UNION ALL SELECT 'system' UNION ALL SELECT 'system_settings' UNION ALL SELECT 'logs'
    UNION ALL SELECT 'users'
) p
WHERE g.code = 'admin';

-- MFA: Tagesgeschaeft (Import, Akten, Ausweise, Briefe) ohne Verwaltung und Vorlagen.
INSERT INTO user_group_permissions (group_id, permission)
SELECT g.id, p.permission
FROM user_groups g
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL SELECT 'import' UNION ALL SELECT 'reports'
    UNION ALL SELECT 'imports' UNION ALL SELECT 'patients' UNION ALL SELECT 'patient_cards'
    UNION ALL SELECT 'letters'
) p
WHERE g.code = 'mfa';

-- Arzt: alle medizinischen Bereiche einschliesslich Stammdaten, Vorlagen und Protokoll.
INSERT INTO user_group_permissions (group_id, permission)
SELECT g.id, p.permission
FROM user_groups g
CROSS JOIN (
    SELECT 'dashboard' AS permission UNION ALL SELECT 'import' UNION ALL SELECT 'reports'
    UNION ALL SELECT 'imports' UNION ALL SELECT 'patients' UNION ALL SELECT 'patient_cards'
    UNION ALL SELECT 'patient_card_settings' UNION ALL SELECT 'letters' UNION ALL SELECT 'letter_templates'
    UNION ALL SELECT 'system' UNION ALL SELECT 'logs'
) p
WHERE g.code = 'arzt';
