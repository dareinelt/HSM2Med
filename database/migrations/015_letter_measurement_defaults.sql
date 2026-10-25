-- HSM2Med – Migration 015: Standardauswahl der Messwerttabelle im Brief
--
-- Grundsaetze:
--  * Die Messwertauswahl des Befundbriefes laesst sich als applikationsweiter Standard
--    speichern. Neue Briefe haben diese Messwerte dann vorausgewaehlt; der Anwender entscheidet
--    im Assistenten nach einer Aenderung der Auswahl, ob die Einstellung gemerkt wird.
--  * Es gibt genau eine Zeile (id = 1). Der Standard ist eine reine Arbeitserleichterung und
--    aendert keinen bestehenden Brief: jeder Brief friert seine Auswahl im Snapshot ein.
--  * Gespeichert werden die Schluessel der Messwertzeilen des festen Katalogs
--    (Abschnitt.Gruppe.Zeile). Passen sie nach einer Katalogaenderung nicht mehr, greift die
--    Vorauswahl nur fuer die weiterhin vorhandenen Zeilen; bleibt nichts uebrig, sind wie bisher
--    alle verfuegbaren Messwerte vorausgewaehlt.

CREATE TABLE letter_measurement_defaults (
    id         TINYINT UNSIGNED NOT NULL,
    selected   JSON NOT NULL COMMENT 'Schluessel der vorausgewaehlten Messwertzeilen',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    COMMENT='Applikationsweite Standardauswahl der Messwerttabelle im Brief';
