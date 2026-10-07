<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

/**
 * Anwendungskonfiguration ausschliesslich aus Umgebungsvariablen (keine hart codierten Zugangsdaten).
 */
final readonly class Config
{
    public const string APP_NAME = 'HSM2Med';
    public const string APP_VERSION = '1.0.0';

    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public string $appEnv,
        public string $timezone,
        public int $uploadMaxBytes,
        public string $dataDir,
        public string $importDir,
        public bool $pdfRawAppendixDefault,
        public bool $sessionSecureCookie,
    ) {
    }

    /**
     * @param array<string, string>|null $env Fuer Tests; Standard: getenv()
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= getenv();
        $get = static function (string $key, ?string $default = null) use ($env): string {
            $value = $env[$key] ?? null;
            if ($value === null || $value === '') {
                if ($default === null) {
                    throw new InvalidArgumentException(sprintf('Umgebungsvariable %s ist nicht gesetzt.', $key));
                }
                return $default;
            }
            return (string) $value;
        };

        $timezone = $get('APP_TIMEZONE', 'Europe/Berlin');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('APP_TIMEZONE ist keine gueltige Zeitzone.');
        }

        $appEnv = $get('APP_ENV', 'production');
        if (!in_array($appEnv, ['production', 'development', 'testing'], true)) {
            throw new InvalidArgumentException('APP_ENV muss production, development oder testing sein.');
        }

        return new self(
            dbHost: $get('DB_HOST', 'db'),
            dbPort: self::parsePort($get('DB_PORT', '3306')),
            dbDatabase: $get('DB_DATABASE', 'hsm2med'),
            dbUsername: $get('DB_USERNAME', 'hsm2med'),
            dbPassword: $get('DB_PASSWORD', ''),
            appEnv: $appEnv,
            timezone: $timezone,
            uploadMaxBytes: self::parseSize($get('UPLOAD_MAX_SIZE', '5M')),
            dataDir: rtrim($get('APP_DATA_DIR', dirname(__DIR__, 2) . '/storage'), '/'),
            importDir: rtrim($get('IMPORT_DATA_DIR', dirname(__DIR__, 2) . '/storage/imports'), '/'),
            pdfRawAppendixDefault: self::parseBool($get('PDF_RAW_APPENDIX_DEFAULT', '0')),
            sessionSecureCookie: self::parseBool($get('SESSION_SECURE_COOKIE', '0')),
        );
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    /**
     * "5M", "512K", "1G" oder reine Byte-Angabe.
     */
    public static function parseSize(string $value): int
    {
        if (!preg_match('/^\s*(\d+)\s*([KMG])?B?\s*$/i', $value, $m)) {
            throw new InvalidArgumentException('Ungueltige Groessenangabe: ' . $value);
        }
        $bytes = (int) $m[1];
        $bytes *= match (strtoupper($m[2] ?? '')) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
        if ($bytes < 1) {
            throw new InvalidArgumentException('Groessenangabe muss positiv sein.');
        }
        return $bytes;
    }

    private static function parsePort(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535) {
            throw new InvalidArgumentException('DB_PORT ist ungueltig.');
        }
        return (int) $value;
    }

    private static function parseBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
