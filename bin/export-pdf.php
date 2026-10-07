<?php

declare(strict_types=1);

/*
 * Exportiert einen gespeicherten Bericht als PDF – ausschliesslich aus der Datenbank.
 *   php bin/export-pdf.php <bericht-id> <ziel.pdf> [--raw] [--force]
 *
 *   --raw    Rohdatenanhang (Originaldaten / Importdaten) anfuegen
 *   --force  vorhandene Zieldatei ueberschreiben
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Application $app */
$app = require __DIR__ . '/../src/bootstrap.php';

$positional = [];
$raw = $app->config->pdfRawAppendixDefault;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--raw') {
        $raw = true;
    } elseif ($arg === '--no-raw') {
        $raw = false;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unbekannte Option: {$arg}\n");
        exit(2);
    } else {
        $positional[] = $arg;
    }
}

if (count($positional) !== 2 || !ctype_digit($positional[0])) {
    fwrite(STDERR, "Verwendung: php bin/export-pdf.php <bericht-id> <ziel.pdf> [--raw|--no-raw] [--force]\n");
    exit(2);
}
[$id, $target] = [(int) $positional[0], $positional[1]];

if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) !== 'pdf') {
    fwrite(STDERR, "Die Zieldatei muss die Endung .pdf haben.\n");
    exit(2);
}
if (file_exists($target) && !$force) {
    fwrite(STDERR, "Zieldatei existiert bereits. Mit --force überschreiben.\n");
    exit(2);
}
$directory = dirname($target);
if (!is_dir($directory) || !is_writable($directory)) {
    fwrite(STDERR, "Zielverzeichnis existiert nicht oder ist nicht beschreibbar.\n");
    exit(2);
}

try {
    $service = $app->reportService();
    $data = $service->load($id);
    if ($data === null) {
        fwrite(STDERR, "Bericht {$id} nicht gefunden.\n");
        exit(1);
    }
    $pdf = $service->renderPdf($data, $raw, $app->clock()->now());
} catch (Throwable $e) {
    $reference = $app->logger()->error('CLI-PDF-Export fehlgeschlagen', ['report_id' => $id], $e);
    fwrite(STDERR, "PDF konnte nicht erzeugt werden (Ref. {$reference}).\n");
    exit(1);
}

$tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
if (file_put_contents($tmp, $pdf) !== strlen($pdf) || !rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "Zieldatei konnte nicht geschrieben werden.\n");
    exit(1);
}
fwrite(STDOUT, sprintf("PDF für Bericht %d geschrieben: %s (%d Byte)\n", $id, $target, strlen($pdf)));
exit(0);
