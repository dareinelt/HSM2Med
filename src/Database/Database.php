<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Config;
use PDO;
use PDOException;
use Throwable;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->dbHost,
            $config->dbPort,
            $config->dbDatabase,
        );

        $pdo = new PDO($dsn, $config->dbUsername, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Strikter Modus: keine stillschweigende Kuerzung von Werten.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,ONLY_FULL_GROUP_BY'");
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

        return $pdo;
    }

    /**
     * Wiederholte Verbindungsversuche (Containerstart).
     */
    public static function connectWithRetry(Config $config, int $timeoutSeconds): PDO
    {
        $deadline = time() + $timeoutSeconds;
        while (true) {
            try {
                return self::connect($config);
            } catch (PDOException $e) {
                if (time() >= $deadline) {
                    throw $e;
                }
                sleep(2);
            }
        }
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public static function transactional(PDO $pdo, callable $callback): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
