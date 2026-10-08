<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Icon;
use Tests\TestCase;

/**
 * Symbole der Oberflaeche: Inline-SVG, offline und ohne Icon-Schrift.
 */
final class IconTest extends TestCase
{
    // Oberflaeche 1. Alle Symbole liegen als Pfaddaten vor und werden als Inline-SVG ausgegeben.
    public function testEveryIconRendersInlineSvg(): void
    {
        $names = Icon::names();
        $this->assertTrue(count($names) >= 20, 'Es sollten genuegend Symbole vorhanden sein.');

        foreach ($names as $name) {
            $this->assertTrue(Icon::has($name), 'Symbol fehlt: ' . $name);
            $svg = Icon::svg($name);
            $this->assertContains('<svg', $svg);
            $this->assertContains('viewBox="0 0 24 24"', $svg);
            $this->assertContains('aria-hidden="true"', $svg);
            $this->assertContains('focusable="false"', $svg);
            $this->assertContains('</svg>', $svg);
            $this->assertNotContains('<script', $svg);
            // Keine externen Verweise (Offline-Betrieb).
            $this->assertNotContains('href', $svg);
            $this->assertNotContains('http', $svg);
        }
    }

    // Oberflaeche 2. Unbekannte Namen fallen auf ein Ersatzsymbol zurueck statt zu scheitern.
    public function testUnknownIconFallsBack(): void
    {
        $this->assertFalse(Icon::has('gibt-es-nicht'));
        $this->assertSame(Icon::svg(Icon::FALLBACK), Icon::svg('gibt-es-nicht'));
        $this->assertSame(Icon::svg(Icon::FALLBACK), Icon::svg(''));
        $this->assertNotSame('', Icon::paths(Icon::FALLBACK));
        $this->assertSame(Icon::paths(Icon::FALLBACK), Icon::paths('gibt-es-nicht'));
    }

    // Oberflaeche 3. Die CSS-Klasse wird escaped und ist anpassbar.
    public function testClassAttributeIsEscaped(): void
    {
        $this->assertContains('class="app-icon"', Icon::svg('dashboard'));
        $this->assertContains('class="app-icon app-icon--lg"', Icon::svg('dashboard', 'app-icon app-icon--lg'));
        $escaped = Icon::svg('dashboard', '"><script>');
        $this->assertNotContains('"><script>', $escaped);
        $this->assertContains('&quot;&gt;&lt;script&gt;', $escaped);
    }

    // Oberflaeche 4. Namen sind eindeutig; das Ersatzsymbol ist selbst vorhanden.
    public function testNamesAreUnique(): void
    {
        $names = Icon::names();
        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertFalse(in_array(Icon::FALLBACK, $names, true), 'Das Ersatzsymbol ist kein eigenstaendiger Eintrag.');
    }
}
