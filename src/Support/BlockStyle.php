<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Darstellung eines Bausteins in den Vorlagen (Brief und Patientenausweis).
 *
 * Jeder Baustein fuehrt die Option "font_size" (Schriftgroesse relativ zur Grundgroesse des
 * Bausteins); Bausteine mit Zeilen aus Beschriftung und Wert fuehren zusaetzlich je Zeile eine
 * Option zur Fettdarstellung ("label", "value", "both" oder "none"). Die Ueberschriften bleiben
 * unabhaengig davon fett.
 */
final class BlockStyle
{
    /** Schriftgroessen je Baustein, relativ zur Grundgroesse des Bausteins. */
    public const array FONT_SIZES = [
        'small' => 0.85,
        'normal' => 1.0,
        'large' => 1.15,
        'xlarge' => 1.3,
    ];

    /** Auswahl fuer die Fettdarstellung von Beschriftung und Wert einer Zeile. */
    public const array BOLD_CHOICES = [
        'label' => 'Beschriftung fett',
        'value' => 'Wert fett',
        'both' => 'Beides fett',
        'none' => 'Nicht fett',
    ];

    /**
     * Definition der Option "font_size" fuer eine Baustein- oder Zonenbeschreibung.
     *
     * @return array<string, mixed>
     */
    public static function fontSizeOption(): array
    {
        return [
            'label' => 'Schriftgröße',
            'type' => 'select',
            'default' => 'normal',
            'choices' => [
                'small' => 'Klein',
                'normal' => 'Normal',
                'large' => 'Groß',
                'xlarge' => 'Sehr groß',
            ],
        ];
    }

    /**
     * Definition einer Option zur Fettdarstellung einer Zeile.
     *
     * @return array<string, mixed>
     */
    public static function boldOption(string $label): array
    {
        return [
            'label' => $label,
            'type' => 'select',
            'default' => 'value',
            'choices' => self::BOLD_CHOICES,
        ];
    }

    /**
     * Schriftgroesse eines Bausteins als Faktor zur Grundgroesse.
     *
     * @param array<string, mixed> $block
     */
    public static function scale(array $block): float
    {
        $choice = (string) ($block['options']['font_size'] ?? 'normal');
        return self::FONT_SIZES[$choice] ?? 1.0;
    }

    /**
     * Schriftschnitte einer Zeile: [Beschriftung, Wert].
     *
     * @param array<string, mixed> $block
     * @return array{0: string, 1: string}
     */
    public static function emphasis(array $block, string $option): array
    {
        return match ((string) ($block['options'][$option] ?? 'value')) {
            'label' => ['bold', 'regular'],
            'both' => ['bold', 'bold'],
            'none' => ['regular', 'regular'],
            default => ['regular', 'bold'],
        };
    }
}
