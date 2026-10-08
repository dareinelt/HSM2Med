<?php

declare(strict_types=1);

/*
 * Legt das Administratorkonto aus der .env an (idempotent).
 *
 *   php bin/seed-admin.php [--force] [--wait=SEKUNDEN]
 *
 * Ohne --force wird ein vorhandenes Konto nicht angefasst: das Kennwort aus der .env gilt nur
 * beim ersten Anlegen. --force setzt das Kennwort des Kontos auf den Wert der .env zurueck und
 * dient dem Wiederherstellen des Zugangs, wenn kein Administratorkonto mehr erreichbar ist.
 *
 * Aufruf beim Start des Containers (docker/entrypoint.sh), damit eine frische Installation
 * sofort eine Anmeldung erlaubt.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Application $app */
$app = require __DIR__ . '/../src/bootstrap.php';

$force = false;
$wait = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif (preg_match('/^--wait=(\d+)$/D', $arg, $m) === 1) {
        $wait = (int) $m[1];
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Verwendung: php bin/seed-admin.php [--force] [--wait=SEKUNDEN]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        exit(2);
    }
}

try {
    if ($wait > 0) {
        App\Database\Database::connectWithRetry($app->config, $wait);
    }
    // Security fix: Im Produktivbetrieb wird kein Konto mit dem oeffentlich bekannten
    // Vorgabekennwort aus .env.example angelegt oder darauf zurueckgesetzt.
    if ($app->config->isProduction() && $app->config->adminPasswordIsDefault()) {
        $existing = $app->userRepository()->findByUsername(App\User\UserInput::normalizeUsername($app->config->adminUsername));
        if ($existing === null || $force) {
            fwrite(STDERR, "ADMIN_PASSWORD ist noch der Vorgabewert. Im Produktivbetrieb wird damit kein Administratorkonto angelegt.\n"
                . "Bitte in der .env ein eigenes Kennwort setzen und den Container neu starten.\n");
            exit(1);
        }
    }
    $result = $app->userService()->seedAdmin(
        $app->config->adminUsername,
        $app->config->adminPassword,
        $force,
    );
} catch (Throwable $e) {
    $reference = $app->logger()->error('Administratorkonto konnte nicht angelegt werden', [], $e);
    fwrite(STDERR, "Administratorkonto fehlgeschlagen (Ref. {$reference}): " . $e->getMessage() . "\n");
    exit(1);
}

if ($result['created']) {
    fwrite(STDOUT, "Administratorkonto \"{$app->config->adminUsername}\" angelegt (Gruppe Admin).\n");
} elseif ($result['password_reset']) {
    fwrite(STDOUT, "Kennwort des Administratorkontos \"{$app->config->adminUsername}\" auf den Wert aus der .env zurueckgesetzt.\n");
} else {
    fwrite(STDOUT, "Administratorkonto \"{$app->config->adminUsername}\" ist bereits vorhanden; Kennwort unveraendert.\n");
}

if ($app->config->adminPasswordIsDefault() && !$force) {
    fwrite(STDOUT, "Hinweis: ADMIN_PASSWORD ist noch der Vorgabewert. Bitte in der .env aendern.\n");
}

exit(0);
