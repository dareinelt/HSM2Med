<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Database\Database;
use App\Database\Migrator;
use App\Import\ImportArchive;
use App\Import\ImportService;
use App\Import\ImportValidator;
use App\Import\MerlinParser;
use App\Mapping\ParameterMapping;
use App\Report\ReportService;
use App\Report\ReportSummaryBuilder;
use App\Repository\ImportRepository;
use App\Repository\ReportRepository;
use App\Support\FixedClock;
use DateTimeImmutable;
use PDO;
use Tests\Support\ReportDataFactory;
use Tests\TestCase;
use Throwable;

/**
 * Basis fuer Tests gegen die Testdatenbank (docker compose --profile test).
 * Jeder Test beginnt mit einem frisch migrierten, leeren Schema.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $sharedPdo = null;
    protected PDO $pdo;
    protected FixedClock $clock;
    protected string $archiveDir;

    public static function setUpBeforeClass(): void
    {
        if (getenv('DB_HOST') === false || getenv('DB_DATABASE') === false) {
            throw new \Tests\TestSkipped('Keine Testdatenbank konfiguriert (DB_HOST/DB_DATABASE).');
        }
        if (!str_contains((string) getenv('DB_DATABASE'), 'test')) {
            throw new \Tests\TestSkipped('Datenbankname muss "test" enthalten – Schutz vor Datenverlust.');
        }
        if (self::$sharedPdo === null) {
            try {
                self::$sharedPdo = Database::connectWithRetry(Config::fromEnvironment(), 60);
            } catch (Throwable $e) {
                throw new \Tests\TestSkipped('Testdatenbank nicht erreichbar: ' . $e->getMessage());
            }
        }
    }

    public function setUp(): void
    {
        $this->pdo = self::$sharedPdo ?? $this->skip('Keine Datenbankverbindung.');
        self::resetSchema($this->pdo);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-10-07 08:00:00'));
        $this->archiveDir = sys_get_temp_dir() . '/hsm2med-archive-' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        foreach (glob($this->archiveDir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }
        @rmdir($this->archiveDir);
    }

    public static function resetSchema(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function importService(?ParameterMapping $mapping = null, ?string $class = null): ImportService
    {
        $mapping ??= ReportDataFactory::mapping();
        $class ??= ImportService::class;
        return new $class(
            $this->pdo,
            new MerlinParser(),
            new ImportValidator(),
            $mapping,
            new ReportSummaryBuilder($mapping),
            $this->clock,
            new ImportArchive($this->archiveDir),
            null,
        );
    }

    protected function reportService(): ReportService
    {
        return new ReportService(new ReportRepository($this->pdo), new ImportRepository($this->pdo));
    }

    protected function rowCount(string $table, string $where = '1=1', array $params = []): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
