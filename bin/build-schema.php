<?php

declare(strict_types=1);

/**
 * Erzeugt database/schema.sql aus den Migrationen (ohne Datenbankverbindung).
 * Aufruf: php bin/build-schema.php [--check]
 *   --check  nur pruefen, ob schema.sql aktuell ist (Exit 1 bei Abweichung)
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/src/Database/Migrator.php';

$root = dirname(__DIR__);
$target = $root . '/database/schema.sql';
$dump = App\Database\Migrator::buildSchemaDump($root . '/database/migrations');

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? str_replace("\r\n", "\n", (string) file_get_contents($target)) : '';
    if ($current !== $dump) {
        fwrite(STDERR, "database/schema.sql ist veraltet. Bitte 'php bin/build-schema.php' ausfuehren.\n");
        exit(1);
    }
    fwrite(STDOUT, "database/schema.sql ist aktuell.\n");
    exit(0);
}

file_put_contents($target, $dump);
fwrite(STDOUT, "database/schema.sql geschrieben.\n");
