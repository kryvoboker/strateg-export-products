<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

if (!function_exists('string_to_array')) {
    function string_to_array(?string $string, string $separator = ','): array
    {
        if ($string === null || Str::trim($string) === '') {
            return [];
        }

        $values = array_map(
            static fn (string $value): string => Str::trim($value),
            explode($separator, $string),
        );

        return array_values(array_filter($values));
    }
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web     : __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health  : '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only handle 500 errors in production
            if (config('app.debug') === false && $request->expectsJson() === false && method_exists($e, 'getStatusCode')) {
                // Only render custom view for 500 errors
                if ($e->getStatusCode() === Response::HTTP_INTERNAL_SERVER_ERROR) {
                    return response()->view('errors.500', [], Response::HTTP_INTERNAL_SERVER_ERROR);
                } elseif ($e->getStatusCode() === Response::HTTP_SERVICE_UNAVAILABLE) {
                    return response()->view('errors.503', [], Response::HTTP_SERVICE_UNAVAILABLE);
                }
            }

            // Return null to let Laravel handle other errors by default
            return null;
        });
    })->create();

$new_storage_path = getenv('NEW_STORAGE_PATH');
$new_public_path  = getenv('NEW_PUBLIC_PATH');

if (empty($new_storage_path) || empty($new_public_path)) {
    exit('NEW_STORAGE_PATH and NEW_PUBLIC_PATH environment variables must be set!');
}

// Override storage path immediately after app creation
$app->useStoragePath($new_storage_path);

// Override public path
$app->usePublicPath($new_public_path);

return $app;
