<?php

declare(strict_types=1);

/*
 * Kommandozeilen-Import einer Exportdatei (Merlin .txt/.log oder Biotronik
 * IEEE 11073-10103 .xml). Der Parser wird automatisch erkannt.
 *   php bin/import.php <datei> [--dry-run] [--force] [--patient=<id|new|none>]
 *
 *   --dry-run            nur analysieren, nichts speichern
 *   --force              bereits importierte Datei (gleicher SHA-256) erneut importieren
 *   --patient=<ziel>     ausdrueckliche Patientenzuordnung (Patienten-Nr., new oder none)
 *
 * Exit-Codes: 0 = Erfolg, 1 = Import fehlgeschlagen, 2 = Aufruffehler, 3 = Duplikat
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @var App\Application $app */
$app = require __DIR__ . '/../src/bootstrap.php';

$file = null;
$dryRun = false;
$force = false;
$patientTarget = null;
foreach (array_slice($argv, 1) as $arg) {
    match (true) {
        $arg === '--dry-run' => $dryRun = true,
        $arg === '--force' => $force = true,
        str_starts_with($arg, '--patient=') => $patientTarget = (static function (string $raw): string {
            if ($raw === 'new' || $raw === 'none' || preg_match('/^patient:[1-9]\d*$/D', $raw) === 1) {
                return $raw;
            }
            if (preg_match('/^[1-9]\d*$/D', $raw) === 1) {
                return 'patient:' . $raw;
            }
            fwrite(STDERR, "Ungültige Angabe für --patient: {$raw} (erwartet: Patienten-Nr., new oder none).\n");
            exit(2);
        })(substr($arg, 10)),
        $arg === '--help' || $arg === '-h' => (static function (): never {
            fwrite(STDOUT, "Verwendung: php bin/import.php <datei> [--dry-run] [--force] [--patient=<id|new|none>]\n");
            exit(0);
        })(),
        str_starts_with($arg, '--') => (static function () use ($arg): never {
            fwrite(STDERR, "Unbekannte Option: {$arg}\n");
            exit(2);
        })(),
        default => $file = $arg,
    };
}

if ($file === null || !is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Datei nicht gefunden oder nicht lesbar.\nVerwendung: php bin/import.php <datei> [--dry-run] [--force] [--patient=<id|new|none>]\n");
    exit(2);
}

$bytes = (string) file_get_contents($file);
$filename = App\Security\FileName::sanitize(basename($file));

try {
    (new App\Security\UploadValidator($app->config->uploadMaxBytes))->validateContent($bytes, $filename);
} catch (App\Security\UploadException $e) {
    fwrite(STDERR, 'Datei abgelehnt: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $service = $app->importService();
    $analysis = $service->analyze($bytes, $filename);
} catch (Throwable $e) {
    $reference = $app->logger()->error('CLI-Analyse fehlgeschlagen', [], $e);
    fwrite(STDERR, "Technischer Fehler (Ref. {$reference}).\n");
    exit(1);
}

$result = $analysis->parseResult;
$resolution = $analysis->patientResolution;
fwrite(STDOUT, sprintf(
    "Datei:        %s\nGröße:        %d Byte\nSHA-256:      %s\nKodierung:    %s\nDatensätze:   %d erkannt, %d gültig\nFehler:       %d\nWarnungen:    %d\nZielpatient:  %s (%s)\n",
    $analysis->filename,
    $analysis->fileSize,
    $analysis->fileHash,
    $result->encoding,
    $result->recordCount,
    $result->validRecordCount(),
    $analysis->errorCount(),
    $analysis->warningCount(),
    $resolution->summary,
    $resolution->reason,
));
foreach ($analysis->issues() as $issue) {
    fwrite(STDOUT, sprintf("  [%s] Pos. %s %s: %s\n", $issue->isError() ? 'FEHLER' : 'Warnung', $issue->position ?? '-', $issue->code, $issue->message));
}
foreach ($analysis->validation->blockingErrors as $message) {
    fwrite(STDOUT, "  [BLOCKIEREND] {$message}\n");
}
if ($resolution->requiresDecision()) {
    foreach ($resolution->options as $option) {
        $cliArg = str_starts_with($option->target, 'patient:') ? substr($option->target, 8) : $option->target;
        fwrite(STDOUT, sprintf("  [AUSWAHL] --patient=%s : %s%s\n", $cliArg, $option->label, $option->detail !== '' ? ' (' . $option->detail . ')' : ''));
    }
}
if ($analysis->isDuplicate()) {
    fwrite(STDOUT, 'Hinweis: Datei wurde bereits importiert (Import-Nr. ' . implode(', ', array_column($analysis->previousImports, 'id')) . ").\n");
}

if ($dryRun) {
    $possible = $analysis->validation->isValid() && (!$resolution->requiresDecision() || ($patientTarget !== null && $resolution->accepts($patientTarget)));
    fwrite(STDOUT, $possible ? "Probelauf: Import wäre möglich. Es wurde nichts gespeichert.\n" : "Probelauf: Import wäre ohne weitere Angaben nicht möglich.\n");
    exit($possible ? 0 : 1);
}

try {
    $outcome = $service->import($analysis, $bytes, $force, null, $patientTarget);
} catch (App\Import\DuplicateImportException) {
    fwrite(STDERR, "Datei wurde bereits importiert. Mit --force erneut importieren.\n");
    exit(3);
} catch (App\Import\PatientResolutionException $e) {
    fwrite(STDERR, $e->getMessage() . " Bitte --patient=<id|new|none> angeben.\n");
    exit(1);
} catch (App\Import\ImportFailedException $e) {
    fwrite(STDERR, $e->getMessage() . ($e->failedImportId !== null ? " (Import-Nr. {$e->failedImportId})" : '') . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Import erfolgreich: Import-Nr. %d, Bericht-Nr. %d, %d Parameter, Status %s.\n",
    $outcome->importId,
    $outcome->reportId,
    $outcome->parameterCount,
    $outcome->status,
));
exit(0);
