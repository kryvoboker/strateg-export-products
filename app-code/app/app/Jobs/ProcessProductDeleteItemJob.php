<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Jobs\Traits\InteractsWithShopApi;
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
use Throwable;

class ProcessProductDeleteItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithShopApi;

    public function __construct(public int $product_delete_item_id) {}

    public function handle(ProductDeleteQueueService $product_delete_queue_service): void
    {
        $product_delete_item = ProductDeleteItem::query()->find($this->product_delete_item_id);
        if (! $product_delete_item instanceof ProductDeleteItem) {
            return;
        }

        $payload             = is_array($product_delete_item->payload) ? $product_delete_item->payload : [];
        $shop_id             = (int) Arr::get($payload, 'shop_id', 0);
        $product_id          = (int) ($product_delete_item->product_id ?? 0);
        $external_product_id = (int) Arr::get($payload, 'external_product_id', 0);

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
                throw new RuntimeException($this->buildFailedApiResponseMessage('Backup API', $backup_response));
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
                throw new RuntimeException($this->buildFailedApiResponseMessage('Delete API', $delete_response));
            }

            $product_delete_item->update([
                'status'        => ProductDeleteItemsStatusEnum::DELETED->value,
                'error_message' => null,
                'processed_at'  => now(),
                'payload'       => [
                    ...$payload,
                    'operation'           => 'delete',
                    'shop_id'             => $shop_id,
                    'external_product_id' => $external_product_id,
                    'backup_id'           => (int) $created_backup->id,
                    'backup_product_id'   => $backup_product_id,
                    'response_status'     => $delete_response->status(),
                    'response_body'       => $this->truncateResponseBody($delete_response->body()),
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
        $url = $this->resolveAbsoluteEndpointUrl($base_url, $endpoint, 'product backup');

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
        $url = $this->resolveAbsoluteEndpointUrl($base_url, $endpoint, 'product delete');

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

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $delete_path, 'opencart product delete');
    }

    private function resolveBackupUrl(Shop $shop, string $api_base_url): string
    {
        $options     = is_array($shop->options) ? $shop->options : [];
        $backup_path = Str::trim((string) Arr::get($options, 'part_api_url_backup_prods', ''));
        if ($backup_path === '') {
            throw new RuntimeException('Shop backup API path is missing');
        }

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $backup_path, 'opencart product backup');
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
        return $this->shopApiSendOpenCartRequestWithBodyAuthToken(
            $request_url,
            $auth_api_token,
            $payload,
            $timeout
        );
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        return $this->shopApiIsInvalidOpenCartAuthTokenResponse($response);
    }

    /**
     * @throws ConnectionException
     */
    private function requestOpenCartAuthApiToken(Shop $shop, string $api_base_url, int $timeout): string
    {
        return $this->shopApiRequestOpenCartAuthApiTokenWithApiToken($shop, $api_base_url, $timeout);
    }

    private function resolveLoginUrl(Shop $shop, string $api_base_url): string
    {
        return $this->shopApiResolveOpenCartLoginUrl($shop, $api_base_url);
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        return $this->shopApiResolveApiBaseUrl($shop);
    }

    private function resolveBaseUrlForDefaultApi(Shop $shop): string
    {
        return $this->shopApiResolveBaseUrlForDefaultApi($shop);
    }

    private function resolveStoredAuthApiToken(Shop $shop): string
    {
        return $this->shopApiResolveStoredAuthApiToken($shop);
    }

    private function persistOpenCartAuthApiToken(Shop $shop, string $auth_api_token): void
    {
        $this->shopApiPersistOpenCartAuthApiToken($shop, $auth_api_token);
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        return $this->shopApiApplyDebugCookieForDevelopment($request);
    }

    protected function shopApiShouldApplyDebugCookieForDevelopment(): bool
    {
        return app()->environment(['local', 'development', 'testing']);
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
        return $this->shopApiIsOpenCartShop($shop);
    }

    private function resolveAbsoluteEndpointUrl(string $base_url, string $endpoint, string $operation): string
    {
        return $this->shopApiResolveAbsoluteEndpointUrl($base_url, $endpoint, $operation);
    }

    private function buildFailedApiResponseMessage(string $operation, Response $response): string
    {
        return $this->shopApiBuildFailedResponseMessage($operation, $response);
    }

    private function truncateResponseBody(string $response_body): string
    {
        return $this->shopApiTruncateResponseBody($response_body);
    }
}
