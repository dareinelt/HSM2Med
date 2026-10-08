<?php

declare(strict_types=1);

namespace App\Letter;

/**
 * Briefvorlage (DIN 5008, Form B): Aufbau und alle festen Texte des Briefes.
 *
 * Aufbau des Vorlagen-JSON (schema 1):
 *   {
 *     "schema": 1,
 *     "name": "Standardvorlage",
 *     "zones":  { "<zone>": {"options": {...}, "texts": {...}}, ... },
 *     "blocks": [ {"id": "...", "type": "...", "enabled": true, "options": {...}, "texts": {...}}, ... ]
 *   }
 *
 * Zonen haben nach DIN 5008 eine feste Lage auf der Seite (Briefkopf, Anschriftfeld,
 * Informationsblock, Fusszeile, Anhang); ihre Texte sind bearbeitbar. Bausteine bilden den
 * Brieftext; ihre Reihenfolge ist frei (Drag and Drop im Editor), Textbausteine koennen
 * beliebig oft ergaenzt werden.
 *
 * Feste Texte duerfen Platzhalter enthalten ({patient_name} usw.); sie werden beim Erzeugen
 * ausschliesslich aus dem Snapshot des Briefes gefuellt.
 *
 * Eine Vorlage gilt je Empfaengerart (Patient, Hausarzt, ueberweisender Arzt); die Arten werden
 * getrennt gepflegt und haben je einen eigenen Fassungsverlauf. Die Anrede steht nicht in der
 * Vorlage, sondern in den Stammdaten des Empfaengers ({salutation}, siehe LetterSalutation).
 */
final class LetterTemplate
{
    public const int SCHEMA = 1;

    public const int MAX_BLOCKS = 40;
    private const int MAX_SINGLE = 300;
    private const int MAX_MULTI = 4000;
    private const int MAX_NAME = 200;

    /** Platzhalter => Beschreibung (fuer Editor und Pruefung). */
    public const array PLACEHOLDERS = [
        'center_name' => 'Name des Nachsorgezentrums',
        'center_address_line' => 'Anschrift des Zentrums in einer Zeile',
        'salutation' => 'Anrede des Empfängers (aus den Stammdaten)',
        'patient_name' => 'Patientenname (NACHNAME, VORNAME)',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'date_of_birth' => 'Geburtsdatum (TT.MM.JJJJ)',
        'patient_identifier' => 'Patienten-ID',
        'document_number' => 'Dokumentnummer',
        'letter_date' => 'Briefdatum (TT.MM.JJJJ)',
        'sequence_no' => 'laufende Briefnummer des Patienten',
    ];

    /** Nur in der Seitenangabe der Fusszeile zulaessig. */
    public const array PAGE_PLACEHOLDERS = [
        'page' => 'aktuelle Seite',
        'pages' => 'Seitenzahl gesamt',
    ];

