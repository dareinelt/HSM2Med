<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Config;
use App\Database\Migrator;
use App\Http\Response;
use App\Http\View;
use App\Import\PendingUploadStore;
use App\Security\FileName;
use App\Security\UploadException;
use App\Security\UploadValidator;
use App\Support\FixedClock;
use DateTimeImmutable;
use Tests\Support\Fixtures;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    public function testFileNameSanitizing(): void
    {
        $this->assertSame('passwd', FileName::sanitize('../../etc/passwd'));
        $this->assertSame('boot.ini', FileName::sanitize('C:\\windows\\..\\boot.ini'));
        $this->assertSame('evil.log', FileName::sanitize("ev\0il.log"));
        $this->assertSame('upload.txt', FileName::sanitize('..'));
        $this->assertSame('Bericht_1_2026-10-07.pdf', FileName::downloadName('Bericht 1 2026-10-07.pdf'));
        $this->assertSame('a_b_.pdf', FileName::downloadName('a"b;.pdf'));
    }

    public function testUploadContentValidation(): void
    {
        $validator = new UploadValidator(1024 * 1024);
        $validator->validateContent(Fixtures::sampleFile(), 'export.log');
        $validator->validateContent(Fixtures::sampleFile(), 'EXPORT.TXT');
        $this->assertTrue(true);

        $cases = [
            [Fixtures::sampleFile(), 'export.php'],
            [Fixtures::sampleFile(), 'export.txt.php'],
            [Fixtures::sampleFile(), 'export'],
            ['', 'leer.txt'],
            ["einfacher Text ohne Trennzeichen\n", 'x.txt'],
            ["<?php system('id'); \x1C", 'x.txt'],
            ["%PDF-1.4\x1C", 'x.txt'],
            ["PK\x03\x04\x1C", 'x.log'],
            [str_repeat("A\x1C", 600000), 'gross.txt'],
        ];
        foreach ($cases as [$bytes, $name]) {
            $this->assertThrows(UploadException::class, fn () => $validator->validateContent($bytes, $name), "Datei {$name} muss abgelehnt werden");
        }
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(null));
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(['error' => UPLOAD_ERR_INI_SIZE]));
        $this->assertThrows(UploadException::class, fn () => $validator->validateUpload(['error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/passwd', 'name' => 'a.txt']));
    }

    public function testHtmlEscaping(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&apos;', View::escape('<script>alert("x")</script>\''));
        $this->assertSame('', View::escape(null));
        $this->assertSame('a␜b[0x00]', View::raw("a\x1Cb\x00"));
    }

    public function testRedirectOnlyToLocalPaths(): void
    {
        $this->assertSame('/', Response::redirect('https://evil.example')->headers['Location']);
        $this->assertSame('/', Response::redirect('//evil.example')->headers['Location']);
        $this->assertSame('/reports/1', Response::redirect('/reports/1')->headers['Location']);
        // Browser lesen "\\" als "/" und ignorieren Tab/Zeilenumbruch: alles Umleitungen nach aussen.
        $this->assertSame('/', Response::redirect('/\\evil.example')->headers['Location']);
        $this->assertSame('/', Response::redirect("/\t/evil.example")->headers['Location']);
        $this->assertSame('/', Response::redirect("/a\r\nSet-Cookie: x=1")->headers['Location']);
        $this->assertSame('/', Response::redirect('reports/1')->headers['Location']);
        $this->assertSame('/', Response::redirect('')->headers['Location']);
        $this->assertTrue(Response::isLocalPath('/patients?q=a%20b'));
        $this->assertFalse(Response::isLocalPath('/\\evil.example'));
    }

    public function testPendingUploadStore(): void
    {
        $dir = sys_get_temp_dir() . '/hsm2med-pending-' . bin2hex(random_bytes(4));
        $clock = new FixedClock(new DateTimeImmutable('2026-10-07 10:00:00'));
        $store = new PendingUploadStore($dir, $clock);
        $token = $store->store('abc' . "\x1C", 'datei.txt');
        $this->assertTrue(PendingUploadStore::isValidToken($token));
        $loaded = $store->load($token);
        $this->assertSame('abc' . "\x1C", $loaded['bytes'] ?? null);
        $this->assertSame('datei.txt', $loaded['filename']);
        $this->assertNull($store->load('../../etc/passwd'));
        $this->assertNull($store->load(str_repeat('0', 32)));

        $clock->set(new DateTimeImmutable('2026-10-07 11:00:01'));
        $this->assertNull($store->load($token), 'Abgelaufener Upload');
        $this->assertSame([], glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testConfigParsing(): void
    {
        $this->assertSame(5 * 1024 * 1024, Config::parseSize('5M'));
        $this->assertSame(512 * 1024, Config::parseSize('512K'));
        $this->assertSame(100, Config::parseSize('100'));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Config::parseSize('viel'));
        $config = Config::fromEnvironment(['DB_PASSWORD' => 'x', 'UPLOAD_MAX_SIZE' => '2M']);
        $this->assertSame(2 * 1024 * 1024, $config->uploadMaxBytes);
        $this->assertSame('production', $config->appEnv);
        $this->assertThrows(\InvalidArgumentException::class, fn () => Config::fromEnvironment(['APP_ENV' => 'debug']));
    }

    public function testSqlSplitter(): void
    {
        $sql = "-- Kommentar; mit Semikolon\nCREATE TABLE a (x VARCHAR(5) COMMENT 'a;b');\n/* block; */INSERT INTO a VALUES ('it''s;');";
        $statements = Migrator::splitStatements($sql);
        $this->assertCount(2, $statements);
        $this->assertContains("COMMENT 'a;b'", $statements[0]);
    }
}
