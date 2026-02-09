<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web     : __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health  : '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Override storage path immediately after app creation
$new_storage_path = getenv('NEW_STORAGE_PATH');

if ($new_storage_path) {
    $app->useStoragePath($new_storage_path);
}

// Override public path
$new_public_path = getenv('NEW_PUBLIC_PATH');

if ($new_public_path) {
    $app->usePublicPath($new_public_path);
}

return $app;