    /**
     * Zonen mit fester Lage nach DIN 5008.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function zoneDefinitions(): array
    {
        return [
            'letterhead' => [
                'label' => 'Briefkopf',
                'description' => 'Logo, Name und Anschrift des Nachsorgezentrums (aus den Ausweis-Stammdaten) im 45 mm hohen Kopfbereich.',
                'options' => [
                    'show_logo' => ['label' => 'Logo anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'extra' => ['label' => 'Zusatzzeilen unter der Anschrift', 'multiline' => true, 'default' => ''],
                ],
            ],
            'return_address' => [
                'label' => 'Rücksendeangabe',
                'description' => 'Kleine Absenderzeile oberhalb der Empfängeranschrift (Anschriftfeld, Zusatz- und Vermerkzone).',
                'options' => [
                    'show' => ['label' => 'Rücksendeangabe drucken', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'text' => ['label' => 'Rücksendeangabe', 'multiline' => false, 'default' => '{center_name} · {center_address_line}'],
                ],
            ],
            'recipient' => [
                'label' => 'Anschriftfeld (Empfänger)',
                'description' => 'Anschriftfeld 85 × 45 mm nach DIN 5008 Form B, passend für Fensterumschläge DL/C5/C4. Die Anschrift stammt aus dem im Brief-Assistenten gewählten Empfänger (Patient, Hausarzt, Überweisender Arzt); je Empfänger entsteht ein eigener Brief.',
                'options' => [
                    'source' => [
                        'label' => 'Empfänger ohne Auswahl im Brief-Assistenten (ältere Briefe, Vorschau)',
                        'type' => 'select',
                        'default' => 'text',
                        'choices' => ['text' => 'fester Text (unten)', 'patient' => 'Patient mit Anschrift aus den Stammdaten'],
                    ],
                ],
                'texts' => [
                    'remark' => ['label' => 'Vermerk (z. B. „Persönlich")', 'multiline' => false, 'default' => ''],
                    'text' => ['label' => 'Empfänger (fester Text)', 'multiline' => true, 'default' => "An die weiterbehandelnden\nÄrztinnen und Ärzte"],
                ],
            ],
            'info_block' => [
                'label' => 'Informationsblock',
                'description' => 'Bezugszeichen rechts neben dem Anschriftfeld (ab 125 mm). Leere Beschriftung blendet die Zeile aus.',
                'options' => [],
                'texts' => [
                    'label_reference' => ['label' => 'Beschriftung Dokumentnummer', 'multiline' => false, 'default' => 'Unser Zeichen'],
                    'label_patient' => ['label' => 'Beschriftung Patient', 'multiline' => false, 'default' => 'Patient'],
                    'label_birth' => ['label' => 'Beschriftung Geburtsdatum', 'multiline' => false, 'default' => 'Geburtsdatum'],
                    'label_identifier' => ['label' => 'Beschriftung Patienten-ID', 'multiline' => false, 'default' => 'Patienten-ID'],
                    'label_sequence' => ['label' => 'Beschriftung Briefnummer', 'multiline' => false, 'default' => 'Brief-Nr.'],
                    'label_settings' => ['label' => 'Beschriftung Stammdatenfassung', 'multiline' => false, 'default' => 'Stammdatenfassung'],
                    'label_reissue' => ['label' => 'Beschriftung Neuausfertigung', 'multiline' => false, 'default' => 'Neuausfertigung von'],
                    'label_date' => ['label' => 'Beschriftung Datum', 'multiline' => false, 'default' => 'Datum'],
                ],
            ],
            'footer' => [
                'label' => 'Fußzeile und Seitenränder',
                'description' => 'Hinweis, Erstellungsangaben und Seitenzahl auf jeder Seite; Falz- und Lochmarken am linken Rand.',
                'options' => [
                    'fold_marks' => ['label' => 'Falz- und Lochmarken drucken', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'disclaimer' => ['label' => 'Hinweis', 'multiline' => true, 'default' => 'Automatisch erzeugter Brief auf Basis der Patientenakte – keine medizinische Bewertung oder Diagnose.'],
                    'page_label' => ['label' => 'Seitenangabe', 'multiline' => false, 'default' => 'Seite {page} von {pages}', 'page' => true],
                    'continuation' => ['label' => 'Kopfzeile der Folgeseiten', 'multiline' => false, 'default' => '{patient_name} · {document_number}'],
                ],
            ],
            'appendix' => [
                'label' => 'Anhang',
                'description' => 'Vollständige Tabelle der Schrittmacher-/ICD-Abfrage auf eigenen Seiten am Ende des Briefes.',
                'options' => [
                    'show' => ['label' => 'Anhang drucken (wenn eine Abfrage vorliegt)', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Anhang: Schrittmacher-/ICD-Abfrage (vollständige Tabelle)'],
                    'intro' => ['label' => 'Einleitung', 'multiline' => false, 'default' => 'Vollständige Angaben aus der Schrittmacher-/ICD-Abfrage'],
                    'column_parameter' => ['label' => 'Spalte Parameter', 'multiline' => false, 'default' => 'Parameter'],
                    'column_value' => ['label' => 'Spalte Wert', 'multiline' => false, 'default' => 'Wert'],
                    'notes_heading' => ['label' => 'Überschrift Bemerkungen', 'multiline' => false, 'default' => 'Bemerkungen'],
                ],
            ],
            'general' => [
                'label' => 'Allgemein',
                'description' => 'Texte, die an mehreren Stellen verwendet werden.',
                'options' => [],
                'texts' => [
                    'empty' => ['label' => 'Fehlende Angabe', 'multiline' => false, 'default' => 'nicht angegeben'],
                ],
            ],
        ];
    }

    /**
     * Bausteine des Brieftextes; die Reihenfolge ist frei.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function blockDefinitions(): array
    {
        $meta = ['label' => 'Stand der Fassung und Autor zeigen', 'type' => 'bool', 'default' => true];
        return [
            'subject' => [
                'label' => 'Betreff',
                'description' => 'Betreffzeile in Fettschrift (DIN 5008: ohne das Wort „Betreff").',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'title' => ['label' => 'Betreff', 'multiline' => false, 'default' => 'Brief zur Schrittmacher-/ICD-Abfrage'],
                    'line2' => ['label' => 'Zweite Betreffzeile', 'multiline' => false, 'default' => '{patient_name}, geboren am {date_of_birth}'],
                ],
            ],
            'salutation' => [
                'label' => 'Anrede',
                'description' => 'Anrede, gefolgt von einer Leerzeile. Der Platzhalter {salutation} wird aus der Anrede in den Stammdaten des Empfängers gefüllt; fehlt die Angabe, erscheint „Sehr geehrte Damen und Herren,“.',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'text' => ['label' => 'Anrede', 'multiline' => false, 'default' => '{salutation}'],
                ],
            ],
            'patient' => [
                'label' => 'Patientendaten',
                'description' => 'Name, Geburtsdatum, Patienten-ID und Anschrift aus dem Snapshot.',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Patientendaten'],
                    'label_name' => ['label' => 'Beschriftung Name', 'multiline' => false, 'default' => 'Name'],
                    'label_birth' => ['label' => 'Beschriftung Geburtsdatum', 'multiline' => false, 'default' => 'Geburtsdatum'],
                    'label_identifier' => ['label' => 'Beschriftung Patienten-ID', 'multiline' => false, 'default' => 'Patienten-ID'],
                    'label_address' => ['label' => 'Beschriftung Anschrift', 'multiline' => false, 'default' => 'Anschrift'],
                    'label_phone' => ['label' => 'Vorsatz Telefon', 'multiline' => false, 'default' => 'Telefon:'],
                ],
            ],
            'anamnesis' => [
                'label' => 'Anamnese',
                'description' => 'Eingefrorene Fassung des Bausteins Anamnese.',
                'unique' => true,
                'options' => ['show_meta' => $meta],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Anamnese'],
                ],
            ],
            'premedication' => [
                'label' => 'Vormedikation',
                'description' => 'Tabelle der Vormedikation, zusätzlich die Textfassung.',
                'unique' => true,
                'options' => ['show_meta' => $meta],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Vormedikation'],
                    'col_substance' => ['label' => 'Spalte Präparat', 'multiline' => false, 'default' => 'Präparat'],
                    'col_dose' => ['label' => 'Spalte Dosis', 'multiline' => false, 'default' => 'Dosis'],
                    'col_schedule' => ['label' => 'Spalte Einnahme', 'multiline' => false, 'default' => 'Einnahme'],
                    'col_reason' => ['label' => 'Spalte Grund', 'multiline' => false, 'default' => 'Grund'],
                    'col_period' => ['label' => 'Spalte Zeitraum', 'multiline' => false, 'default' => 'Zeitraum'],
                ],
            ],
            'report' => [
                'label' => 'Befund (Bericht)',
                'description' => 'Geräte-, Sonden- und Messwerte des zugeordneten Berichts; entfällt ohne Bericht.',
                'unique' => true,
                'options' => ['show_meta' => ['label' => 'Herkunft des Berichts zeigen', 'type' => 'bool', 'default' => true]],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Befund: Schrittmacher-/ICD-Abfrage'],
                ],
            ],
            'epicrisis' => [
                'label' => 'Epikrise',
                'description' => 'Eingefrorene Fassung des Bausteins Epikrise.',
                'unique' => true,
                'options' => ['show_meta' => $meta],
                'texts' => [
                    'heading' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Epikrise'],
                ],
            ],
            'closing' => [
                'label' => 'Grußformel',
                'description' => 'Gruß und Unterschriftsblock (Platz für die Unterschrift wird freigehalten).',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'text' => ['label' => 'Grußformel', 'multiline' => false, 'default' => 'Mit freundlichen Grüßen'],
                    'signature' => ['label' => 'Unterzeichner', 'multiline' => true, 'default' => '{center_name}'],
                ],
            ],
            'text' => [
                'label' => 'Freier Textbaustein',
                'description' => 'Eigener fester Text mit optionaler Überschrift; beliebig oft verwendbar.',
                'unique' => false,
                'options' => [],
                'texts' => [
                    'heading' => ['label' => 'Überschrift (optional)', 'multiline' => false, 'default' => ''],
                    'text' => ['label' => 'Text', 'multiline' => true, 'default' => ''],
                ],
            ],
        ];
    }

    /**
     * Reihenfolge der Bausteine in der Standardvorlage.
     */
    private const array DEFAULT_ORDER = [
        'subject', 'salutation', 'patient', 'anamnesis', 'premedication', 'report', 'epicrisis', 'closing',
    ];

