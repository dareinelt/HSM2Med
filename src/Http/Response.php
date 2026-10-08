<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public static function redirect(string $location): self
    {
        // Nur relative, anwendungsinterne Ziele (kein Open Redirect)
        if (!self::isLocalPath($location)) {
            $location = '/';
        }
        return new self('', 303, ['Location' => $location]);
    }

    /**
     * Anwendungsinterner, relativer Pfad?
     *
     * Security fix: Browser behandeln "\" wie "/" und entfernen Tabulatoren/Zeilenumbrueche aus
     * URLs – "/\evil.example" oder "/<TAB>/evil.example" wuerden so zu "//evil.example" (fremder
     * Host). Darum sind Backslash und Steuerzeichen in Weiterleitungszielen nicht erlaubt.
     */
    public static function isLocalPath(string $location): bool
    {
        return str_starts_with($location, '/')
            && !str_starts_with($location, '//')
            && !str_contains($location, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $location) !== 1;
    }

    public static function pdf(string $content, string $filename, bool $download): self
    {
        return new self($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $download ? 'attachment' : 'inline', $filename),
            'Content-Length' => (string) strlen($content),
        ]);
    }

    /**
     * Binaere Antwort fuer ein Bild (z. B. das hinterlegte Ausweis-Logo).
     */
    public static function image(string $content, string $mimeType): self
    {
        return new self($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($content),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        $headers = $this->headers + [
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
        ];
        foreach ($headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        echo $this->body;
    }
}
