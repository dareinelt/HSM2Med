<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use App\Config\Config;
use App\Http\Controller\SystemController;
use App\Http\Request;
use App\Http\View;
use App\Support\Logger;
use App\Support\LogReader;
use RuntimeException;
use Tests\TestCase;

/**
 * Fehlerprotokoll: Eintraege des Loggers werden gelesen, nach Referenz, Stufe und Text
 * gefiltert und in der Oberflaeche angezeigt.
 */
final class LogReaderTest extends TestCase
{
    private string $dir;
    private string|false $errorLog = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hsm2med-logs-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/logs', 0700, true);
        // Der Logger schreibt zusaetzlich nach error_log; im Test nicht auf die Konsole.
        $this->errorLog = ini_set('error_log', '/dev/null');
    }

    public function tearDown(): void
    {
        @unlink($this->dir . '/logs/app.log');
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
        if ($this->errorLog !== false) {
            ini_set('error_log', $this->errorLog);
        }
        parent::tearDown();
    }

    private function file(): string
    {
        return $this->dir . '/logs/app.log';
    }

    public function testSearchFindsEntryByReferenceNewestFirst(): void
    {
        $logger = new Logger($this->file());
        $first = $logger->info('Erster Eintrag');
        $ref = $logger->error('Unbehandelter Fehler', ['path' => '/letters'], new RuntimeException("Column 'last_name' cannot be null"));
        $logger->warning('Dritter Eintrag');
        file_put_contents($this->file(), "keine JSON-Zeile\n", FILE_APPEND);

        $reader = new LogReader($this->file());
        $all = $reader->search([], 50, 0);
        $this->assertSame(3, $all['total'], 'Ungueltige Zeilen werden uebersprungen.');
        $this->assertSame('Dritter Eintrag', $all['rows'][0]['message']);
        $this->assertSame($first, $all['rows'][2]['ref']);

        $hit = $reader->search(['ref' => strtoupper($ref)], 50, 0);
        $this->assertSame(1, $hit['total']);
        $this->assertSame('/letters', $hit['rows'][0]['context']['path']);
        $this->assertSame(RuntimeException::class, $hit['rows'][0]['exception']['class']);
        $this->assertContains('last_name', $hit['rows'][0]['exception']['message']);

        $this->assertSame(1, $reader->search(['ref' => substr($ref, 0, 6)], 50, 0)['total'], 'Praefix genuegt.');
        $this->assertSame(1, $reader->search(['level' => 'error'], 50, 0)['total']);
        $this->assertSame(1, $reader->search(['q' => 'LAST_NAME'], 50, 0)['total']);
        $this->assertSame(0, $reader->search(['q' => 'gibt es nicht'], 50, 0)['total']);
        $this->assertSame(1, count($reader->search([], 1, 1)['rows']), 'Seitenweise Ausgabe.');
    }

    public function testMissingFileAndInvalidReference(): void
    {
        $reader = new LogReader($this->file());
        $this->assertFalse($reader->available());
        $this->assertSame(0, $reader->search([], 50, 0)['total']);
        $this->assertSame(0, (new LogReader(null))->search([], 50, 0)['total']);
        $this->assertSame('', LogReader::normalizeReference("abc'; DROP"));
        $this->assertSame('279e68e8f457', LogReader::normalizeReference(' 279E68E8F457 '));
    }

    public function testLogPageRendersAndOpensDetailsForReference(): void
    {
        $root = dirname(__DIR__, 2);
        $app = new Application(Config::fromEnvironment(['APP_DATA_DIR' => $this->dir] + getenv()), $root);
        $ref = $app->logger()->error('Unbehandelter Fehler', ['path' => '/letters'], new RuntimeException('Spalte <fehlt>'));
        $app->logger()->info('Anderer Eintrag');
        $controller = new SystemController($app, new View($root . '/templates'));

        $page = $controller->logs(new Request('GET', '/system/logs', ['ref' => $ref]));
        $this->assertSame(200, $page->status);
        $this->assertContains('Fehlerprotokoll', $page->body);
        $this->assertContains($ref, $page->body);
        $this->assertContains('Spalte &lt;fehlt&gt;', $page->body);
        $this->assertContains('<details open>', $page->body);
        $this->assertNotContains('Anderer Eintrag', $page->body);

        $empty = $controller->logs(new Request('GET', '/system/logs', ['ref' => 'ffffffffffff']));
        $this->assertContains('wurde kein Eintrag gefunden', $empty->body);

        $invalid = $controller->logs(new Request('GET', '/system/logs', ['ref' => 'xyz']));
        $this->assertContains('bis zu 12 Zeichen', $invalid->body);
    }
}
