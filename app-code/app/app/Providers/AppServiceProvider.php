<?php

declare(strict_types=1);

namespace App\Providers;

use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Ai\AiTranslationService;
// use App\Supports\Services\Translations\Product\ProductAttributeTextAiTranslatorService;
// use App\Supports\Services\Translations\Product\ProductDescriptionAiTranslatorService;
// use App\Supports\Services\Translations\Product\ProductNameAiTranslatorService;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Client;
use RuntimeException;

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
                'view.compiled'                => $new_storage_path.'/framework/views',
                'debugbar.storage.path'        => $new_storage_path.'/debugbar',
                'logging.channels.single.path' => $new_storage_path.'/logs/laravel.log',
                'logging.channels.daily.path'  => $new_storage_path.'/logs/laravel.log',
                'logging.channels.stack.path'  => $new_storage_path.'/logs/laravel.log',
            ]);
        }

        $this->app->singleton(Client::class, function () {
            $api_key = (string) config('open-ai.api_key');

            if (empty($api_key)) {
                throw new RuntimeException('OpenAI API key is not configured');
            }

            return OpenAI::client($api_key);
        });

        $this->app->singleton(AiTranslationService::class);
        $this->app->singleton(AiTranslationPromptBuilderService::class);
        //        $this->app->singleton(ProductNameAiTranslatorService::class);
        //        $this->app->singleton(ProductDescriptionAiTranslatorService::class);
        //        $this->app->singleton(ProductAttributeTextAiTranslatorService::class);
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
                    $new_public_path.'/storage' => $new_storage_path.'/app/public',
                ],
            ]);
        }

        require_once app_path('Supports/helpers.php');

        if (app()->isLocal() && app()->hasDebugModeEnabled() === true) {
            // Check SQL queries in the local environment for remote debugging
            DB::listen(function (QueryExecuted $query) {
                $bindings = $query->bindings;

                // Replace placeholders with quoted bindings for readable SQL.
                $sql_template = str_replace(['%', '?'], ['#', '%s'], $query->sql);

                $sql = vsprintf($sql_template, array_map(function ($binding) {
                    if (is_string($binding)) {
                        return "'".addslashes($binding)."'";
                    }

                    if ($binding instanceof DateTimeInterface) {
                        return "'".$binding->format('Y-m-d H:i:s')."'";
                    }

                    if (is_bool($binding)) {
                        return $binding ? '1' : '0';
                    }

                    return $binding === null ? 'NULL' : $binding;
                }, $bindings)) ?: $query->sql;

                $res = $sql;
            });
        }
    }
}
