<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimaler Router: Methode + Pfadmuster mit {name}-Platzhaltern.
 *
 * Erlaubte Platzhaltertypen: {id}/{patient} (Ziffern), {token} (Hex-Token),
 * {slug} (kleingeschriebener Bezeichner, z. B. Bausteintyp der Patientenakte).
 *
 * Jede Route nennt das Recht, das sie voraussetzt (null = ohne Recht erreichbar). Das Recht
 * wird im Kernel geprueft, nicht hier – der Router kennt nur die Zuordnung. Die Zuordnung
 * muss zu Permission::forPath() passen; ein Test haelt beides deckungsgleich, damit die
 * Anzeige (ausgeblendete Ziele) und die Sperre nie auseinanderlaufen.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, permission: ?string, patient: bool}> */
    private array $routes = [];

    /**
     * @param ?string $permission     Recht, das die Route voraussetzt (null = kein Recht noetig)
     * @param bool    $requiresPatient Der Patientenvorgang ist fuehrend: ohne aktiven Patienten
     *                                ist die Route nicht erreichbar.
     */
    public function get(string $pattern, callable $handler, ?string $permission = null, bool $requiresPatient = false): void
    {
        $this->add('GET', $pattern, $handler, $permission, $requiresPatient);
    }

    /**
     * @param ?string $permission     Recht, das die Route voraussetzt (null = kein Recht noetig)
     * @param bool    $requiresPatient Der Patientenvorgang ist fuehrend: ohne aktiven Patienten
     *                                ist die Route nicht erreichbar.
     */
    public function post(string $pattern, callable $handler, ?string $permission = null, bool $requiresPatient = false): void
    {
        $this->add('POST', $pattern, $handler, $permission, $requiresPatient);
    }

    /**
     * Setzt die angeforderte Route einen aktiven Patienten voraus?
     */
    public function requiresPatient(Request $request): bool
    {
        $route = $this->match($request);
        return $route['patient'] ?? false;
    }

    /**
     * Recht, das die angeforderte Route voraussetzt (null = kein Recht noetig).
     */
    public function permission(Request $request): ?string
    {
        $route = $this->match($request);
        return $route['permission'] ?? null;
    }

    /**
     * Alle registrierten Routen (Grundlage fuer Tests und Dokumentation).
     *
     * @return list<array{method:string,pattern:string,permission:?string,patient:bool}>
     */
    public function definitions(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method' => $route['method'],
                'pattern' => $route['pattern'],
                'permission' => $route['permission'],
                'patient' => $route['patient'],
            ],
            $this->routes,
        );
    }

    public function dispatch(Request $request): Response
    {
        $methodAllowed = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            $method = $request->method === 'HEAD' ? 'GET' : $request->method;
            if ($route['method'] !== $method) {
                $methodAllowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request, $params);
        }
        if ($methodAllowed) {
            throw new HttpException(405, 'Methode nicht erlaubt.');
        }
        throw HttpException::notFound();
    }

    /**
     * @return array{method: string, pattern: string, regex: string, handler: callable, permission: ?string, patient: bool}|null
     */
    private function match(Request $request): ?array
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['regex'], $request->path) === 1) {
                return $route;
            }
        }
        return null;
    }

    private function add(string $method, string $pattern, callable $handler, ?string $permission, bool $requiresPatient): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => match ($m[1]) {
            'token' => '(?P<token>[a-f0-9]{32})',
            'slug' => '(?P<slug>[a-z][a-z0-9_]{0,31})',
            default => '(?P<' . $m[1] . '>[1-9][0-9]{0,18})',
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#D',
            'handler' => $handler,
            'permission' => $permission,
            'patient' => $requiresPatient,
        ];
    }
}
