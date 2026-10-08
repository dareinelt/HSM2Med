<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Liest das Anwendungsprotokoll (JSON-Zeilen von Logger) fuer die Anzeige in der Oberflaeche.
 *
 * Gelesen wird nur das Ende der Datei (MAX_BYTES), neueste Eintraege zuerst. So bleibt die
 * Seite auch bei grossen Protokollen schnell; aeltere Eintraege stehen weiterhin in der Datei.
 */
final class LogReader
{
    public const int MAX_BYTES = 8 * 1024 * 1024;

    public const array LEVELS = [
        'error' => 'Fehler',
        'warning' => 'Warnung',
        'info' => 'Info',
    ];

    public function __construct(private readonly ?string $logFile)
    {
    }

    public function logFile(): ?string
    {
        return $this->logFile;
    }

    public function available(): bool
    {
        return $this->logFile !== null && is_file($this->logFile) && is_readable($this->logFile);
    }

    /**
     * Normalisiert eine Referenz aus der Fehlerseite (Hex, 1–12 Zeichen) oder liefert ''.
     */
    public static function normalizeReference(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[0-9a-f]{1,12}$/', $value) === 1 ? $value : '';
    }

    /**
     * @param array{ref?: string, level?: string, q?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $ref = self::normalizeReference((string) ($filters['ref'] ?? ''));
        $level = (string) ($filters['level'] ?? '');
        $level = isset(self::LEVELS[$level]) ? $level : '';
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        [$lines, $truncated] = $this->tail();
        $matches = [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $entry = self::parse($lines[$i]);
            if ($entry === null) {
                continue;
            }
            if ($ref !== '' && !str_starts_with($entry['ref'], $ref)) {
                continue;
            }
            if ($level !== '' && $entry['level'] !== $level) {
                continue;
            }
            if ($query !== '' && !str_contains(mb_strtolower($entry['search']), $query)) {
                continue;
            }
            unset($entry['search']);
            $matches[] = $entry;
        }

        return [
            'rows' => array_slice($matches, max(0, $offset), max(0, $limit)),
            'total' => count($matches),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array{0: list<string>, 1: bool} Zeilen und ob der Anfang der Datei abgeschnitten wurde
     */
    private function tail(): array
    {
        if (!$this->available()) {
            return [[], false];
        }
        $size = (int) filesize((string) $this->logFile);
        $handle = @fopen((string) $this->logFile, 'rb');
        if ($handle === false) {
            return [[], false];
        }
        try {
            $truncated = $size > self::MAX_BYTES;
            if ($truncated) {
                fseek($handle, $size - self::MAX_BYTES);
            }
            $content = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $lines = explode("\n", $content);
        if ($truncated) {
            // Die erste Zeile ist angeschnitten.
            array_shift($lines);
        }
        return [array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== '')), $truncated];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $line): ?array
    {
        $data = json_decode($line, true);
        if (!is_array($data) || !isset($data['ref'], $data['message'])) {
            return null;
        }
        $context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $exception = is_array($data['exception'] ?? null) ? $data['exception'] : null;
        $entry = [
            'time' => (string) ($data['time'] ?? ''),
            'level' => (string) ($data['level'] ?? ''),
            'ref' => (string) $data['ref'],
            'message' => (string) $data['message'],
            'context' => array_map(static fn ($v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $context),
            'exception' => $exception === null ? null : [
                'class' => (string) ($exception['class'] ?? ''),
                'message' => (string) ($exception['message'] ?? ''),
                'file' => (string) ($exception['file'] ?? ''),
                'trace' => (string) ($exception['trace'] ?? ''),
            ],
        ];
        $entry['search'] = implode("\n", [
            $entry['ref'],
            $entry['message'],
            implode(' ', $entry['context']),
            $entry['exception']['class'] ?? '',
            $entry['exception']['message'] ?? '',
            $entry['exception']['file'] ?? '',
        ]);
        return $entry;
    }
}
