<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyFoundationResponse;

class LogFilamentErrors
{
    /**
     * @param Request                                       $request
     * @param Closure(Request): (SymfonyFoundationResponse) $next
     *
     * @return JsonResponse|Response|RedirectResponse
     */
    public function handle(Request $request, Closure $next): JsonResponse|Response|RedirectResponse
    {
        /** @var JsonResponse $response */
        $response = $next($request);

        $is_filament = $request->is('admin/*');
        $is_livewire = $request->is('livewire/*');

        $message = 'Filament error' . ($is_livewire ? ' (Livewire)' : '');

        if ($response->getStatusCode() >= SymfonyFoundationResponse::HTTP_BAD_REQUEST && ($is_filament || $is_livewire)) {
            $exception = $response->exception;

            if ($exception) {
                $message .= ': ' . $exception->getMessage();
            }

            Log::channel('stack')->error($message, [
                'url'    => $request->fullUrl(),
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
                'input'  => $request->except(['password', '_token']),
            ]);
        }

        return $response;
    }
}
