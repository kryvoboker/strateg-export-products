<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultLocalePrefix
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $allowed_locales = get_allowed_locales();
        $path_info = Str::ltrim($request->getPathInfo(), '/');
        $is_livewire_request = Str::startsWith($path_info, ['livewire-', 'livewire/']);
        $locale_key = string_value(config('localization.locale_parameter'));
        $fallback_locale = $this->resolveFallbackLocale($allowed_locales);
        $session_locale = session($locale_key);
        $normalized_session_locale = is_string($session_locale) && in_array($session_locale, $allowed_locales, true)
            ? $session_locale
            : $fallback_locale;

        if ($is_livewire_request) {
            session()->put($locale_key, $normalized_session_locale);
            app()->setLocale($normalized_session_locale);
            URL::defaults([$locale_key => $normalized_session_locale]);
            config(['app.locale' => $normalized_session_locale]);

            return $next($request);
        }

        /** @var Route|null $route */
        $route = $request->route();

        if ($route === null) {
            $this->applyLocaleFromPath($request);

            return $next($request);
        }

        $route_name = $route->getName();
        $route_locale = $route->parameter($locale_key);
        $has_locale_parameter = in_array($locale_key, $route->parameterNames(), true);
        $has_valid_route_locale = is_string($route_locale) && in_array($route_locale, $allowed_locales, true);
        $path_locale = Str::before($path_info, '/');
        $has_valid_path_locale = in_array($path_locale, $allowed_locales, true);

        if ($has_locale_parameter && !$has_valid_route_locale && filled($route_name)) {
            $route_parameters = array_merge($route->parameters(), [
                $locale_key => $normalized_session_locale,
            ]);

            return redirect()->route(
                $route_name,
                $route_parameters,
                Response::HTTP_TEMPORARY_REDIRECT,
            );
        }

        $resolved_locale = $has_valid_route_locale
            ? $route_locale
            : ($has_valid_path_locale ? $path_locale : $normalized_session_locale);
        $this->applyLocale($request, (string) $resolved_locale);

        return $next($request);
    }

    /**
     * Apply a valid locale prefix when Laravel could not resolve a route.
     *
     * Unmatched storefront URLs do not execute route middleware, but their
     * public 404 response still needs to use the locale from the URL.
     */
    public function applyLocaleFromPath(Request $request): void
    {
        $allowed_locales = get_allowed_locales();
        $path_info = Str::ltrim($request->getPathInfo(), '/');
        $path_locale = Str::before($path_info, '/');

        if (in_array($path_locale, $allowed_locales, true)) {
            $this->applyLocale($request, $path_locale);
        }
    }

    private function applyLocale(Request $request, string $resolved_locale): void
    {
        $locale_key = string_value(config('localization.locale_parameter'));

        session()->put($locale_key, $resolved_locale);
        app()->setLocale($resolved_locale);
        URL::defaults([$locale_key => $resolved_locale]);
        config(['app.locale' => $resolved_locale]);
    }

    /**
     * @param array<int, string> $allowed_locales
     */
    private function resolveFallbackLocale(array $allowed_locales): string
    {
        $configured_locale_value = config('app.locale', 'en');
        $configured_locale = is_scalar($configured_locale_value) ? (string) $configured_locale_value : 'en';

        if (in_array($configured_locale, $allowed_locales, true)) {
            return $configured_locale;
        }

        return Arr::first($allowed_locales, default: 'en');
    }
}
