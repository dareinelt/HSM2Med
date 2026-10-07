<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Throwable;

final class AssertionFailed extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
{
}

/**
 * Minimaler Test-Basistyp (keine externen Abhaengigkeiten, offline lauffaehig).
 */
abstract class TestCase
{
    public int $assertions = 0;

    public static function setUpBeforeClass(): void
    {
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function skip(string $reason): never
    {
        throw new TestSkipped($reason);
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(mixed $condition, string $message = 'Bedingung ist nicht wahr.'): void
    {
        $this->assertions++;
        if ($condition !== true) {
            $this->fail($message);
        }
    }

    protected function assertFalse(mixed $condition, string $message = 'Bedingung ist nicht falsch.'): void
    {
        $this->assertTrue($condition === false, $message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->fail(trim($message . "\nErwartet: " . self::export($expected) . "\nTatsaechlich: " . self::export($actual)));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($unexpected === $actual) {
            $this->fail(trim($message . "\nWert darf nicht sein: " . self::export($actual)));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message ?: 'Anzahl stimmt nicht.');
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            $this->fail(trim($message . "\nText nicht gefunden: " . self::export($needle)));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            $this->fail(trim($message . "\nText unerwartet gefunden: " . self::export($needle)));
        }
    }

    /**
     * @param class-string<Throwable> $class
     */
    protected function assertThrows(string $class, callable $callback, string $message = ''): Throwable
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            $this->fail(trim($message . "\nErwartet: {$class}, erhalten: " . $e::class . ' – ' . $e->getMessage()));
        }
        $this->fail(trim($message . "\nErwartete Exception {$class} wurde nicht geworfen."));
    }

    private static function export(mixed $value): string
    {
        $export = var_export($value, true);
        return strlen($export) > 2000 ? substr($export, 0, 2000) . ' …' : $export;
    }
}
