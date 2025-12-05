<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $new_storage_path = config('filesystems.new_storage_path');

        if ($new_storage_path) {
            config([
                // Override compiled views path
                'view.compiled'                => $new_storage_path . '/framework/views',
                'debugbar.storage.path'        => $new_storage_path . '/debugbar',
                'logging.channels.single.path' => $new_storage_path . '/logs/laravel.log',
                'logging.channels.daily.path'  => $new_storage_path . '/logs/laravel.log',
                'logging.channels.stack.path'  => $new_storage_path . '/logs/laravel.log',
            ]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $new_storage_path = config('filesystems.new_storage_path');
        $new_public_path  = config('filesystems.new_public_path');

        if ($new_storage_path && $new_public_path) {
            // Override symbolic links configuration
            config([
                'filesystems.links' => [
                    $new_public_path . '/storage' => $new_storage_path . '/app/public',
                ],
            ]);
        }

        require_once app_path('Supports/helpers.php');
    }
}
