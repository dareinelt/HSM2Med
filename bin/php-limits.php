<?php

declare(strict_types=1);

/*
 * Gibt PHP-ini-Direktiven fuer Upload-Limits aus UPLOAD_MAX_SIZE aus (Containerstart).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../src/Config/Config.php';

try {
    $bytes = App\Config\Config::parseSize((string) (getenv('UPLOAD_MAX_SIZE') ?: '5M'));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $bytes = 5 * 1024 * 1024;
}

// Formular-Overhead (Multipart-Header, CSRF-Token) beruecksichtigen
$post = $bytes + 64 * 1024;

echo "; automatisch erzeugt aus UPLOAD_MAX_SIZE\n";
echo 'upload_max_filesize = ' . $bytes . "\n";
echo 'post_max_size = ' . $post . "\n";
