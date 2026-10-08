<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimaler Router: Methode + Pfadmuster mit {name}-Platzhaltern.
 *
 * Erlaubte Platzhaltertypen: {id}/{patient} (Ziffern), {token} (Hex-Token),
 * {slug} (kleingeschriebener Bezeichner, z. B. Bausteintyp der Patientenakte).
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable, patient: bool}> */
    private array $routes = [];

    /**
     * @param bool $requiresPatient Der Patientenvorgang ist fuehrend: ohne aktiven Patienten
     *                             ist die Route nicht erreichbar.
     */
    public function get(string $pattern, callable $handler, bool $requiresPatient = false): void
    {
        $this->add('GET', $pattern, $handler, $requiresPatient);
    }

    /**
     * @param bool $requiresPatient Der Patientenvorgang ist fuehrend: ohne aktiven Patienten
     *                             ist die Route nicht erreichbar.
     */
    public function post(string $pattern, callable $handler, bool $requiresPatient = false): void
    {
        $this->add('POST', $pattern, $handler, $requiresPatient);
    }

    /**
     * Setzt die angeforderte Route einen aktiven Patienten voraus?
     */
    public function requiresPatient(Request $request): bool
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && preg_match($route['regex'], $request->path) === 1) {
                return $route['patient'];
            }
        }
        return false;
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

    private function add(string $method, string $pattern, callable $handler, bool $requiresPatient): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => match ($m[1]) {
            'token' => '(?P<token>[a-f0-9]{32})',
            'slug' => '(?P<slug>[a-z][a-z0-9_]{0,31})',
            default => '(?P<' . $m[1] . '>[1-9][0-9]{0,18})',
        }, $pattern);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#D',
            'handler' => $handler,
            'patient' => $requiresPatient,
        ];
    }
}
