<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Import\ImportService;
use App\Import\MerlinParser;
use App\Security\UploadValidator;
use App\Support\LogReader;
use Throwable;

final class SystemController extends Controller
{
    private const int LOGS_PER_PAGE = 50;

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

    /**
     * Fehlerprotokoll: Eintraege des Anwendungsprotokolls, durchsuchbar nach Referenz.
     */
    public function logs(Request $request): Response
    {
        $filters = [
            'ref' => mb_substr(trim($request->query('ref')), 0, 64),
            'level' => trim($request->query('level')),
            'q' => mb_substr(trim($request->query('q')), 0, 200),
        ];
        if (!isset(LogReader::LEVELS[$filters['level']])) {
            $filters['level'] = '';
        }
        $page = self::page($request->query('page', '1'));
        $reader = $this->app->logReader();
        $result = $reader->search($filters, self::LOGS_PER_PAGE, ($page - 1) * self::LOGS_PER_PAGE);

        return Response::html($this->view->render('system_logs', [
            'title' => 'Fehlerprotokoll',
            'filters' => $filters,
            'invalidRef' => $filters['ref'] !== '' && LogReader::normalizeReference($filters['ref']) === '',
            'available' => $reader->available(),
            'logFile' => $reader->logFile(),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'truncated' => $result['truncated'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::LOGS_PER_PAGE)),
        ], 'logs'));
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
