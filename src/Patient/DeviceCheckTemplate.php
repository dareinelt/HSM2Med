<?php

declare(strict_types=1);

namespace App\Patient;

use InvalidArgumentException;

/**
 * Vorlage des Aktenbausteins "Schrittmacher-/ICD-Abfrage" (config/device_check_template.php).
 *
 * Die Klasse kennt nur die Vorlage: Abschnitte, Felder, Grenzen und die Zuordnung der Felder
 * zu den Quellen im importierten Bericht. Sie liefert die je Geraetetyp gueltigen Abschnitte
 * und Felder; welche Werte darin stehen, entscheidet ausschliesslich der erfasste Inhalt.
 *
 * Es werden keine Werte erfunden und keine medizinischen Bewertungen abgeleitet.
 */
final class DeviceCheckTemplate
{
    public const string FILE = '/config/device_check_template.php';

    private string $version;
    private int $maxLeads;
    private int $maxValue;
    private int $maxNotes;
    /** @var array<string, string> */
    private array $deviceTypes = [];
    /** @var list<array<string, mixed>> */
    private array $leadFields = [];
    /** @var list<array<string, mixed>> */
    private array $sections = [];
    /** @var array<string, true> */
    private array $ids = [];
    /** @var array<string, true> */
    private array $names = [];
    /** @var list<string> */
    private array $parameterIds = [];
    /** @var list<string> */
    private array $parameterNames = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->version = (string) ($config['version'] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+$/', $this->version) !== 1) {
            throw new InvalidArgumentException('Version der Abfragevorlage fehlt oder ist ungueltig.');
        }

