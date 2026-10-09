<?php

declare(strict_types=1);

namespace App\PatientCard;

use DateTimeImmutable;

/**
 * Ausweisvorlage: Aufbau und alle festen Texte des Patientenausweises.
 *
 * Aufbau des Vorlagen-JSON (schema 1):
 *   {
 *     "schema": 1,
 *     "name": "Standardvorlage",
 *     "zones":  { "<zone>": {"options": {...}, "texts": {...}}, ... },
 *     "blocks": [ {"id": "...", "type": "...", "enabled": true, "options": {...}, "texts": {...}}, ... ]
 *   }
 *
 * Zonen haben eine feste Lage auf der Seite (Kopf- und Fusszeile des Ausweises); ihre Texte sind
 * bearbeitbar. Bausteine bilden die Abschnitte des Ausweises; ihre Reihenfolge ist frei
 * (Drag and Drop im Editor). Feste Texte duerfen Platzhalter enthalten ({patient_name} usw.);
 * sie werden beim Erzeugen ausschliesslich aus dem Snapshot des Ausweises gefuellt.
 *
 * Eine Ausweisvorlage gilt fuer alle Ausweise eines Nachsorgezentrums und hat einen eigenen,
 * unveraenderlichen Fassungsverlauf (patient_card_template_versions). Der Inhalt der beim
 * Erzeugen gueltigen Fassung wird im Ausweis-Snapshot eingefroren; Vorlagenaenderungen wirken
 * sich daher nicht rueckwirkend auf bereits erstellte Ausweise aus.
 *
 * Die Vorlage enthaelt keine medizinischen Bewertungen: alle Abschnitte geben ausschliesslich
 * Angaben aus dem Ausweis-Snapshot wieder.
 */
final class PatientCardTemplate
{
    public const int SCHEMA = 1;


    public const int MAX_BLOCKS = 30;
    private const int MAX_SINGLE = 300;
    private const int MAX_MULTI = 4000;
    private const int MAX_NAME = 200;

    /** Bezeichnung der Vorlage (der Ausweis kennt keine Empfaengerarten). */
    public const string LABEL = 'Patientenausweis';

    /** Platzhalter => Beschreibung (fuer Editor und Pruefung). */
    public const array PLACEHOLDERS = [
        'center_name' => 'Name des Nachsorgezentrums (aus den Ausweis-Stammdaten)',
        'center_address' => 'Anschrift des Nachsorgezentrums (aus den Ausweis-Stammdaten)',
        'patient_name' => 'Patientenname (NACHNAME, VORNAME)',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'date_of_birth' => 'Geburtsdatum (TT.MM.JJJJ)',
        'patient_identifier' => 'Patienten-ID',
        'device_model' => 'Gerät (Hersteller, Modell und Modellnummer)',
        'serial_number' => 'Seriennummer des Geräts',
        'report_date' => 'Datum des zugeordneten Berichts (TT.MM.JJJJ)',
        'next_control' => 'Nächste Kontrolle (TT.MM.JJJJ)',
        'sequence_no' => 'laufende Ausweisnummer des Patienten',
        'card_version' => 'Fassung des Ausweises (Zaehler je Bericht)',
        'created_at' => 'Erstellungszeitpunkt des Ausweises (TT.MM.JJJJ HH:MM:SS)',
    ];

    /** Nur in der Seitenangabe der Fusszeile zulaessig. */
    public const array PAGE_PLACEHOLDERS = [
        'page' => 'aktuelle Seite',
        'pages' => 'Seitenzahl gesamt',
    ];

