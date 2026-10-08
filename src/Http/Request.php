<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? rawurldecode($path) : '/';
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $_FILES,
        );
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Angeforderter Pfad samt Abfrage – Ziel fuer die Weiterleitung nach der Anmeldung.
     */
    public function target(): string
    {
        if ($this->query === []) {
            return $this->path;
        }
        $query = http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
        return $query === '' ? $this->path : $this->path . '?' . $query;
    }

    public function post(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }
}