    /**
     * Vorlagen je Empfaengerart: Patient, Hausarzt und ueberweisender Arzt werden getrennt
     * gepflegt. Jede Art hat einen eigenen Fassungsverlauf; die Anrede stammt in allen Arten
     * aus den Stammdaten ({salutation}).
     *
     * @return array<string, string> Empfaengerart => Bezeichnung
     */
    public static function types(): array
    {
        $types = [];
        foreach (LetterRecipient::TYPES as $type) {
            $types[$type] = LetterRecipient::label($type);
        }
        return $types;
    }

    public static function isType(string $type): bool
    {
        return LetterRecipient::isType($type);
    }

    /**
     * Name der Standardvorlage je Empfaengerart.
     */
    public static function defaultName(string $type = LetterRecipient::PATIENT): string
    {
        $label = LetterRecipient::label($type);
        return $type === LetterRecipient::PATIENT || $label === '' ? 'Standardvorlage' : 'Standardvorlage ' . $label;
    }

    /**
     * Standardvorlage je Empfaengerart (Fassung 1 bei Inbetriebnahme, Vorlage fuer
     * "Auf Standard zuruecksetzen"). Der Aufbau ist fuer alle Arten gleich; gepflegt werden die
     * Texte getrennt je Art.
     *
     * @return array<string, mixed>
     */
    public static function default(string $type = LetterRecipient::PATIENT): array
    {
        $zones = [];
        foreach (self::zoneDefinitions() as $key => $definition) {
            $zones[$key] = ['options' => self::defaults($definition['options']), 'texts' => self::defaults($definition['texts'])];
        }
        $blocks = [];
        $definitions = self::blockDefinitions();
        foreach (self::DEFAULT_ORDER as $blockType) {
            $blocks[] = [
                'id' => $blockType,
                'type' => $blockType,
                'enabled' => true,
                'options' => self::defaults($definitions[$blockType]['options']),
                'texts' => self::defaults($definitions[$blockType]['texts']),
            ];
        }
        return ['schema' => self::SCHEMA, 'name' => self::defaultName($type), 'zones' => $zones, 'blocks' => $blocks];
    }

