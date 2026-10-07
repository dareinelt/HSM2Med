<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * Wendet database/migrations/NNN_*.sql in Reihenfolge an und protokolliert sie in schema_migrations.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDir,
    ) {
    }

    /**
     * @return list<string> neu angewendete Migrationen
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );

        $applied = [];
        foreach ($this->pdo->query('SELECT version, checksum FROM schema_migrations') as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        $new = [];
        foreach ($this->migrationFiles() as $version => $file) {
            $sql = (string) file_get_contents($file);
            $checksum = self::checksum($sql);
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException(sprintf('Migration %s wurde nach dem Anwenden veraendert.', $version));
                }
                continue;
            }
            // DDL fuehrt in MySQL implizite Commits aus; daher statement-weise.
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, NOW())');
            $stmt->execute([$version, $checksum]);
            $new[] = $version;
        }

        return $new;
    }

    /**
     * @return array<string, string> version => Pfad
     */
    public function migrationFiles(): array
    {
        return self::listMigrationFiles($this->migrationDir);
    }

    /**
     * @return array<string, string> version => Pfad
     */
    public static function listMigrationFiles(string $migrationDir): array
    {
        $files = glob($migrationDir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);
        $result = [];
        foreach ($files as $file) {
            $result[basename($file, '.sql')] = $file;
        }
        return $result;
    }

    /**
     * @return list<array{version: string, applied_at: string}>
     */
    public function appliedMigrations(): array
    {
        return $this->pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll();
    }

    /**
     * Pruefsumme unabhaengig von Zeilenende-Konvertierungen (CRLF/LF).
     */
    public static function checksum(string $sql): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $sql));
    }

    /**
     * Erzeugt database/schema.sql: vollstaendiges Schema inkl. Migrationsstand.
     */
    public static function buildSchemaDump(string $migrationDir): string
    {
        $out = "-- HSM2Med – vollstaendiges Datenbankschema (MySQL 9.7)\n"
            . "-- AUTOMATISCH ERZEUGT mit: php bin/build-schema.php – nicht manuell bearbeiten.\n"
            . "-- Quelle: database/migrations/*.sql. Fuer Neuinstallationen ohne Migrator nutzbar:\n"
            . "--   mysql -u root -p hsm2med < database/schema.sql\n"
            . "-- Die Eintraege in schema_migrations verhindern eine erneute Ausfuehrung der Migrationen.\n\n"
            . "SET NAMES utf8mb4;\n\n"
            . "CREATE TABLE schema_migrations (\n"
            . "    version    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,\n"
            . "    applied_at DATETIME NOT NULL,\n"
            . "    PRIMARY KEY (version)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n";
        foreach (self::listMigrationFiles($migrationDir) as $version => $file) {
            $sql = str_replace("\r\n", "\n", (string) file_get_contents($file));
            $out .= "\n-- ===== Migration {$version} =====\n\n" . rtrim($sql) . "\n\n"
                . sprintf("INSERT INTO schema_migrations (version, checksum, applied_at) VALUES ('%s', '%s', NOW());\n", $version, self::checksum($sql));
        }
        return $out;
    }

    /**
     * Zerlegt ein SQL-Skript in Einzelanweisungen (beachtet Strings und Kommentare).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
