<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DateInput;
use Tests\TestCase;

/**
 * Datumseingaben: strenge Stammdatenpruefung und erweiterte Zeitraumangaben (Vormedikation).
 */
final class DateInputTest extends TestCase
{
    public function testStandardFormatsRemainUnchanged(): void
    {
        $this->assertSame('2026-10-01', DateInput::parse('01.10.2026'));
        $this->assertSame('2026-10-01', DateInput::parse('2026-10-01'));
        $this->assertSame('2026-10-01', DateInput::parse('01/10/2026'));
        $this->assertSame('2026-10-01', DateInput::parse('01-10-2026'));
        $this->assertNull(DateInput::parse('01102026'));
        $this->assertNull(DateInput::parse('10/2026'));
    }

    public function testCompactDatesAreAccepted(): void
    {
        $this->assertSame('2026-10-01', DateInput::parseExtended('01102026'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('20261001'));
        $this->assertSame('2026-01-12', DateInput::parseExtended('20260112'));
        $this->assertNull(DateInput::parseExtended('31132026'));
        $this->assertNull(DateInput::parseExtended('20261301'));
    }

    public function testMonthOnlyDatesUseFirstOfMonth(): void
    {
        $this->assertSame('2026-10-01', DateInput::parseExtended('10/2026'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('10.2026'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('10-2026'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('10 / 2026'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('2026-10'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('2026/10'));
        $this->assertSame('2026-10-01', DateInput::parseExtended('2026.10'));
        $this->assertSame('2026-01-01', DateInput::parseExtended('1.2026'));
    }

    public function testInvalidOrEmptyValuesAreRejected(): void
    {
        $this->assertNull(DateInput::parseExtended(''));
        $this->assertNull(DateInput::parseExtended('   '));
        $this->assertNull(DateInput::parseExtended('13/2026'));
        $this->assertNull(DateInput::parseExtended('00/2026'));
        $this->assertNull(DateInput::parseExtended('2026-13'));
        $this->assertNull(DateInput::parseExtended('31.02.2024'));
        $this->assertNull(DateInput::parseExtended('keine Angabe'));
    }

    public function testFormatDisplaysGermanDate(): void
    {
        $this->assertSame('01.10.2026', DateInput::format('2026-10-01'));
        $this->assertSame('keine Angabe', DateInput::format('keine Angabe'));
        $this->assertSame('', DateInput::format(null));
    }
}