        $deviceTypes = $config['device_types'] ?? [];
        if (!is_array($deviceTypes) || $deviceTypes === []) {
            throw new InvalidArgumentException('Die Abfragevorlage enthaelt keine Geraetetypen.');
        }
        foreach ($deviceTypes as $value => $label) {
            $value = (string) $value;
            $label = trim((string) $label);
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $value) !== 1 || $label === '') {
                throw new InvalidArgumentException('Ungueltiger Geraetetyp in der Abfragevorlage: ' . $value);
            }
            $this->deviceTypes[$value] = $label;
        }

        $this->maxLeads = max(1, (int) ($config['max_leads'] ?? 1));
        $this->maxValue = max(1, (int) ($config['max_value'] ?? 1));
        $this->maxNotes = max(0, (int) ($config['max_notes'] ?? 0));

        $leadFields = $config['lead_fields'] ?? [];
        if (!is_array($leadFields) || $leadFields === []) {
            throw new InvalidArgumentException('Die Abfragevorlage enthaelt keine Sondenfelder.');
        }
        $leadKeys = [];
        foreach ($leadFields as $field) {
            $field = $this->field((array) $field, 'Sonden');
            if (isset($leadKeys[$field['name']])) {
                throw new InvalidArgumentException('Doppeltes Sondenfeld: ' . $field['name']);
            }
            $leadKeys[$field['name']] = true;
            $this->leadFields[] = $field;
        }

        $sections = $config['sections'] ?? [];
        if (!is_array($sections) || $sections === []) {
            throw new InvalidArgumentException('Die Abfragevorlage enthaelt keine Abschnitte.');
        }
        $sectionKeys = [];
        foreach ($sections as $section) {
            $section = $this->section((array) $section);
            if (isset($sectionKeys[$section['key']])) {
                throw new InvalidArgumentException('Doppelter Abschnitt der Abfragevorlage: ' . $section['key']);
            }
            $sectionKeys[$section['key']] = true;
            $this->sections[] = $section;
        }

        foreach ($this->deviceTypes as $value => $label) {
            if ($this->sectionsFor($value) === []) {
                throw new InvalidArgumentException('Geraetetyp ohne Abschnitt in der Abfragevorlage: ' . $value);
            }
        }

        $this->parameterIds = array_keys($this->ids);
        $this->parameterNames = array_keys($this->names);
    }

    public static function fromFile(string $path): self
    {
        $config = require $path;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Vorlage der Abfrage liefert kein Array.');
        }
        return new self($config);
    }

    public static function default(string $rootDir): self
    {
        return self::fromFile($rootDir . self::FILE);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function maxLeads(): int
    {
        return $this->maxLeads;
    }

    public function maxValue(): int
    {
        return $this->maxValue;
    }

    public function maxNotes(): int
    {
        return $this->maxNotes;
    }

    /**
     * @return array<string, string> Geraetetyp => Beschriftung
     */
    public function deviceTypes(): array
    {
        return $this->deviceTypes;
    }

    public function hasDeviceType(string $value): bool
    {
        return isset($this->deviceTypes[$value]);
    }

    public function deviceTypeLabel(string $value): string
    {
        return $this->deviceTypes[$value] ?? $value;
    }

    /**
     * Sondenfelder, die fuer den Geraetetyp gelten.
     *
     * @return list<array<string, mixed>>
     */
    public function leadFields(string $deviceType): array
    {
        return array_values(array_filter(
            $this->leadFields,
            fn (array $field): bool => $this->applies($field['devices'], $deviceType),
        ));
    }

    /**
     * Abschnitte, die fuer den Geraetetyp gelten (Felder und Gruppen bereits gefiltert).
     *
     * @return list<array<string, mixed>>
     */
    public function sectionsFor(string $deviceType): array
    {
        $sections = [];
        foreach ($this->sections as $section) {
            if (!$this->applies($section['devices'], $deviceType)) {
                continue;
            }
            $copy = $section;
            $copy['fields'] = array_values(array_filter(
                $section['fields'],
                fn (array $field): bool => $this->applies($field['devices'], $deviceType),
            ));
            $groups = [];
            foreach ($section['groups'] as $group) {
                $fields = array_values(array_filter(
                    $group['fields'],
                    fn (array $field): bool => $this->applies($field['devices'], $deviceType),
                ));
                if ($fields === []) {
                    continue;
                }
                $group['fields'] = $fields;
                $groups[] = $group;
            }
            $copy['groups'] = $groups;
            if ($copy['fields'] === [] && $groups === [] && $copy['repeat'] === null) {
                continue;
            }
            $sections[] = $copy;
        }
        return $sections;
    }

    /**
     * Alle Abschnitte mit vollstaendigen Feldpfaden fuer das Formular.
     *
     * Anders als sectionsFor() sind hier alle Geraetetypen enthalten; die Abschnitte und
     * Felder tragen ihre Geraetetypen ("devices"), damit das Formular sie abhaengig von der
     * gewaehlten Geraeteart ein- und ausblenden kann. Ohne JavaScript bleibt alles sichtbar;
     * gueltig bleibt serverseitig ausschliesslich der gewaehlte Geraetetyp.
     *
     * @return list<array<string, mixed>>
     */
    public function formSections(): array
    {
        $sections = [];
        foreach ($this->sections as $section) {
            $copy = $section;
            $copy['fields'] = array_map(
                fn (array $field): array => $field + ['path' => $section['key'] . '.' . $field['name']],
                $section['fields'],
            );
            $groups = [];
            foreach ($section['groups'] as $group) {
                $group['fields'] = array_map(
                    fn (array $field): array => $field + ['path' => $section['key'] . '.' . $group['key'] . '.' . $field['name']],
                    $group['fields'],
                );
                $groups[] = $group;
            }
            $copy['groups'] = $groups;
            $sections[] = $copy;
        }
        return $sections;
    }

    /**
     * Alle Sondenfelder (fuer die wiederholbaren Zeilen des Formulars).
     *
     * @return list<array<string, mixed>>
     */
    public function allLeadFields(): array
    {
        return $this->leadFields;
    }

    /**
     * Alle Felder eines Geraetetyps, Schluessel => Felddefinition. Der Schluessel ist der
     * punktierte Pfad im Inhalt der Fassung (z. B. "device.serial", "tachy.vt1.rate").
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(string $deviceType): array
    {
        $fields = [];
        foreach ($this->sectionsFor($deviceType) as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$section['key'] . '.' . $field['name']] = $field + ['section' => $section['key']];
            }
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $fields[$section['key'] . '.' . $group['key'] . '.' . $field['name']] = $field
                        + ['section' => $section['key'], 'group' => $group['key']];
                }
            }
        }
        return $fields;
    }

    /**
     * Alle Sondenfelder eines Geraetetyps, Schluessel => Felddefinition ("leads.model").
     *
     * @return array<string, array<string, mixed>>
     */
    public function leadFieldMap(string $deviceType): array
    {
        $fields = [];
        foreach ($this->leadFields($deviceType) as $field) {
            $fields['leads.' . $field['name']] = $field;
        }
        return $fields;
    }

    /**
     * Alle Felddefinitionen unabhaengig vom Geraetetyp (Beschriftung und Reihenfolge).
     *
     * @return array<string, array<string, mixed>>
     */
    public function allFields(): array
    {
        $fields = [];
        foreach (array_keys($this->deviceTypes) as $deviceType) {
            $fields += $this->fields($deviceType);
        }
        return $fields;
    }

    /**
     * Parameter-IDs der Vorlage (fuer eine gebuendelte Datenbankabfrage).
     *
     * @return list<string>
     */
    public function parameterIds(): array
    {
        return $this->parameterIds;
    }

    /**
     * Parameterbezeichnungen der Vorlage, normalisiert.
     *
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return $this->parameterNames;
    }

    /**
     * @param list<string>|null $devices
     */
    private function applies(?array $devices, string $deviceType): bool
    {
        return $devices === null || in_array($deviceType, $devices, true);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function field(array $field, string $section): array
    {
        $name = trim((string) ($field['key'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1) {
            throw new InvalidArgumentException('Ungueltiger Feldname im Abschnitt ' . $section . ': ' . $name);
        }
        $label = trim((string) ($field['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Feld ohne Beschriftung im Abschnitt ' . $section . ': ' . $name);
        }

        $devices = $field['devices'] ?? null;
        if ($devices !== null) {
            $devices = array_values(array_map('strval', (array) $devices));
            foreach ($devices as $device) {
                if (!$this->hasDeviceType($device)) {
                    throw new InvalidArgumentException('Unbekannter Geraetetyp am Feld ' . $name . ': ' . $device);
                }
            }
        }

        $options = $field['options'] ?? null;
        if ($options !== null) {
            $options = array_values(array_filter(array_map(
                static fn (mixed $option): string => trim((string) $option),
                (array) $options,
            ), static fn (string $option): bool => $option !== ''));
            if ($options === []) {
                throw new InvalidArgumentException('Auswahlfeld ohne Werte: ' . $name);
            }
        }

        $type = (string) ($field['type'] ?? 'text');
        if (!in_array($type, ['text', 'date'], true)) {
            throw new InvalidArgumentException('Unbekannter Feldtyp am Feld ' . $name . ': ' . $type);
        }

        $maxLength = (int) ($field['maxlength'] ?? $this->maxValue);
        if ($maxLength < 1) {
            throw new InvalidArgumentException('Ungueltige Laengengrenze am Feld ' . $name);
        }

        $sources = [];
        foreach ((array) ($field['sources'] ?? []) as $source) {
            $source = (array) $source;
            $ids = array_values(array_unique(array_map('strval', (array) ($source['ids'] ?? []))));
            $names = [];
            foreach ((array) ($source['names'] ?? []) as $sourceName) {
                $normalized = mb_strtolower(trim((string) $sourceName));
                if ($normalized !== '') {
                    $names[] = $normalized;
                }
            }
            $names = array_values(array_unique($names));
            if ($ids === [] && $names === []) {
                throw new InvalidArgumentException('Quelle ohne ID und Bezeichnung am Feld ' . $name);
            }
            foreach ($ids as $id) {
                $this->ids[$id] = true;
            }
            foreach ($names as $sourceName) {
                $this->names[$sourceName] = true;
            }
            $sources[] = ['ids' => $ids, 'names' => $names];
        }

        $fromSummary = trim((string) ($field['from_summary'] ?? ''));
        $fromCard = trim((string) ($field['from_card'] ?? ''));

        return [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'maxlength' => $maxLength,
            'options' => $options,
            'devices' => $devices,
            'sources' => $sources,
            'glue' => (string) ($field['glue'] ?? ''),
            'from_summary' => $fromSummary === '' ? null : $fromSummary,
            'from_card' => $fromCard === '' ? null : $fromCard,
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    private function section(array $section): array
    {
        $key = trim((string) ($section['key'] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key) !== 1) {
            throw new InvalidArgumentException('Ungueltiger Abschnitt der Abfragevorlage: ' . $key);
        }
        $label = trim((string) ($section['label'] ?? ''));
        if ($label === '') {
            throw new InvalidArgumentException('Abschnitt ohne Beschriftung: ' . $key);
        }

        $devices = $section['devices'] ?? null;
        if ($devices !== null) {
            $devices = array_values(array_map('strval', (array) $devices));
            foreach ($devices as $device) {
                if (!$this->hasDeviceType($device)) {
                    throw new InvalidArgumentException('Unbekannter Geraetetyp am Abschnitt ' . $key . ': ' . $device);
                }
            }
        }

        $fields = [];
        $fieldKeys = [];
        foreach ((array) ($section['fields'] ?? []) as $field) {
            $field = $this->field((array) $field, $key);
            if (isset($fieldKeys[$field['name']])) {
                throw new InvalidArgumentException('Doppeltes Feld im Abschnitt ' . $key . ': ' . $field['name']);
            }
            $fieldKeys[$field['name']] = true;
            $fields[] = $field;
        }

        $groups = [];
        $groupKeys = [];
        foreach ((array) ($section['groups'] ?? []) as $group) {
            $group = (array) $group;
            $groupKey = trim((string) ($group['key'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $groupKey) !== 1) {
                throw new InvalidArgumentException('Ungueltige Gruppe im Abschnitt ' . $key . ': ' . $groupKey);
            }
            if (isset($groupKeys[$groupKey])) {
                throw new InvalidArgumentException('Doppelte Gruppe im Abschnitt ' . $key . ': ' . $groupKey);
            }
            $groupKeys[$groupKey] = true;
            $groupLabel = trim((string) ($group['label'] ?? ''));
            if ($groupLabel === '') {
                throw new InvalidArgumentException('Gruppe ohne Beschriftung: ' . $key . '.' . $groupKey);
            }
            $groupFields = [];
            foreach ((array) ($group['fields'] ?? []) as $field) {
                $groupFields[] = $this->field((array) $field, $key . '.' . $groupKey);
            }
            if ($groupFields === []) {
                throw new InvalidArgumentException('Gruppe ohne Felder: ' . $key . '.' . $groupKey);
            }
            $groups[] = ['key' => $groupKey, 'label' => $groupLabel, 'fields' => $groupFields];
        }

        $repeat = $section['repeat'] ?? null;
        $repeat = $repeat === null ? null : trim((string) $repeat);
        if ($repeat !== null && $repeat !== 'leads') {
            throw new InvalidArgumentException('Unbekannte Wiederholung im Abschnitt ' . $key . ': ' . $repeat);
        }
        if ($fields === [] && $groups === [] && $repeat === null) {
            throw new InvalidArgumentException('Abschnitt ohne Felder: ' . $key);
        }

        return [
            'key' => $key,
            'label' => $label,
            'devices' => $devices,
            'fields' => $fields,
            'groups' => $groups,
            'repeat' => $repeat,
        ];
    }
}
