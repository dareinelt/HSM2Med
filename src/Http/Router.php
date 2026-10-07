<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimaler Router: Methode + Pfadmuster mit {name}-Platzhaltern (nur Ziffern bzw. Hex-Token).
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
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

    private function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => match ($m[1]) {
            'token' => '(?P<token>[a-f0-9]{32})',
            default => '(?P<' . $m[1] . '>[1-9][0-9]{0,18})',
        }, $pattern);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#D', 'handler' => $handler];
    }
}
