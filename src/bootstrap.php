<?php

declare(strict_types=1);

/*
 * Gemeinsamer Einstiegspunkt fuer Web und CLI.
 * Liefert eine konfigurierte App\Application-Instanz.
 */

const APP_ROOT = __DIR__ . '/..';

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, 4));
        if (preg_match('#^[A-Za-z0-9_/]+$#D', $relative) !== 1) {
            return;
        }
        $file = __DIR__ . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

mb_internal_encoding('UTF-8');

$config = App\Config\Config::fromEnvironment();
date_default_timezone_set($config->timezone);

return new App\Application($config, APP_ROOT);