    /**
     * Zonen des Ausweises mit fester Lage.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function zoneDefinitions(): array
    {
        return [
            'header' => [
                'label' => 'Kopfbereich',
                'description' => 'Logo und Überschrift auf Seite 1 sowie die Kopfzeile ab Seite 2.',
                'options' => [
                    'show_logo' => ['label' => 'Logo anzeigen', 'type' => 'bool', 'default' => true],
                    'show_title' => ['label' => 'Überschrift anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Schrittmacher - Patientenausweis'],
                    'subtitle' => ['label' => 'Unterzeile', 'multiline' => false, 'default' => '(Patient Identification Card)'],
                    'continuation' => [
                        'label' => 'Kopfzeile ab Seite 2',
                        'multiline' => false,
                        'default' => 'Patientenausweis · {patient_name} · Geburtsdatum {date_of_birth} · Gerät {device_model} · Seriennummer {serial_number}',
                    ],
                ],
            ],
            'footer' => [
                'label' => 'Fußzeile',
                'description' => 'Fußzeile jeder Seite: Hinweis zur automatischen Erzeugung und Seitenangabe.',
                'options' => [
                    'show_disclaimer' => ['label' => 'Hinweis zur automatischen Erzeugung anzeigen', 'type' => 'bool', 'default' => true],
                    'show_meta' => ['label' => 'Erstellungsdatum und Ausweisfassung anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'disclaimer' => [
                        'label' => 'Hinweis',
                        'multiline' => false,
                        'default' => 'Automatisch erzeugter Patientenausweis auf Basis importierter Nachsorgeberichte – keine medizinische Bewertung oder Diagnose.',
                    ],
                    'meta' => [
                        'label' => 'Zusatzzeile (Erstellungsdatum)',
                        'multiline' => false,
                        'default' => 'Erstellt am {created_at} · {patient_name} · Ausweisfassung {card_version}',
                    ],
                    'page_label' => [
                        'label' => 'Seitenangabe',
                        'multiline' => false,
                        'default' => 'Seite {page} von {pages}',
                        'page' => true,
                    ],
                ],
            ],
            'general' => [
                'label' => 'Allgemein',
                'description' => 'Texte, die in mehreren Bausteinen verwendet werden.',
                'options' => [],
                'texts' => [
                    'empty' => ['label' => 'Platzhaltertext für fehlende Angaben', 'multiline' => false, 'default' => 'nicht angegeben'],
                ],
            ],
        ];
    }

    /**
     * Bausteine des Ausweises: die Abschnitte von Seite 1 und die Messwerttabelle auf Seite 2.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function blockDefinitions(): array
    {
        return [
            'patient_data' => [
                'label' => 'Patientendaten',
                'description' => 'Name, Geburtsdatum, Anschrift, Telefon und Indikation des Patienten (Seite 1, linke Spalte).',
                'unique' => true,
                'options' => [
                    'show_indication' => ['label' => 'Indikation anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Patientendaten:'],
                    'label_name' => ['label' => 'Beschriftung Name', 'multiline' => false, 'default' => 'Name'],
                    'label_birth' => ['label' => 'Beschriftung Geburtsdatum', 'multiline' => false, 'default' => 'geboren am:'],
                    'label_street' => ['label' => 'Beschriftung Straße', 'multiline' => false, 'default' => 'Straße:'],
                    'label_city' => ['label' => 'Beschriftung PLZ/Wohnort', 'multiline' => false, 'default' => 'PLZ/Wohnort:'],
                    'label_phone' => ['label' => 'Beschriftung Telefon', 'multiline' => false, 'default' => 'Telefon:'],
                    'label_indication' => ['label' => 'Beschriftung Indikation', 'multiline' => false, 'default' => 'Indikation:'],
                ],
            ],
            'emergency_contact' => [
                'label' => 'Notfallkontakt',
                'description' => 'Name und Telefon des Notfallkontakts (Seite 1, linke Spalte).',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Notfallkontakt:'],
                    'label_name' => ['label' => 'Beschriftung Name', 'multiline' => false, 'default' => 'Name:'],
                    'label_phone' => ['label' => 'Beschriftung Telefon', 'multiline' => false, 'default' => 'Telefon:'],
                ],
            ],
            'physician' => [
                'label' => 'Hausarzt',
                'description' => 'Name, Praxis, Ort und Telefon des betreuenden Hausarztes (Seite 1, linke Spalte).',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Hausarzt:'],
                    'label_name' => ['label' => 'Beschriftung Name', 'multiline' => false, 'default' => 'Name:'],
                    'label_practice' => ['label' => 'Beschriftung Praxis-Adresse', 'multiline' => false, 'default' => 'Praxis-Adresse:'],
                    'label_city' => ['label' => 'Beschriftung PLZ/Ort', 'multiline' => false, 'default' => 'PLZ/Ort:'],
                    'label_phone' => ['label' => 'Beschriftung Telefon', 'multiline' => false, 'default' => 'Telefon:'],
                ],
            ],
            'center' => [
                'label' => 'Betreuendes Nachsorgezentrum',
                'description' => 'Name und Anschrift des betreuenden Nachsorgezentrums (Seite 1, linke Spalte).',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Betreuendes Nachsorgezentrum:'],
                    'extra' => ['label' => 'Zusatzzeilen unter der Anschrift', 'multiline' => true, 'default' => ''],
                ],
            ],
            'implants' => [
                'label' => 'Implantate',
                'description' => 'Tabelle des Schrittmachers und der Elektroden mit Modell, Lokalisation und Implantationsdatum (Seite 1, rechte Spalte).',
                'unique' => true,
                'options' => [
                    'show_leads' => ['label' => 'Elektrodentabelle anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'Implantate:'],
                    'device_title' => ['label' => 'Zwischenüberschrift Gerät', 'multiline' => false, 'default' => 'Schrittmacher:'],
                    'lead_title' => ['label' => 'Zwischenüberschrift Elektroden', 'multiline' => false, 'default' => 'Elektroden:'],
                    'lead_empty' => ['label' => 'Text ohne Elektrodendaten', 'multiline' => false, 'default' => 'Für diesen Bericht sind keine Elektrodendaten hinterlegt.'],
                    'col_model' => ['label' => 'Spaltenkopf Modell', 'multiline' => false, 'default' => 'Modell'],
                    'col_location' => ['label' => 'Spaltenkopf Implantationsort', 'multiline' => false, 'default' => 'Impl.Ort'],
                    'col_localization' => ['label' => 'Spaltenkopf Lokalisation', 'multiline' => false, 'default' => 'Lokalisation'],
                    'col_date' => ['label' => 'Spaltenkopf Datum', 'multiline' => false, 'default' => 'Impl.Datum'],
                ],
            ],
            'mrt' => [
                'label' => 'MRT-Tauglichkeit',
                'description' => 'Angabe zur MRT-Tauglichkeit des implantierten Systems (Seite 1, rechte Spalte).',
                'unique' => true,
                'options' => [],
                'texts' => [
                    'title' => ['label' => 'Überschrift', 'multiline' => false, 'default' => 'MRT-Tauglichkeit:'],
                ],
            ],
            'notice' => [
                'label' => 'Hinweise',
                'description' => 'Hinweistexte aus den Ausweis-Stammdaten und die Hinweise zur Flugsicherheit (Seite 1, rechte Spalte).',
                'unique' => true,
                'options' => [
                    'show_flight' => ['label' => 'Hinweise zur Flugsicherheit anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'title' => ['label' => 'Überschrift Hinweise', 'multiline' => false, 'default' => 'Hinweise:'],
                    'flight_title_de' => ['label' => 'Überschrift Flugsicherheit (deutsch)', 'multiline' => false, 'default' => 'Achtung Flugsicherheit:'],
                    'flight_title_en' => ['label' => 'Überschrift Flugsicherheit (englisch)', 'multiline' => false, 'default' => 'Attention Airline Security:'],
                ],
            ],
            'summary' => [
                'label' => 'Abschlussblock',
                'description' => 'Abschlussblock am Fuß von Seite 1 mit Bericht, Bemerkung, Arzt, nächster Kontrolle und dem Barcode der Patientenkennung.',
                'unique' => true,
                'options' => [
                    'show_barcode' => ['label' => 'Barcode anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'label_other' => ['label' => 'Beschriftung Sonstiges', 'multiline' => false, 'default' => 'Sonstiges'],
                    'label_remark' => ['label' => 'Beschriftung Bemerkung', 'multiline' => false, 'default' => 'Bemerkung'],
                    'label_physician' => ['label' => 'Beschriftung Arzt', 'multiline' => false, 'default' => 'Arzt'],
                    'label_next_control' => ['label' => 'Beschriftung nächste Kontrolle', 'multiline' => false, 'default' => 'Nächste Kontrolle in'],
                ],
            ],
            'measurements' => [
                'label' => 'Messwerttabelle',
                'description' => 'Messwerte der aktuellen Untersuchung und der letzten früheren Untersuchungen (Seite 2). Die Messwerte selbst stammen aus der Messwertvorlage der Anwendung.',
                'unique' => true,
                'options' => [
                    'show_notes' => ['label' => 'Hinweise zur Messwerttabelle anzeigen', 'type' => 'bool', 'default' => true],
                ],
                'texts' => [
                    'current_label' => ['label' => 'Zusatz am Spaltenkopf der aktuellen Untersuchung', 'multiline' => false, 'default' => '(aktuelle Untersuchung)'],
                    'empty' => ['label' => 'Text ohne Messwerte', 'multiline' => false, 'default' => 'Es sind keine Messwerte dieses Patienten gespeichert.'],
                    'notes_heading' => ['label' => 'Überschrift der Hinweise', 'multiline' => false, 'default' => 'Hinweise zur Messwerttabelle / Notes on the measurements'],
                    'notes' => [
                        'label' => 'Hinweistext zur Messwerttabelle',
                        'multiline' => true,
                        'default' => 'Dargestellt sind die Messwerte der aktuellen Untersuchung und der bis zu sechs letzten früheren Untersuchungen dieses Patienten, jeweils mit dem Datum der Untersuchung als Spaltenkopf. Leere Zellen bedeuten, dass der jeweilige Bericht keinen Wert enthält. Änderungen an Stammdaten oder später importierte Berichte verändern diesen Ausweis nicht.',
                    ],
                ],
            ],
            'text' => [
                'label' => 'Freier Textbaustein',
                'description' => 'Eigener fester Text mit optionaler Überschrift; wird auf Seite 2 unter der Messwerttabelle gedruckt und ist beliebig oft verwendbar.',
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
     * Reihenfolge der Bausteine in der Standardvorlage: erst die Abschnitte von Seite 1
     * (linke Spalte, rechte Spalte), danach der Abschlussblock und die Messwerttabelle.
     */
    private const array DEFAULT_ORDER = [
        'patient_data', 'emergency_contact', 'physician', 'center', 'implants', 'mrt', 'notice', 'summary', 'measurements',
    ];

