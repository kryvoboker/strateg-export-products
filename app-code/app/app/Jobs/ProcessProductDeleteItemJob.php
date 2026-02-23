<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class ProcessProductDeleteItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $product_delete_item_id) {}

    public function handle(ProductDeleteQueueService $product_delete_queue_service): void
    {
        $product_delete_item = ProductDeleteItem::query()->find($this->product_delete_item_id);
        if (! $product_delete_item instanceof ProductDeleteItem) {
            return;
        }

        $payload              = is_array($product_delete_item->payload) ? $product_delete_item->payload : [];
        $shop_id              = (int) Arr::get($payload, 'shop_id', 0);
        $product_id           = (int) ($product_delete_item->product_id ?? 0);
        $external_product_id  = (int) Arr::get($payload, 'external_product_id', 0);

        $product_delete_item->update([
            'status'        => ProductDeleteItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        try {
            if ($product_id <= 0 || $shop_id <= 0) {
                throw new RuntimeException('Missing product_id or shop_id for delete operation');
            }

            $shop = Shop::query()->find($shop_id);
            if (! $shop instanceof Shop) {
                throw new RuntimeException('Shop not found for delete operation');
            }

            $product = Product::query()->find($product_id);
            if (! $product instanceof Product) {
                throw new RuntimeException('Product not found for delete operation');
            }

            $product_shop = ProductShop::query()
                ->where('product_id', $product_id)
                ->where('shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();
            if (! $product_shop instanceof ProductShop) {
                Log::channel('daily')->warning('Product delete skipped because binding is missing', [
                    'product_delete_item_id'  => (int) $product_delete_item->id,
                    'product_delete_batch_id' => (int) $product_delete_item->product_delete_batch_id,
                    'product_id'              => $product_id,
                    'shop_id'                 => $shop_id,
                ]);

                throw new RuntimeException('Product is not bound to selected shop');
            }

            if ($external_product_id <= 0) {
                $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            }

            if ($external_product_id <= 0) {
                Log::channel('daily')->warning('Product delete skipped because external_product_id is missing', [
                    'product_delete_item_id'  => (int) $product_delete_item->id,
                    'product_delete_batch_id' => (int) $product_delete_item->product_delete_batch_id,
                    'product_id'              => $product_id,
                    'shop_id'                 => $shop_id,
                ]);

                throw new RuntimeException('External product id is missing for delete operation');
            }

            $backup_response = $this->sendBackupRequest($shop, $product, $external_product_id);
            if (! $backup_response->successful()) {
                throw new RuntimeException('Backup API failed with status '.$backup_response->status().': '.$backup_response->body());
            }

            $backup_payload = $backup_response->json();
            if (! is_array($backup_payload)) {
                throw new RuntimeException('Backup API response is not a JSON object');
            }

            $backup_product_id = $this->resolveProductIdFromBackupPayload($backup_payload);
            if ($backup_product_id <= 0) {
                Log::channel('daily')->warning('Product delete backup is invalid because product_id is missing', [
                    'product_delete_item_id'  => (int) $product_delete_item->id,
                    'product_delete_batch_id' => (int) $product_delete_item->product_delete_batch_id,
                    'product_id'              => $product_id,
                    'shop_id'                 => $shop_id,
                    'external_product_id'     => $external_product_id,
                ]);

                throw new RuntimeException('Backup payload does not contain valid product id');
            }

            $created_backup = ProductBackups::createUsingBackupForProduct($product_id, [
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id,
                'backup_product_id'   => $backup_product_id,
                'backup_payload'      => $backup_payload,
                'received_at'         => now()->toDateTimeString(),
                'operation'           => 'delete',
            ], $shop_id, (string) $external_product_id);

            $delete_response = $this->sendDeleteRequest($shop, $product, $external_product_id);
            if (! $delete_response->successful()) {
                throw new RuntimeException('Delete API failed with status '.$delete_response->status().': '.$delete_response->body());
            }

            $product_delete_item->update([
                'status'        => ProductDeleteItemsStatusEnum::DELETED->value,
                'error_message' => null,
                'processed_at'  => now(),
                'payload'       => [
                    ...$payload,
                    'operation'         => 'delete',
                    'shop_id'           => $shop_id,
                    'external_product_id' => $external_product_id,
                    'backup_id'         => (int) $created_backup->id,
                    'backup_product_id' => $backup_product_id,
                    'response_status'   => $delete_response->status(),
                    'response_body'     => Str::limit($delete_response->body(), 10000),
                ],
            ]);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to delete product from shop', [
                'product_delete_item_id'  => (int) $product_delete_item->id,
                'product_delete_batch_id' => (int) $product_delete_item->product_delete_batch_id,
                'product_id'              => $product_id,
                'shop_id'                 => $shop_id,
                'external_product_id'     => $external_product_id,
                'error_msg'               => $exception->getMessage(),
                'file'                    => $exception->getFile(),
                'line'                    => $exception->getLine(),
            ]);

            $product_delete_item->update([
                'status'        => ProductDeleteItemsStatusEnum::FAILED->value,
                'error_message' => Str::limit(Str::trim($exception->getMessage()), 10000),
                'processed_at'  => now(),
            ]);
        } finally {
            $product_delete_queue_service->syncBatchStatusByItems((int) $product_delete_item->product_delete_batch_id);
        }
    }

    /**
     * @throws ConnectionException
     */
    private function sendBackupRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        if ($this->isOpenCartShop($shop)) {
            return $this->sendOpenCartBackupRequest($shop, $product, $external_product_id);
        }

        return $this->sendDefaultBackupRequest($shop, $product, $external_product_id);
    }

    /**
     * @throws ConnectionException
     */
    private function sendDeleteRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        if ($this->isOpenCartShop($shop)) {
            return $this->sendOpenCartDeleteRequest($shop, $product, $external_product_id);
        }

        return $this->sendDefaultDeleteRequest($shop, $product, $external_product_id);
    }

    /**
     * @throws ConnectionException
     */
    private function sendDefaultBackupRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        $options  = is_array($shop->options) ? $shop->options : [];
        $endpoint = Str::trim((string) Arr::get($options, 'product_backup_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = Str::trim((string) Arr::get($options, 'part_api_url_backup_prods', ''));
        }
        if ($endpoint === '') {
            throw new RuntimeException('Missing product backup endpoint');
        }

        $base_url = $this->resolveBaseUrlForDefaultApi($shop);
        $timeout  = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $url      = Str::startsWith($endpoint, ['http://', 'https://'])
            ? $endpoint
            : Str::rtrim($base_url, '/').'/'.Str::ltrim($endpoint, '/');

        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string) Arr::get($options, 'api_token', ''));
        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        return $request->post($url, [
            'shop_id'             => (int) $shop->id,
            'product_id'          => (int) $product->id,
            'external_product_id' => $external_product_id,
            'operation'           => 'backup',
        ]);
    }

    /**
     * @throws ConnectionException
     */
    private function sendDefaultDeleteRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $endpoint = Str::trim((string) Arr::get($options, 'product_delete_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = Str::trim((string) Arr::get($options, 'part_api_url_delete_prods', ''));
        }
        if ($endpoint === '') {
            $endpoint = Str::trim((string) ($shop->part_api_url_delete_prods ?? ''));
        }
        if ($endpoint === '') {
            throw new RuntimeException('Missing product delete endpoint');
        }

        $base_url = $this->resolveBaseUrlForDefaultApi($shop);
        $timeout  = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $url      = Str::startsWith($endpoint, ['http://', 'https://'])
            ? $endpoint
            : Str::rtrim($base_url, '/').'/'.Str::ltrim($endpoint, '/');

        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string) Arr::get($options, 'api_token', ''));
        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        return $request->post($url, [
            'shop_id'             => (int) $shop->id,
            'product_id'          => (int) $product->id,
            'external_product_id' => $external_product_id,
            'operation'           => 'delete',
        ]);
    }

    /**
     * @throws ConnectionException
     */
    private function sendOpenCartBackupRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        $options      = is_array($shop->options) ? $shop->options : [];
        $timeout      = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $backup_url   = $this->resolveBackupUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $payload = [
            'shop_id'             => (int) $shop->id,
            'product_id'          => (int) $product->id,
            'external_product_id' => $external_product_id,
            'operation'           => 'backup',
        ];

        $response = $this->sendOpenCartRequestWithAuthApiToken($backup_url, $auth_api_token, $payload, $timeout);
        if (! $this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($backup_url, $refreshed_auth_api_token, $payload, $timeout);
    }

    /**
     * @throws ConnectionException
     */
    private function sendOpenCartDeleteRequest(Shop $shop, Product $product, int $external_product_id): Response
    {
        $options      = is_array($shop->options) ? $shop->options : [];
        $timeout      = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $delete_url   = $this->resolveDeleteUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $payload = [
            'shop_id'             => (int) $shop->id,
            'product_id'          => (int) $product->id,
            'external_product_id' => $external_product_id,
            'operation'           => 'delete',
        ];

        $response = $this->sendOpenCartRequestWithAuthApiToken($delete_url, $auth_api_token, $payload, $timeout);
        if (! $this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($delete_url, $refreshed_auth_api_token, $payload, $timeout);
    }

    private function resolveDeleteUrl(Shop $shop, string $api_base_url): string
    {
        $delete_path = Str::trim((string) ($shop->part_api_url_delete_prods ?? ''));
        if ($delete_path === '') {
            throw new RuntimeException('Shop delete API path is missing');
        }

        if (Str::startsWith($delete_path, ['http://', 'https://'])) {
            return $delete_path;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($delete_path, '/');
    }

    private function resolveBackupUrl(Shop $shop, string $api_base_url): string
    {
        $options      = is_array($shop->options) ? $shop->options : [];
        $backup_path = Str::trim((string) Arr::get($options, 'part_api_url_backup_prods', ''));
        if ($backup_path === '') {
            throw new RuntimeException('Shop backup API path is missing');
        }

        if (Str::startsWith($backup_path, ['http://', 'https://'])) {
            return $backup_path;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($backup_path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartRequestWithAuthApiToken(
        string $request_url,
        string $auth_api_token,
        array $payload,
        int $timeout
    ): Response {
        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        return $request->post($request_url, [
            ...$payload,
            'api_token' => $auth_api_token,
        ]);
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        if ($response->status() === SymfonyResponse::HTTP_UNAUTHORIZED) {
            return true;
        }

        if ($response->status() === SymfonyResponse::HTTP_FORBIDDEN) {
            return true;
        }

        $response_body = Str::lower(Str::trim($response->body()));
        if ($response_body === '') {
            return false;
        }

        return Str::contains($response_body, 'invalid token')
            || Str::contains($response_body, 'api token')
            || Str::contains($response_body, 'token is invalid')
            || Str::contains($response_body, 'permission denied');
    }

    /**
     * @throws ConnectionException
     */
    private function requestOpenCartAuthApiToken(Shop $shop, string $api_base_url, int $timeout): string
    {
        $login_url = $this->resolveLoginUrl($shop, $api_base_url);
        $api_token = Str::trim((string) ($shop->api_token ?? ''));

        if ($api_token === '') {
            throw new RuntimeException('Shop API token is missing');
        }

        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $response = $request->post($login_url, [
            'api_token' => $api_token,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenCart auth API failed with status '.$response->status().': '.$response->body());
        }

        $auth_api_token = Str::trim((string) Arr::get($response->json(), 'api_token', ''));
        if ($auth_api_token === '') {
            throw new RuntimeException('OpenCart auth API token is empty');
        }

        return $auth_api_token;
    }

    private function resolveLoginUrl(Shop $shop, string $api_base_url): string
    {
        $login_path = Str::trim((string) ($shop->part_api_url_login ?? ''));
        if ($login_path === '') {
            throw new RuntimeException('Shop login API path is missing');
        }

        if (Str::startsWith($login_path, ['http://', 'https://'])) {
            return $login_path;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($login_path, '/');
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        $api_url = Str::trim((string) ($shop->api_url ?? ''));
        if ($api_url !== '') {
            return Str::rtrim($api_url, '/');
        }

        $base_url = Str::trim((string) ($shop->base_url ?? ''));
        if ($base_url !== '') {
            return Str::rtrim($base_url, '/');
        }

        throw new RuntimeException('Shop API URL is missing');
    }

    private function resolveBaseUrlForDefaultApi(Shop $shop): string
    {
        $api_url = Str::trim((string) ($shop->api_url ?? ''));
        if ($api_url !== '') {
            return Str::rtrim($api_url, '/');
        }

        $base_url = Str::trim((string) ($shop->base_url ?? ''));
        if ($base_url !== '') {
            return Str::rtrim($base_url, '/');
        }

        throw new RuntimeException('Shop base/api URL is missing');
    }

    private function resolveStoredAuthApiToken(Shop $shop): string
    {
        $options = is_array($shop->options) ? $shop->options : [];

        return Str::trim((string) Arr::get($options, 'auth_api_token', ''));
    }

    private function persistOpenCartAuthApiToken(Shop $shop, string $auth_api_token): void
    {
        $options = is_array($shop->options) ? $shop->options : [];
        $shop->update([
            'options' => [
                ...$options,
                'auth_api_token' => $auth_api_token,
            ],
        ]);
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            return $request;
        }

        return $request->withHeaders([
            'Cookie' => 'XDEBUG_SESSION=PHPSTORM',
        ]);
    }

    private function resolveProductIdFromBackupPayload(array $backup_payload): int
    {
        $candidate_paths = [
            'product_id',
            'id',
            'data.product_id',
            'data.id',
            'product.id',
            'product.product_id',
            'result.id',
            'result.product_id',
        ];

        foreach ($candidate_paths as $candidate_path) {
            $candidate_id = Arr::get($backup_payload, $candidate_path);
            if (is_numeric($candidate_id) && (int) $candidate_id > 0) {
                return (int) $candidate_id;
            }
        }

        return 0;
    }

    private function isOpenCartShop(Shop $shop): bool
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
}

