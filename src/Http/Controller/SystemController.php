<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Config\Config;
use App\Http\Response;
use App\Import\ImportService;
use App\Import\MerlinParser;
use App\Security\UploadValidator;
use Throwable;

final class SystemController extends Controller
{
    public function index(): Response
    {
        $database = ['ok' => false, 'version' => null, 'migrations' => [], 'pending' => []];
        try {
            $pdo = $this->app->pdo();
            $database['version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $migrator = $this->app->migrator();
            $database['migrations'] = $migrator->appliedMigrations();
            $applied = array_column($database['migrations'], 'version');
            $database['pending'] = array_values(array_diff(array_keys($migrator->migrationFiles()), $applied));
            $database['ok'] = true;
            $stats = $this->app->reportRepository()->statistics();
        } catch (Throwable $e) {
            $database['reference'] = $this->app->logger()->error('Systeminformationen: Datenbank nicht erreichbar', [], $e);
            $stats = null;
        }

        $config = $this->app->config;
        return Response::html($this->view->render('system', [
            'title' => 'Systeminformationen',
            'app' => [
                'Anwendung' => Config::APP_NAME . ' ' . Config::APP_VERSION,
                'Umgebung' => $config->appEnv,
                'PHP-Version' => PHP_VERSION,
                'Server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unbekannt'),
                'Zeitzone' => $config->timezone,
                'Parser-Version' => MerlinParser::VERSION,
                'Mapping-Version' => $this->app->mapping()->version(),
                'Berichtsversion' => (string) ImportService::REPORT_VERSION,
                'Max. Uploadgröße' => UploadValidator::formatBytes($config->uploadMaxBytes),
                'PDF-Rohdatenanhang standardmäßig' => $config->pdfRawAppendixDefault ? 'ja' : 'nein',
            ],
            'storage' => [
                'Anwendungsdaten' => [$config->dataDir, is_dir($config->dataDir) && is_writable($config->dataDir)],
                'Import-Archiv' => [$config->importDir, is_dir($config->importDir) && is_writable($config->importDir)],
            ],
            'extensions' => array_map(static fn (string $ext): array => [$ext, extension_loaded($ext)], ['pdo_mysql', 'mbstring', 'zlib', 'fileinfo']),
            'database' => $database,
            'stats' => $stats,
        ], 'system'));
    }

    public function health(): Response
    {
        try {
            $this->app->pdo()->query('SELECT 1')->fetchColumn();
            return Response::json(['status' => 'ok', 'database' => 'ok']);
        } catch (Throwable $e) {
            $this->app->logger()->error('Healthcheck: Datenbank nicht erreichbar', [], $e);
            return Response::json(['status' => 'error', 'database' => 'unavailable'], 503);
        }
    }
}
