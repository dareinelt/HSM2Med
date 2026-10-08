<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Symbole der Oberflaeche als eingebettete SVG-Grafiken.
 *
 * Bewusst als Inline-SVG und nicht als Icon-Schrift oder externes Paket: die Anwendung
 * arbeitet offline und ohne Content-Security-Policy-Ausnahmen. Alle Symbole sind eigene,
 * schlichte Strichzeichnungen auf einem 24x24-Raster (Strichstaerke, Farbe und Groesse
 * kommen aus dem Stylesheet), damit sie auch fuer IT-Laien ohne Vorkenntnisse lesbar sind.
 */
final class Icon
{
    public const string DEFAULT_CLASS = 'app-icon';

    /** Fallback fuer unbekannte Namen: ein neutraler Punkt statt eines Fehlers. */
    public const string FALLBACK = 'dot';

    /**
     * Strichzeichnungen ohne <svg>-Rahmen, damit sie auch in eigenen Symbolen
     * (z. B. Markenzeichen der Titelleiste) wiederverwendet werden koennen.
     *
     * @var array<string, string>
     */
    private const array PATHS = [
        // Bereiche
        'dashboard' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/>',
        'import' => '<path d="M12 3v10"/><path d="m8 9 4 4 4-4"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'reports' => '<path d="M14 3v5h5"/><path d="M19 21H5V3h9l5 5z"/><path d="M8.5 12h7M8.5 16h4.5"/>',
        'patients' => '<path d="M3 6h6l2 2h10v11H3z"/><circle cx="12" cy="12" r="2.2"/><path d="M8.8 18.4a3.2 3.2 0 0 1 6.4 0"/>',
        'cards' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5 16.4a3.6 3.6 0 0 1 7 0"/><path d="M14.5 10h5M14.5 13.5h4"/>',
        'letters' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'system' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.3M12 19.2v2.3M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.3M19.2 12h2.3M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"/>',
        'log' => '<path d="M8.5 6h12M8.5 12h12M8.5 18h12"/><circle cx="4" cy="6" r="1.3"/><circle cx="4" cy="12" r="1.3"/><circle cx="4" cy="18" r="1.3"/>',

        // Aktionen
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'user-plus' => '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20a6.5 6.5 0 0 1 13 0"/><path d="M18.5 8v6M15.5 11h6"/>',
        'card-plus' => '<rect x="2.5" y="5" width="13.5" height="14" rx="2"/><circle cx="8" cy="10.5" r="1.8"/><path d="M5 16.3a3.2 3.2 0 0 1 6 0"/><path d="M19.5 9v8M15.5 13h8"/>',
        'mail-new' => '<rect x="2.5" y="5" width="14" height="11" rx="2"/><path d="m3.2 7 6.3 4.4L15.8 7"/><path d="M18.5 14.5v7M15 18h7"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.9-3.9"/>',
        'download' => '<path d="M12 3v11"/><path d="m7.5 9.5 4.5 4.5 4.5-4.5"/><path d="M4 18v2.5h16V18"/>',
        'eye' => '<path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'check' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.2 12.2 2.6 2.6 5-5.6"/>',
        'warning' => '<path d="M12 3.5 21 19.5H3z"/><path d="M12 9.5V14M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8h.01"/>',
        'shield' => '<path d="M12 3 5 5.5v6c0 4.6 3 7.6 7 8.5 4-.9 7-3.9 7-8.5v-6z"/><path d="m9 12 2 2 4-4"/>',
        'edit' => '<path d="M4 20h16"/><path d="M14.5 4.5a2 2 0 0 1 2.8 2.8L8 16.6l-3.5.9.9-3.5z"/>',
        'settings' => '<path d="M4 7h9M19 7h1M4 17h5M15 17h5"/><circle cx="16" cy="7" r="2.2"/><circle cx="11" cy="17" r="2.2"/>',
        'filter' => '<path d="M3 5h18l-7 8.2V19l-4-2v-3.8z"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.4-5.7"/><path d="M20 4v5h-5"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-8 8"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'back' => '<path d="m14 6-6 6 6 6"/>',
        'next' => '<path d="m10 6 6 6-6 6"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'database' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v12c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
        'printer' => '<path d="M7 9V3h10v6"/><path d="M7 19H5a2 2 0 0 1-2-2v-5h18v5a2 2 0 0 1-2 2h-2"/><rect x="7" y="14" width="10" height="7"/>',
        'help' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.7.4-1 .9-1 1.6M12 16.8h.01"/>',
        'pulse' => '<path d="M2.5 12h4l2-5.5 3 11 2.5-5.5H21.5"/>',
        'list' => '<path d="M4 6h16M4 12h16M4 18h10"/>',

        self::FALLBACK => '<circle cx="12" cy="12" r="3.5"/>',
    ];

    /**
     * Prueft, ob ein Symbol gezeichnet werden kann (unabhaengig vom Fallback).
     */
    public static function has(string $name): bool
    {
        return isset(self::PATHS[$name]) && $name !== self::FALLBACK;
    }

    /**
     * @return list<string> Alle bekannten Symbolnamen (ohne Fallback)
     */
    public static function names(): array
    {
        return array_values(array_filter(array_keys(self::PATHS), static fn (string $name): bool => $name !== self::FALLBACK));
    }

    /**
     * Zeichnung ohne umgebendes <svg> – fuer eigene Zusammenstellungen.
     */
    public static function paths(string $name): string
    {
        return self::PATHS[$name] ?? self::PATHS[self::FALLBACK];
    }

    /**
     * Fertiges Symbol. Unbekannte Namen ergeben den Fallback statt eines Fehlers,
     * damit ein Tippfehler niemals eine Seite unbrauchbar macht.
     */
    public static function svg(string $name, string $class = self::DEFAULT_CLASS): string
    {
        return '<svg class="' . View::escape($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . self::paths($name) . '</svg>';
    }
}