    /**
     * Definition fuer den Editor (Zonen, Bausteine, Platzhalter).
     *
     * @return array<string, mixed>
     */
    public static function editorDefinition(string $type = LetterRecipient::PATIENT): array
    {
        return [
            'schema' => self::SCHEMA,
            'type' => $type,
            'types' => self::types(),
            'zones' => self::zoneDefinitions(),
            'blocks' => self::blockDefinitions(),
            'placeholders' => self::PLACEHOLDERS,
            'pagePlaceholders' => self::PAGE_PLACEHOLDERS,
            'maxBlocks' => self::MAX_BLOCKS,
            'limits' => ['single' => self::MAX_SINGLE, 'multi' => self::MAX_MULTI, 'name' => self::MAX_NAME],
            'default' => self::default($type),
        ];
    }

    /**
     * Prueft eine Vorlage und bringt sie in die kanonische Form. Unbekannte Schluessel werden
     * verworfen, fehlende mit den Standardwerten ergaenzt.
     *
     * @return array<string, mixed>
     * @throws LetterException mit Feldfehlern
     */
    public static function normalize(mixed $template): array
    {
        if (!is_array($template)) {
            throw LetterException::validation(['template' => 'Die Vorlage ist kein gültiges JSON-Objekt.']);
        }
        $errors = [];
        if ((int) ($template['schema'] ?? self::SCHEMA) !== self::SCHEMA) {
            $errors['schema'] = 'Der Aufbau der Vorlage wird nicht unterstützt.';
        }
        $name = trim(self::string($template['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Bitte einen Namen für die Vorlage angeben.';
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = sprintf('Der Name darf höchstens %d Zeichen lang sein.', self::MAX_NAME);
        }

        $zones = [];
        $rawZones = is_array($template['zones'] ?? null) ? $template['zones'] : [];
        foreach (self::zoneDefinitions() as $key => $definition) {
            $raw = is_array($rawZones[$key] ?? null) ? $rawZones[$key] : [];
            $zones[$key] = [
                'options' => self::options($definition['options'], $raw['options'] ?? [], 'zones.' . $key, $errors),
                'texts' => self::texts($definition['texts'], $raw['texts'] ?? [], 'zones.' . $key, $errors),
            ];
        }

        $blocks = [];
        $definitions = self::blockDefinitions();
        $rawBlocks = $template['blocks'] ?? null;
        if (!is_array($rawBlocks) || !array_is_list($rawBlocks)) {
            $errors['blocks'] = 'Die Bausteine fehlen.';
            $rawBlocks = [];
        }
        if (count($rawBlocks) > self::MAX_BLOCKS) {
            $errors['blocks'] = sprintf('Eine Vorlage darf höchstens %d Bausteine enthalten.', self::MAX_BLOCKS);
            $rawBlocks = array_slice($rawBlocks, 0, self::MAX_BLOCKS);
        }
        $seenTypes = [];
        $seenIds = [];
        foreach ($rawBlocks as $index => $raw) {
            $path = 'blocks.' . $index;
            if (!is_array($raw)) {
                $errors[$path] = 'Ungültiger Baustein.';
                continue;
            }
            $type = self::string($raw['type'] ?? '');
            if (!isset($definitions[$type])) {
                $errors[$path] = sprintf('Unbekannter Baustein „%s".', $type);
                continue;
            }
            $definition = $definitions[$type];
            if ($definition['unique'] && isset($seenTypes[$type])) {
                $errors[$path] = sprintf('Der Baustein „%s" darf nur einmal vorkommen.', $definition['label']);
                continue;
            }
            $seenTypes[$type] = true;
            $id = self::string($raw['id'] ?? '');
            if (preg_match('/^[a-z0-9_-]{1,40}$/D', $id) !== 1 || isset($seenIds[$id])) {
                $id = $type;
                $suffix = 2;
                while (isset($seenIds[$id])) {
                    $id = $type . '-' . $suffix++;
                }
            }
            $seenIds[$id] = true;
            $blocks[] = [
                'id' => $id,
                'type' => $type,
                'enabled' => ($raw['enabled'] ?? true) !== false,
                'options' => self::options($definition['options'], $raw['options'] ?? [], $path, $errors),
                'texts' => self::texts($definition['texts'], $raw['texts'] ?? [], $path, $errors),
            ];
        }

        if ($errors !== []) {
            throw LetterException::validation($errors);
        }
        return ['schema' => self::SCHEMA, 'name' => $name, 'zones' => $zones, 'blocks' => $blocks];
    }

    /**
     * Stabile JSON-Darstellung (Grundlage fuer Speicherung und Pruefsumme).
     *
     * @param array<string, mixed> $template
     */
    public static function encode(array $template): string
    {
        return json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ersetzt Platzhalter. Unbekannte Platzhalter bleiben sichtbar stehen.
     *
     * @param array<string, string> $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0],
            $text,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array<string, mixed>
     */
    private static function defaults(array $definitions): array
    {
        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = $definition['default'];
        }
        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, string> $errors
     * @return array<string, bool|string>
     */
    private static function options(array $definitions, mixed $raw, string $path, array &$errors): array
    {
        $raw = is_array($raw) ? $raw : [];
        $values = [];
        foreach ($definitions as $key => $definition) {
            $value = $raw[$key] ?? $definition['default'];
            if ($definition['type'] === 'bool') {
                $values[$key] = $value === true || $value === 1 || $value === '1';
                continue;
            }
            $value = self::string($value);
            if (!isset($definition['choices'][$value])) {
                $errors[$path . '.options.' . $key] = sprintf('Ungültige Auswahl für „%s".', $definition['label']);
                $value = (string) $definition['default'];
            }
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    private static function texts(array $definitions, mixed $raw, string $path, array &$errors): array
    {
        $raw = is_array($raw) ? $raw : [];
        $values = [];
        foreach ($definitions as $key => $definition) {
            $value = array_key_exists($key, $raw) ? self::string($raw[$key]) : (string) $definition['default'];
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $multiline = (bool) $definition['multiline'];
            if (!$multiline) {
                $value = str_replace("\n", ' ', $value);
            }
            $value = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value);
            $field = $path . '.texts.' . $key;
            $max = $multiline ? self::MAX_MULTI : self::MAX_SINGLE;
            if (mb_strlen($value) > $max) {
                $errors[$field] = sprintf('„%s" darf höchstens %d Zeichen lang sein.', $definition['label'], $max);
            }
            $allowed = self::PLACEHOLDERS + (($definition['page'] ?? false) === true ? self::PAGE_PLACEHOLDERS : []);
            if (preg_match_all('/\{([a-z_]+)\}/', $value, $matches) > 0) {
                $unknown = array_values(array_unique(array_diff($matches[1], array_keys($allowed))));
                if ($unknown !== []) {
                    $errors[$field] = sprintf(
                        '„%s" enthält unbekannte Platzhalter: %s.',
                        $definition['label'],
                        implode(', ', array_map(static fn (string $p): string => '{' . $p . '}', $unknown)),
                    );
                }
            }
            $values[$key] = $value;
        }
        return $values;
    }

    private static function string(mixed $value): string
    {
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : '';
        }
        return is_int($value) || is_float($value) ? (string) $value : '';
    }
}
