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
        if (!str_starts_with($location, '/') || str_starts_with($location, '//')) {
            $location = '/';
        }
        return new self('', 303, ['Location' => $location]);
    }

    public static function pdf(string $content, string $filename, bool $download): self
    {
        return new self($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s"', $download ? 'attachment' : 'inline', $filename),
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
