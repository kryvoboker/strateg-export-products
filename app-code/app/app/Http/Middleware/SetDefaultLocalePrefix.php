<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultLocalePrefix
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $path_info           = Str::ltrim($request->getPathInfo(), '/');
        $is_livewire_request = Str::startsWith($path_info, ['livewire-', 'livewire/']);

        $locale = $request->route('locale');

        if ($is_livewire_request) {
            $resolved_locale = $locale ?: session('locale', config('app.locale', 'en'));

            session()->put(
                'locale',
                $resolved_locale,
            );
            app()->setLocale($resolved_locale);
            url()->defaults(['locale' => $resolved_locale]);

            config(['app.locale' => $resolved_locale]);

            return $next($request);
        }

        // If locale is missing or invalid, redirect with locale
        if (! $locale || ! in_array($locale, config('app.locales', ['en']))) {
            $locale     = session('locale', config('app.locale', 'en'));
            $route      = $request->route();
            $route_name = $route?->getName();

            if ($route_name) {
                return redirect()->route(
                    $route_name,
                    array_merge($route->parameters(), ['locale' => $locale]),
                    Response::HTTP_TEMPORARY_REDIRECT,
                );
            }
        }

        session()->put('locale', $locale);
        app()->setLocale($locale);
        url()->defaults(['locale' => $locale]);

        config(['app.locale' => $locale]);

        return $next($request);
    }
}
