<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Security\Csrf;
use App\Security\SessionManager;
use App\User\Permission;
use App\User\User;
use RuntimeException;

/**
 * PHP-Templates mit konsequentem Escaping (Hilfsfunktion $e).
 */
final class View
{
    /**
     * @param (\Closure(): array{id:int,name:string,birth:string,identifier:string}|null)|null $activePatient
     * @param (\Closure(): \App\User\User|null)|null $currentUser
     */
    public function __construct(
        private readonly string $templateDir,
        private readonly ?\Closure $activePatient = null,
        private readonly ?\Closure $currentUser = null,
    ) {
    }

    /**
     * Rendert eine Seite im Rahmen der Oberflaeche.
     *
     * @param array<string, mixed> $vars
     * @param array{error?:?string,username?:string,target?:string}|null $login Wird die Seite hinter
     *        dem Anmeldefenster dargestellt (nicht angemeldet), uebernimmt der Rahmen die Anzeige
     *        des Fensters: die eigentliche Seite wird nur noch als gedimmter, unscharfer
     *        Hintergrund gezeigt.
     */
    public function render(string $template, array $vars = [], string $active = '', ?array $login = null): string
    {
        $user = $this->currentUser === null ? null : ($this->currentUser)();
        // Dieselbe Zuordnung, die der Kernel durchsetzt: Templates zeigen nur, was auch
        // aufrufbar ist. Sie liegt darum allen Seiten (nicht nur dem Rahmen) vor.
        $permitted = self::permitted($user);
        $content = $this->renderPartial($template, $vars + ['permitted' => $permitted]);
        return $this->renderPartial('layout', [
            'content' => $content,
            'title' => (string) ($vars['title'] ?? Config::APP_NAME),
            'active' => $active,
            'login' => $login,
            'user' => $user,
            'permitted' => $permitted,
            'activePatient' => $this->activePatient === null ? null : ($this->activePatient)(),
            'flashes' => session_status() === PHP_SESSION_ACTIVE ? SessionManager::takeFlashes() : [],
        ]);
    }

    /**
     * Rechteprüfung für Templates: darf das Ziel hinter $href geoeffnet werden?
     *
     * @return \Closure(string): bool
     */
    public static function permitted(?User $user): \Closure
    {
        return static fn (string $href): bool => $user === null || $user->hasPermission(Permission::forPath($href));
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function renderPartial(string $template, array $vars = []): string
    {
        if (preg_match('/^[a-z0-9_\/]+$/D', $template) !== 1) {
            throw new RuntimeException('Ungueltiger Template-Name.');
        }
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template nicht gefunden: ' . $template);
        }
        $e = self::escape(...);
        $csrf = static fn (): string => '<input type="hidden" name="_csrf" value="' . self::escape(Csrf::token()) . '">';
        $icon = static fn (string $name, string $class = Icon::DEFAULT_CLASS): string => Icon::svg($name, $class);
        $view = $this;
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    public static function escape(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $string = is_scalar($value) ? (string) $value : '';
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'Windows-1252');
        }
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Macht Steuerzeichen sichtbar (z.B. Feldtrenner 0x1C als ␜) und escaped.
     */
    public static function raw(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $visible = preg_replace_callback('/[\x00-\x08\x0B-\x1F\x7F]/', static fn (array $m): string =>
            $m[0] === "\x1C" ? "\u{241C}" : sprintf('[0x%02X]', ord($m[0])), $value);
        return self::escape($visible);
    }

    public static function dateTime(?string $value, bool $dateOnly = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value) ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($ts === false) {
            return $value;
        }
        return $ts->format($dateOnly ? 'd.m.Y' : 'd.m.Y H:i');
    }
}
