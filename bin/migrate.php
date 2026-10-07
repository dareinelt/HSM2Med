<?php

declare(strict_types=1);

/*
 * Fuehrt ausstehende Datenbank-Migrationen aus.
 *   php bin/migrate.php [--wait=SEKUNDEN]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Application $app */
$app = require __DIR__ . '/../src/bootstrap.php';

$wait = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--wait=(\d+)$/D', $arg, $m) === 1) {
        $wait = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Verwendung: php bin/migrate.php [--wait=SEKUNDEN]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        exit(2);
    }
}

try {
    $pdo = $wait > 0 ? App\Database\Database::connectWithRetry($app->config, $wait) : $app->pdo();
    $migrator = new App\Database\Migrator($pdo, $app->rootDir . '/database/migrations');
    $applied = $migrator->migrate();
    fwrite(STDOUT, $applied === []
        ? "Datenbankschema ist aktuell.\n"
        : 'Angewendete Migrationen: ' . implode(', ', $applied) . "\n");
    exit(0);
} catch (Throwable $e) {
    $reference = $app->logger()->error('Migration fehlgeschlagen', [], $e);
    fwrite(STDERR, "Migration fehlgeschlagen (Ref. {$reference}): " . $e->getMessage() . "\n");
    exit(1);
}
