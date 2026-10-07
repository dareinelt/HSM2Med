<?php

declare(strict_types=1);

/*
 * Test-Runner ohne externe Abhaengigkeiten.
 *   php tests/run.php [Filter]
 * Datenbanktests werden uebersprungen, wenn keine Testdatenbank erreichbar ist.
 * Exit-Code 0 nur, wenn alle Tests erfolgreich sind.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // mit @ unterdrueckt
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

const APP_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $map = ['App\\' => APP_ROOT . '/src/', 'Tests\\' => APP_ROOT . '/tests/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});
require_once __DIR__ . '/TestCase.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Europe/Berlin');

$filter = $argv[1] ?? null;
$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], glob(__DIR__ . '/Integration/*Test.php') ?: []);
sort($files);

$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    $relative = substr($file, strlen(__DIR__) + 1, -4);
    $class = 'Tests\\' . str_replace('/', '\\', $relative);
    if (!class_exists($class)) {
        fwrite(STDERR, "Testklasse {$class} nicht gefunden.\n");
        $failed++;
        continue;
    }
    $methods = array_values(array_filter(
        get_class_methods($class),
        static fn (string $m): bool => str_starts_with($m, 'test') && ($filter === null || stripos($class . '::' . $m, $filter) !== false),
    ));
    if ($methods === []) {
        continue;
    }
    echo "\n", $class, "\n";
    try {
        $class::setUpBeforeClass();
    } catch (Tests\TestSkipped $e) {
        foreach ($methods as $method) {
            echo "  S {$method} – übersprungen: {$e->getMessage()}\n";
            $skipped++;
        }
        continue;
    }
    foreach ($methods as $method) {
        /** @var Tests\TestCase $test */
        $test = new $class();
        try {
            $test->setUp();
            try {
                $test->{$method}();
            } finally {
                $test->tearDown();
            }
            echo "  ✓ {$method}\n";
            $passed++;
        } catch (Tests\TestSkipped $e) {
            echo "  S {$method} – übersprungen: {$e->getMessage()}\n";
            $skipped++;
        } catch (Throwable $e) {
            echo "  ✗ {$method}\n";
            $failed++;
            $failures[] = sprintf(
                "%s::%s\n%s: %s\n%s",
                $class,
                $method,
                $e::class,
                $e->getMessage(),
                $e instanceof Tests\AssertionFailed ? '' : $e->getTraceAsString(),
            );
        }
        $assertions += $test->assertions;
    }
}

if ($failures !== []) {
    echo "\nFehlgeschlagen:\n";
    foreach ($failures as $i => $failure) {
        echo "\n", $i + 1, ") ", $failure, "\n";
    }
}
printf(
    "\nErgebnis: %d erfolgreich, %d fehlgeschlagen, %d übersprungen, %d Assertions (%.2fs)\n",
    $passed,
    $failed,
    $skipped,
    $assertions,
    microtime(true) - $start,
);
exit($failed > 0 ? 1 : 0);
