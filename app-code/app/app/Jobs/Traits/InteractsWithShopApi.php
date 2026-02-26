<?php

declare(strict_types=1);

namespace App\Jobs\Traits;

use App\Models\Shops\Shop;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

trait InteractsWithShopApi
{
    /**
     * Build absolute endpoint URL and validate it before outbound request.
     */
    protected function shopApiResolveAbsoluteEndpointUrl(string $base_url, string $endpoint, string $operation): string
    {
        $clean_base_url = Str::rtrim(Str::trim($base_url), '/');
        $clean_endpoint = Str::trim($endpoint);

        if ($clean_endpoint === '') {
            throw new RuntimeException('Missing endpoint for '.$operation);
        }

        $resolved_url = Str::startsWith($clean_endpoint, ['http://', 'https://'])
            ? $clean_endpoint
            : $clean_base_url.'/'.Str::ltrim($clean_endpoint, '/');

        if (validate_url($resolved_url) === false) {
            throw new RuntimeException('Invalid URL for '.$operation.' request');
        }

        return $resolved_url;
    }

    protected function shopApiIsOpenCartShop(Shop $shop): bool
    {
        $shop_type     = Str::lower(Str::trim((string) ($shop->type ?? '')));
        $allowed_types = config('app.allowed_projects_types.opencart', []);

        if (! is_array($allowed_types)) {
            return false;
        }

        foreach ($allowed_types as $allowed_type) {
            if (Str::lower(Str::trim((string) $allowed_type)) === $shop_type) {
                return true;
            }
        }

        return false;
    }

    protected function shopApiResolveBaseUrlForDefaultApi(Shop $shop): string
    {
        $api_url  = Str::trim((string) ($shop->api_url ?? ''));
        $base_url = $api_url !== '' ? $api_url : Str::trim((string) ($shop->base_url ?? ''));

        if ($base_url === '') {
            throw new RuntimeException('Missing api_url/base_url for API requests');
        }

        return Str::rtrim($base_url, '/');
    }

    protected function shopApiResolveApiBaseUrl(Shop $shop): string
    {
        $api_base_url = $this->shopApiResolveBaseUrlForDefaultApi($shop);

        if (validate_url($api_base_url) === false) {
            throw new RuntimeException('Invalid api_url/base_url for OpenCart API requests');
        }

        return $api_base_url;
    }

