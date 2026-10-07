<?php

declare(strict_types=1);

namespace App\Mapping;

use InvalidArgumentException;

/**
 * Ordnet Merlin-Quellformat-Parameter Kategorien zu. Unabhaengig von der PDF-Logik.
 */
final class ParameterMapping
{
    /** @var array<string, array{label: string, sort: int}> */
    private array $categories;
    /** @var array<string, array{category: string, display_name: ?string}> */
    private array $byId = [];
    /** @var array<string, string> */
    private array $byName = [];
    /** @var list<array{pattern: string, category: string}> */
    private array $namePatterns;
    private string $fallback;
    private string $version;
    /** @var array<string, array{ids: list<string>, names: list<string>}> */
    private array $fields;
    /** @var array<string, string> */
    private array $leadFields;
    /** @var list<array{pattern: string, key: string, label: string}> */
    private array $leadChambers;
    /** @var array<string, array{system: string, code: string, source?: string}> */
    private array $standardCodes;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->version = (string) ($config['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $this->version)) {
            throw new InvalidArgumentException('Mapping-Version fehlt oder ist ungueltig.');
        }

        $this->categories = [];
        foreach ($config['categories'] ?? [] as $key => $category) {
            $this->categories[(string) $key] = ['label' => (string) $category['label'], 'sort' => (int) $category['sort']];
        }
        $this->fallback = (string) ($config['fallback_category'] ?? 'other');
        if (!isset($this->categories[$this->fallback])) {
            throw new InvalidArgumentException('Fallback-Kategorie ist nicht definiert.');
        }

        foreach ($config['by_id'] ?? [] as $id => $entry) {
            $entry = is_array($entry) ? $entry : ['category' => $entry];
            $this->assertCategory((string) $entry['category']);
            $this->byId[(string) $id] = [
                'category' => (string) $entry['category'],
                'display_name' => isset($entry['display_name']) ? (string) $entry['display_name'] : null,
            ];
        }
        foreach ($config['by_name'] ?? [] as $name => $category) {
            $this->assertCategory((string) $category);
            $this->byName[mb_strtolower(trim((string) $name))] = (string) $category;
        }
        $this->namePatterns = [];
        foreach ($config['name_patterns'] ?? [] as $rule) {
            $this->assertCategory((string) $rule['category']);
            if (@preg_match((string) $rule['pattern'], '') === false) {
                throw new InvalidArgumentException('Ungueltiges Muster: ' . $rule['pattern']);
            }
            $this->namePatterns[] = ['pattern' => (string) $rule['pattern'], 'category' => (string) $rule['category']];
        }

        $this->fields = [];
        foreach ($config['fields'] ?? [] as $key => $spec) {
            $this->fields[(string) $key] = [
                'ids' => array_map('strval', $spec['ids'] ?? []),
                'names' => array_map('strval', $spec['names'] ?? []),
            ];
        }
        $this->leadFields = array_map('strval', $config['lead_fields'] ?? []);
        $this->leadChambers = array_values($config['lead_chambers'] ?? []);
        $this->standardCodes = $config['standard_codes'] ?? [];
    }

    public static function fromFile(string $path): self
    {
        $config = require $path;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Mapping-Datei liefert kein Array.');
        }
        return new self($config);
    }

    public static function default(): self
    {
        return self::fromFile(dirname(__DIR__, 2) . '/config/parameter_mapping.php');
    }

    public function version(): string
    {
        return $this->version;
    }

    public function resolve(string $parameterId, string $name): CategoryAssignment
    {
        if (isset($this->byId[$parameterId])) {
            $entry = $this->byId[$parameterId];
            return $this->assignment($entry['category'], $entry['display_name'] ?? $name, CategoryAssignment::SOURCE_ID);
        }
        $normalized = mb_strtolower(trim($name));
        if (isset($this->byName[$normalized])) {
            return $this->assignment($this->byName[$normalized], $name, CategoryAssignment::SOURCE_NAME);
        }
        foreach ($this->namePatterns as $rule) {
            if (preg_match($rule['pattern'], $name) === 1) {
                return $this->assignment($rule['category'], $name, CategoryAssignment::SOURCE_PATTERN);
            }
        }
        return $this->assignment($this->fallback, $name, CategoryAssignment::SOURCE_NONE);
    }

    /**
     * @return array<string, array{label: string, sort: int}>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * @return array<string, array{ids: list<string>, names: list<string>}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, string>
     */
    public function leadFields(): array
    {
        return $this->leadFields;
    }

    /**
     * @return array{key: string, label: string}
     */
    public function leadChamber(string $sourceChamber): array
    {
        foreach ($this->leadChambers as $rule) {
            if (preg_match((string) $rule['pattern'], $sourceChamber) === 1) {
                return ['key' => (string) $rule['key'], 'label' => (string) $rule['label']];
            }
        }
        $key = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $sourceChamber));
        return ['key' => trim($key, '_') ?: 'unknown', 'label' => $sourceChamber];
    }

    /**
     * @return array{system: string, code: string}|null
     */
    public function standardCode(string $parameterId): ?array
    {
        $entry = $this->standardCodes[$parameterId] ?? null;
        return $entry === null ? null : ['system' => (string) $entry['system'], 'code' => (string) $entry['code']];
    }

    private function assignment(string $category, string $displayName, string $source): CategoryAssignment
    {
        $meta = $this->categories[$category];
        return new CategoryAssignment($category, $meta['label'], $meta['sort'], $displayName, $source);
    }

    private function assertCategory(string $key): void
    {
        if (!isset($this->categories[$key])) {
            throw new InvalidArgumentException('Unbekannte Kategorie im Mapping: ' . $key);
        }
    }
}
