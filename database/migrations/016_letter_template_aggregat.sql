-- HSM2Med – Migration 016: Baustein "Aggregat" in bestehenden Briefvorlagen
--
-- Grundsaetze:
--  * Der Brief zeigt die Geraetedaten des letzten Imports und die zugehoerigen Sonden
--    (Elektroden) im neuen Baustein "Aggregat" direkt unter den Patientendaten.
--  * Neue Vorlagen enthalten den Baustein von Anfang an (LetterTemplate::default()). Bereits
--    gespeicherte Fassungen werden hier einmalig ergaenzt: der Baustein wird hinter dem
--    Baustein "Patient" eingefuegt. Texte und Optionen fuellen sich beim Lesen aus der
--    Bausteindefinition (LetterTemplate::normalize()), deshalb genuegt hier der Bausteintyp.
--  * Vorlagen ohne Baustein "Patient" bleiben unberuehrt, Vorlagen mit bereits vorhandenem
--    Baustein "Aggregat" ebenfalls (der Baustein darf nur einmal vorkommen).
--  * Es werden keine Tabellen und keine Spalten geaendert; schema.sql bleibt unveraendert.
--  * Die Pruefsumme content_sha256 bleibt wie in Migration 009 und 010 unveraendert: sie
--    beschreibt den Stand bei der Speicherung der Fassung, nicht die Aufwertung.

UPDATE letter_template_versions
    SET content = JSON_ARRAY_INSERT(
        content,
        CONCAT(
            '$.blocks[',
            CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(
                JSON_UNQUOTE(JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'patient')),
                '[', -1), ']', 1) AS UNSIGNED) + 1,
            ']'
        ),
        JSON_OBJECT('id', 'aggregat', 'type', 'aggregat', 'enabled', true)
    )
WHERE JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'patient') IS NOT NULL
  AND JSON_SEARCH(JSON_EXTRACT(content, '$.blocks[*].type'), 'one', 'aggregat') IS NULL;