    protected function shopApiResolveOpenCartLoginUrl(Shop $shop, string $api_base_url): string
    {
        $part_api_url_login = Str::trim((string) ($shop->part_api_url_login ?? ''));

        if ($part_api_url_login === '') {
            throw new RuntimeException('Missing part_api_url_login for OpenCart API requests');
        }

        if (Str::startsWith($part_api_url_login, ['http://', 'https://'])) {
            return $part_api_url_login;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($part_api_url_login, '/');
    }

    protected function shopApiResolveStoredAuthApiToken(Shop $shop): string
    {
        $options = is_array($shop->options) ? $shop->options : [];

        return Str::trim((string) Arr::get($options, 'auth_api_token', ''));
    }

    protected function shopApiPersistOpenCartAuthApiToken(Shop $shop, string $auth_api_token): void
    {
        $clean_auth_api_token = Str::trim($auth_api_token);
        if ($clean_auth_api_token === '') {
            return;
        }

        $options                   = is_array($shop->options) ? $shop->options : [];
        $options['auth_api_token'] = $clean_auth_api_token;

        $shop->update([
            'options' => $options,
        ]);
    }

    /**
     * @throws ConnectionException
     */
    protected function shopApiRequestOpenCartAuthApiTokenWithKey(Shop $shop, string $api_base_url, int $timeout): string
    {
        $login_url = $this->shopApiResolveOpenCartLoginUrl($shop, $api_base_url);
        $options   = is_array($shop->options) ? $shop->options : [];

        $api_username = Str::trim((string) Arr::get($options, 'api_username', ''));
        $api_token    = Str::trim((string) ($shop->api_token ?? ''));

        if ($api_token === '') {
            throw new RuntimeException('Missing api_token for OpenCart login API request');
        }

        $request = Http::timeout($timeout)->asForm();
        $request = $this->shopApiApplyDebugCookieForDevelopment($request);

        $response = $request->post($login_url, [
            'username' => $api_username,
            'key'      => $api_token,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->shopApiBuildFailedResponseMessage('OpenCart login API', $response));
        }

        $response_data  = $response->json();
        $auth_api_token = Str::trim((string) Arr::get($response_data, 'api_token', ''));

        if ($auth_api_token === '') {
            throw new RuntimeException('OpenCart login API did not return api_token');
        }

        return $auth_api_token;
    }

    /**
     * @throws ConnectionException
     */
    protected function shopApiRequestOpenCartAuthApiTokenWithApiToken(Shop $shop, string $api_base_url, int $timeout): string
    {
        $login_url = $this->shopApiResolveOpenCartLoginUrl($shop, $api_base_url);
        $api_token = Str::trim((string) ($shop->api_token ?? ''));

        if ($api_token === '') {
            throw new RuntimeException('Shop API token is missing');
        }

        $request = Http::timeout($timeout)->asForm();
        $request = $this->shopApiApplyDebugCookieForDevelopment($request);

        $response = $request->post($login_url, [
            'api_token' => $api_token,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->shopApiBuildFailedResponseMessage('OpenCart auth API', $response));
        }

        $auth_api_token = Str::trim((string) Arr::get($response->json(), 'api_token', ''));
        if ($auth_api_token === '') {
            throw new RuntimeException('OpenCart auth API token is empty');
        }

        return $auth_api_token;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    protected function shopApiSendOpenCartRequestWithQueryAuthToken(
        string $request_url,
        string $auth_api_token,
        array $payload,
        int $timeout
    ): Response {
        $url = $request_url;
        $url .= Str::contains($request_url, '?') ? '&' : '?';
        $url .= 'api_token='.urlencode($auth_api_token);

        $request = Http::timeout($timeout)->asForm();
        $request = $this->shopApiApplyDebugCookieForDevelopment($request);

        return $request->post($url, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    protected function shopApiSendOpenCartRequestWithBodyAuthToken(
        string $request_url,
        string $auth_api_token,
        array $payload,
        int $timeout
    ): Response {
        $request = Http::timeout($timeout)->asForm();
        $request = $this->shopApiApplyDebugCookieForDevelopment($request);

        return $request->post($request_url, [
            ...$payload,
            'api_token' => $auth_api_token,
        ]);
    }

    protected function shopApiApplyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        if (! $this->shopApiShouldApplyDebugCookieForDevelopment()) {
            return $request;
        }

        return $request->withHeaders([
            'Cookie' => 'XDEBUG_SESSION=PHPSTORM',
        ]);
    }

    protected function shopApiShouldApplyDebugCookieForDevelopment(): bool
    {
        return config('app.debug', false) && app()->isLocal();
    }

    protected function shopApiIsInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        if ($response->status() === 401 || $response->status() === 403) {
            return true;
        }

        $response_body = Str::lower(Str::trim($response->body()));
        if ($response_body === '') {
            return false;
        }

        if (Str::startsWith($response_body, '<!doctype html>') || Str::startsWith($response_body, '<html')) {
            return true;
        }

        if (
            Str::contains($response_body, 'error_invalid_token')
            || (Str::contains($response_body, 'invalid') && Str::contains($response_body, 'token'))
            || Str::contains($response_body, 'token is invalid')
            || Str::contains($response_body, 'invalid api token')
            || Str::contains($response_body, 'api token is invalid')
            || Str::contains($response_body, 'permission denied')
        ) {
            return true;
        }

        $response_data = $response->json();
        if (! is_array($response_data)) {
            return false;
        }

        if (Arr::has($response_data, 'error_invalid_token')) {
            return true;
        }

        $error_text = Str::lower(Str::trim((string) Arr::get($response_data, 'error', '')));

        return $error_text !== '' && Str::contains($error_text, 'token');
    }

    protected function shopApiTruncateResponseBody(string $response_body): string
    {
        return Str::limit(Str::trim($response_body), 20000);
    }

    /**
     * Build a safe error message for failed remote responses.
     */
    protected function shopApiBuildFailedResponseMessage(string $operation, Response $response): string
    {
        $safe_response_body = $this->shopApiSanitizeSensitiveText($response->body());

        return $operation.' failed with status '.$response->status().': '.$this->shopApiTruncateResponseBody($safe_response_body);
    }

    /**
     * Remove token-like values from text before persisting/logging.
     */
    protected function shopApiSanitizeSensitiveText(string $text): string
    {
        $sanitized_text = preg_replace('/(api_token=)[^&\\s]+/i', '$1[REDACTED]', $text) ?? $text;
        $sanitized_text = preg_replace('/(Bearer\s+)[A-Za-z0-9\-._~+\/=]+/i', '$1[REDACTED]', $sanitized_text) ?? $sanitized_text;
        $sanitized_text = preg_replace('/((?:api_token|token|access_token|authorization)\\s*[=:]\\s*)([^\\s,;]+)/i', '$1[REDACTED]', $sanitized_text) ?? $sanitized_text;

        return preg_replace('/("(?:api_token|token|access_token|authorization)"\\s*:\\s*")[^"]*(")/i', '$1[REDACTED]$2', $sanitized_text) ?? $sanitized_text;
    }

    protected function shopApiNormalizeDateTimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return (string) $value->toDateTimeString();
        }

        $clean_value = trim((string) $value);

        return $clean_value !== '' ? $clean_value : null;
    }
}
