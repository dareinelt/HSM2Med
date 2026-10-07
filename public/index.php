<?php

declare(strict_types=1);

/** @var App\Application $app */
$app = require __DIR__ . '/../src/bootstrap.php';

(new App\Http\Kernel($app))
    ->handle(App\Http\Request::fromGlobals())
    ->send();