    /**
     * Feste Lage der Bausteine auf dem Ausweis: linke und rechte Spalte auf Seite 1, der
     * Abschlussblock ueber die volle Breite am Fuss von Seite 1 sowie Messwerte und freie Texte
     * auf Seite 2. Innerhalb eines Bereichs gilt die Reihenfolge der Vorlage.
     *
     * @var array<string, string>
     */
    public const array AREAS = [
        'patient_data' => 'left',
        'emergency_contact' => 'left',
        'physician' => 'left',
        'center' => 'left',
        'implants' => 'right',
        'mrt' => 'right',
        'notice' => 'right',
        'summary' => 'bottom',
        'measurements' => 'page2',
        'text' => 'page2',
    ];

    /**
     * Bausteine frueherer Vorlagenfassungen, die unter neuem Typ weitergefuehrt werden.
     *
     * @var array<string, string>
     */
    private const array LEGACY_BLOCK_TYPES = [];

    /**
     * Lage eines Bausteins ("left", "right", "bottom" oder "page2").
     */
    public static function area(string $type): string
    {
        return self::AREAS[$type] ?? 'page2';
    }

    /**
     * Name der Standardvorlage.
     */
    public static function defaultName(): string
    {
        return 'Standardvorlage';
    }

    /**
     * Standardvorlage (Fassung 1 bei Inbetriebnahme, Vorlage fuer "Auf Standard zuruecksetzen").
     *
     * @return array<string, mixed>
     */
    public static function default(): array
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
        return ['schema' => self::SCHEMA, 'name' => self::defaultName(), 'zones' => $zones, 'blocks' => $blocks];
    }

    /**
     * Definition fuer den Editor (Zonen, Bausteine, Platzhalter).
     *
     * @return array<string, mixed>
     */
    public static function editorDefinition(): array
    {
        return [
            'schema' => self::SCHEMA,
            'kind' => 'patient_card',
            'label' => self::LABEL,
            'type' => '',
            'types' => [],
            'zones' => self::zoneDefinitions(),
            'blocks' => self::blockDefinitions(),
            'areas' => self::AREAS,
            'placeholders' => self::PLACEHOLDERS,
            'pagePlaceholders' => self::PAGE_PLACEHOLDERS,
            'maxBlocks' => self::MAX_BLOCKS,
            'limits' => ['single' => self::MAX_SINGLE, 'multi' => self::MAX_MULTI, 'name' => self::MAX_NAME],
            'default' => self::default(),
        ];
    }

    /**
     * Prueft eine Vorlage und bringt sie in die kanonische Form. Unbekannte Schluessel werden
     * verworfen, fehlende mit den Standardwerten ergaenzt.
     *
     * @return array<string, mixed>
     * @throws PatientCardException mit Feldfehlern
     */
    public static function normalize(mixed $template): array
    {
        if (!is_array($template)) {
            throw PatientCardException::validation(['template' => 'Die Vorlage ist kein gültiges JSON-Objekt.']);
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
            $type = self::LEGACY_BLOCK_TYPES[$type] ?? $type;
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
            throw PatientCardException::validation($errors);
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
     * Werte der Platzhalter der Vorlage, ausschliesslich aus dem Ausweis-Snapshot.
     * Grundlage fuer PDF-Erzeugung und Live-Vorschau im Editor.
     *
     * @param array<string, mixed> $card vollstaendiger Ausweis-Snapshot
     * @return array<string, string>
     */
    public static function values(array $card, DateTimeImmutable $generatedAt): array
    {
        $patient = is_array($card['patient'] ?? null) ? $card['patient'] : [];
        $device = is_array($card['device'] ?? null) ? $card['device'] : [];
        $settings = is_array($card['settings'] ?? null) ? $card['settings'] : [];
        $followUp = is_array($card['follow_up'] ?? null) ? $card['follow_up'] : [];
        $model = trim((string) ($device['model_name'] ?? '') . ' ' . (string) ($device['model_number'] ?? ''));

        return [
            'center_name' => (string) ($settings['center_name'] ?? ''),
            'center_address' => (string) ($settings['center_address'] ?? ''),
            'patient_name' => (string) ($patient['patient_name'] ?? ''),
            'first_name' => (string) ($patient['first_name'] ?? ''),
            'last_name' => (string) ($patient['last_name'] ?? ''),
            'date_of_birth' => (string) ($patient['date_of_birth_display'] ?? ''),
            'patient_identifier' => (string) ($patient['patient_identifier'] ?? ''),
            'device_model' => $model,
            'serial_number' => (string) ($device['serial_number'] ?? ''),
            'report_date' => (string) ($followUp['report_date_display'] ?? ''),
            'next_control' => (string) ($followUp['next_control_display'] ?? ''),
            'sequence_no' => (string) ($card['sequence_no'] ?? ''),
            'card_version' => (string) ($card['card_version'] ?? ''),
            'created_at' => $generatedAt->format('d.m.Y H:i:s'),
        ];
    }

    /**
     * Ersetzt Platzhalter. Unbekannte Platzhalter bleiben sichtbar stehen.
     *
     * @param array<string, string|int|float> $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0],
            $text,
        );
    }

    /**
     * Baustein einer Vorlage nach Typ (null, wenn der Typ nicht vorkommt oder ausgeblendet ist).
     *
     * @param array<string, mixed> $template kanonische Vorlage
     * @return array<string, mixed>|null
     */
    public static function block(array $template, string $type): ?array
    {
        foreach ((array) ($template['blocks'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === $type) {
                return ($block['enabled'] ?? true) === false ? null : $block;
            }
        }
        return null;
    }

    /**
     * Bausteine einer Vorlage in ihrer Reihenfolge (ohne ausgeblendete).
     *
     * @param array<string, mixed> $template kanonische Vorlage
     * @return list<array<string, mixed>>
     */
    public static function enabledBlocks(array $template): array
    {
        $blocks = [];
        foreach ((array) ($template['blocks'] ?? []) as $block) {
            if (is_array($block) && ($block['enabled'] ?? true) !== false) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * Text eines Bausteins (kanonisch ergaenzt um den Standardwert).
     *
     * @param array<string, mixed>|null $block
     */
    public static function blockText(?array $block, string $key, string $default = ''): string
    {
        if ($block === null) {
            return $default;
        }
        $texts = is_array($block['texts'] ?? null) ? $block['texts'] : [];
        $value = $texts[$key] ?? $default;
        return self::string($value);
    }

    /**
     * Schalterwert eines Bausteins (Standard: true).
     *
     * @param array<string, mixed>|null $block
     */
    public static function blockOption(?array $block, string $key, bool $default = true): bool
    {
        if ($block === null) {
            return false;
        }
        $options = is_array($block['options'] ?? null) ? $block['options'] : [];
        $value = $options[$key] ?? $default;
        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * Text einer Zone (kanonisch ergaenzt um den Standardwert).
     *
     * @param array<string, mixed> $template kanonische Vorlage
     */
    public static function zoneText(array $template, string $zone, string $key, string $default = ''): string
    {
        $zones = is_array($template['zones'] ?? null) ? $template['zones'] : [];
        $texts = is_array($zones[$zone]['texts'] ?? null) ? $zones[$zone]['texts'] : [];
        return self::string($texts[$key] ?? $default);
    }

    /**
     * Schalterwert einer Zone (Standard: true).
     *
     * @param array<string, mixed> $template kanonische Vorlage
     */
    public static function zoneOption(array $template, string $zone, string $key, bool $default = true): bool
    {
        $zones = is_array($template['zones'] ?? null) ? $template['zones'] : [];
        $options = is_array($zones[$zone]['options'] ?? null) ? $zones[$zone]['options'] : [];
        $value = $options[$key] ?? $default;
        return $value === true || $value === 1 || $value === '1';
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
