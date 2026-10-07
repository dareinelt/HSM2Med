<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application;
use App\Http\View;

abstract class Controller
{
    public function __construct(
        protected readonly Application $app,
        protected readonly View $view,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    protected static function id(array $params, string $key = 'id'): int
    {
        return (int) ($params[$key] ?? 0);
    }

    protected static function page(string $value): int
    {
        return ctype_digit($value) && (int) $value > 0 && (int) $value < 100000 ? (int) $value : 1;
    }
}
