<?php

declare(strict_types=1);

use App\Http\Middleware\LogFilamentErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use LaravelLang\Routes\Middlewares\LocalizationByCookie;
use LaravelLang\Routes\Middlewares\LocalizationByHeader;
use LaravelLang\Routes\Middlewares\LocalizationByModel;
use LaravelLang\Routes\Middlewares\LocalizationByParameter;
use LaravelLang\Routes\Middlewares\LocalizationByParameterWithRedirect;
use LaravelLang\Routes\Middlewares\LocalizationBySession;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web     : __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health  : '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [
            LogFilamentErrors::class,
        ]);

        $middleware->alias([
            'localization.header'    => LocalizationByHeader::class,
            'localization.cookie'    => LocalizationByCookie::class,
            'localization.session'   => LocalizationBySession::class,
            'localization.model'     => LocalizationByModel::class,
            'localization.parameter' => LocalizationByParameter::class,
            'localization.redirect'  => LocalizationByParameterWithRedirect::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// TODO: need fix path
// Override storage path immediately after app creation
$new_storage_path = getenv('NEW_STORAGE_PATH');

if ($new_storage_path) {
    $app->useStoragePath($new_storage_path);
}

// TODO: need fix path
// Override public path
$new_public_path = getenv('NEW_PUBLIC_PATH');

if ($new_public_path) {
    $app->usePublicPath($new_public_path);
}

return $app;
