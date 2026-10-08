<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Migrator;
use PDO;

final class SchemaTest extends DatabaseTestCase
{
    public function testSchemaFileIsUpToDate(): void
    {
        $root = dirname(__DIR__, 2);
        $expected = Migrator::buildSchemaDump($root . '/database/migrations');
        $actual = str_replace("\r\n", "\n", (string) file_get_contents($root . '/database/schema.sql'));
        $this->assertTrue($actual === $expected, 'database/schema.sql ist veraltet (php bin/build-schema.php ausfuehren).');
    }

    public function testSchemaFileMatchesMigrations(): void
    {
        $fromMigrations = $this->describeSchema();

        self::resetSchema($this->pdo);
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        foreach (Migrator::splitStatements($sql) as $statement) {
            $this->pdo->exec($statement);
        }
        $this->assertSame($fromMigrations, $this->describeSchema());

        // Migrator erkennt den Stand und fuehrt nichts erneut aus
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $this->assertSame([], $migrator->migrate());
    }

    public function testMigrationsAreIdempotent(): void
    {
        $migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations');
        $this->assertSame([], $migrator->migrate());
        $this->assertCount(4, $migrator->appliedMigrations());
        $this->assertSame(
            ['001_initial', '002_patient_card', '003_patient_records', '004_patient_card_mrt'],
            array_column($migrator->appliedMigrations(), 'version'),
        );
    }

    public function testDatabaseUsesUtf8mb4AndStrictMode(): void
    {
        $mode = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->assertContains('STRICT_ALL_TABLES', $mode);
        $charset = (string) $this->pdo->query('SELECT @@SESSION.character_set_connection')->fetchColumn();
        $this->assertSame('utf8mb4', $charset);
        $nonInnoDb = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'")->fetchColumn();
        $this->assertSame(0, (int) $nonInnoDb);
    }

    /**
     * @return array<string, string>
     */
    private function describeSchema(): array
    {
        $result = [];
        $tables = $this->pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $create = (string) $this->pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            $result[(string) $table] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
        }
        return $result;
    }
}
