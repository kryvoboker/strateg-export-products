<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\Traits\InteractsWithShopApi;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
use App\Services\Products\Payload\ProductPayloadBuilderService;
use App\Supports\Services\Products\ProductBackupRestoreService;
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

class ProcessCatalogProductRestoreItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithShopApi;

    /**
     * Restore flow is intentionally full-payload only.
     * For partial updates use ProcessProductUpdateItemJob.
     */
    private const string RESTORE_PAYLOAD_MODE = 'full';

    public function __construct(public int $product_update_item_id) {}

    public function handle(ProductBackupRestoreService $backup_restore_service): void
    {
        $product_update_item = ProductUpdateItem::query()->find($this->product_update_item_id);
        if (! $product_update_item instanceof ProductUpdateItem) {
            return;
        }

        $payload    = is_array($product_update_item->payload) ? $product_update_item->payload : [];
        $shop_id    = (int) Arr::get($payload, 'shop_id', 0);
        $product_id = (int) ($product_update_item->product_id ?? 0);

        $product_export_item = $this->resolveOrCreateProductExportItem($product_update_item, $product_id, $shop_id, $payload);

        $product_update_item->update([
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        if ($product_export_item instanceof ProductExportItem) {
            $product_export_item->update([
                'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);
        }

        try {
            if ($shop_id <= 0 || $product_id <= 0) {
                throw new RuntimeException('Missing shop_id or product_id for restore');
            }

            $shop = Shop::query()->find($shop_id);
            if (! $shop instanceof Shop) {
                throw new RuntimeException('Shop not found for restore');
            }

            $product = Product::query()->find($product_id);
            if (! $product instanceof Product) {
                throw new RuntimeException('Product not found for restore');
            }

            $product_shop = ProductShop::query()
                ->where('product_id', $product_id)
                ->where('shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();

            if (! $product_shop instanceof ProductShop) {
                throw new RuntimeException('Product is not bound to selected shop');
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                throw new RuntimeException('External product id is missing for restore');
            }

            $backup = $backup_restore_service->resolveLatestValidExternalSnapshotForProductShop(
                $product_id,
                $shop_id,
                $external_product_id
            );

            if ($backup === null) {
                throw new RuntimeException('Valid unused external backup for product/shop not found');
            }

            $backup_payload = is_array($backup->payload) ? $backup->payload : [];

            $product_payload = $this->buildRestoreProductPayload(
                $product,
                $shop_id,
                $external_product_id
            );

            $request_payload = [
                ...$product_payload,
                'payload_mode'        => self::RESTORE_PAYLOAD_MODE,
                'operation'           => 'restore',
                'shop_id'             => $shop_id,
                'product_id'          => $product_id,
                'external_product_id' => $external_product_id,
                'backup_id'           => (int) $backup->id,
                'backup_source'       => (string) ($backup->backup_source ?? ''),
                'backup_kind'         => (string) ($backup->backup_kind ?? ''),
                'backup_payload'      => $backup_payload,
            ];

            $response = $this->sendRestoreRequest($shop, $request_payload);
            if (! $response->successful()) {
                throw new RuntimeException($this->buildFailedApiResponseMessage('Restore API', $response));
            }

            $backup->markAsUsed();

            $product_update_item->update([
                'status'        => ProductUpdateItemsStatusEnum::SUCCESSED->value,
                'error_message' => null,
                'processed_at'  => now(),
                'payload'       => [
                    ...$payload,
                    'operation'       => 'restore',
                    'request_payload' => $request_payload,
                    'response_status' => $response->status(),
                    'response_body'   => $this->truncateResponseBody($response->body()),
                    'backup_id'       => (int) $backup->id,
                ],
            ]);

            if ($product_export_item instanceof ProductExportItem) {
                $product_export_item->update([
                    'status'        => ProductExportItemsStatusEnum::EXPORTED->value,
                    'error_message' => null,
                    'processed_at'  => now(),
                    'payload'       => [
                        ...(is_array($product_export_item->payload) ? $product_export_item->payload : []),
                        ...$payload,
                        'operation'       => 'restore',
                        'request_payload' => $request_payload,
                        'response_status' => $response->status(),
                        'response_body'   => $this->truncateResponseBody($response->body()),
                        'backup_id'       => (int) $backup->id,
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to restore product with id '.$product_id.' for shop with id '.$shop_id, [
                'error_msg'  => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
            ]);

            $product_update_item->update([
                'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
                'error_message' => Str::limit(Str::trim($exception->getMessage()), 10000),
                'processed_at'  => now(),
            ]);

            if ($product_export_item instanceof ProductExportItem) {
                $product_export_item->update([
                    'status'        => ProductExportItemsStatusEnum::FAILED->value,
                    'error_message' => Str::limit(Str::trim($exception->getMessage()), 10000),
                    'processed_at'  => now(),
                    'payload'       => [
                        ...(is_array($product_export_item->payload) ? $product_export_item->payload : []),
                        ...$payload,
                        'operation' => 'restore',
                    ],
                ]);
            }
        } finally {
            $this->syncBatchStatusByRestoreItems((int) $product_update_item->product_update_batch_id);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrCreateProductExportItem(
        ProductUpdateItem $product_update_item,
        int $product_id,
        int $shop_id,
        array $payload
    ): ?ProductExportItem {
        $batch_id = (int) ($product_update_item->product_update_batch_id ?? 0);
        if ($batch_id <= 0 || $product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $existing_product_export_item = ProductExportItem::query()
            ->forBatchable(ProductUpdateBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('payload->shop_id', $shop_id)
            ->where('payload->operation', 'restore')
            ->orderByDesc('id')
            ->first();

        if ($existing_product_export_item instanceof ProductExportItem) {
            return $existing_product_export_item;
        }

        return ProductExportItem::query()->create([
            'batchable_type' => ProductUpdateBatch::class,
            'batchable_id'   => $batch_id,
            'product_id'     => $product_id,
            'payload'        => [
                ...$payload,
                'operation' => 'restore',
            ],
            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);
    }

    private function buildRestoreProductPayload(
        Product $product,
        int $shop_id,
        int $external_product_id
    ): array {
        $payload = app(ProductPayloadBuilderService::class)->build($product, $shop_id, [
            'include_product_binding_fields' => true,
        ]);
        $payload['product']['external_product_id'] = $external_product_id > 0 ? $external_product_id : null;

        Log::channel('daily')->debug('Restore payload entity resolution summary', [
            'product_id'               => (int) $product->id,
            'shop_id'                  => $shop_id,
            'payload_mode'             => self::RESTORE_PAYLOAD_MODE,
            'categories_total'         => count(Arr::get($payload, 'categories', [])),
            'attributes_total'         => count(Arr::get($payload, 'attributes', [])),
            'specials_total'           => count(Arr::get($payload, 'specials', [])),
            'discounts_total'          => count(Arr::get($payload, 'discounts', [])),
            'external_product_id'      => Arr::get($payload, 'product.external_product_id'),
            'external_manufacturer_id' => Arr::get($payload, 'manufacturer_brand.external_manufacturer_id'),
            'external_brand_id'        => Arr::get($payload, 'manufacturer_brand.external_brand_id'),
        ]);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendRestoreRequest(Shop $shop, array $request_payload): Response
    {
        if ($this->isOpenCartShop($shop)) {
            return $this->sendOpenCartRestoreRequest($shop, $request_payload);
        }

        return $this->sendDefaultRestoreRequest($shop, $request_payload);
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendDefaultRestoreRequest(Shop $shop, array $request_payload): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $endpoint = Str::trim((string) Arr::get($options, 'product_restore_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = Str::trim((string) Arr::get($options, 'part_api_url_restore_prods', ''));
        }

        if ($endpoint === '') {
            $endpoint = Str::trim((string) ($shop->part_api_url_restore_prods ?? ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('Missing product restore endpoint');
        }

        $base_url = $this->resolveBaseUrlForDefaultApi($shop);
        $timeout  = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $url      = $this->resolveAbsoluteEndpointUrl($base_url, $endpoint, 'product restore');

        $request = Http::timeout($timeout)
            ->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string) Arr::get($options, 'api_token', ''));
        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        return $request->post($url, $request_payload);
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartRestoreRequest(Shop $shop, array $request_payload): Response
    {
        $options      = is_array($shop->options) ? $shop->options : [];
        $timeout      = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $restore_url  = $this->resolveRestoreUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $response = $this->sendOpenCartRequestWithAuthApiToken($restore_url, $auth_api_token, $request_payload, $timeout);
        if (! $this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($restore_url, $refreshed_auth_api_token, $request_payload, $timeout);
    }

    private function syncBatchStatusByRestoreItems(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductUpdateBatch::query()->find($batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $status_rows = ProductUpdateItem::query()
            ->selectRaw('status, COUNT(*) AS status_total')
            ->where('product_update_batch_id', $batch_id)
            ->where('payload->operation', 'restore')
            ->groupBy('status')
            ->get();

        $total_items = (int) $status_rows->sum('status_total');

        if ($total_items <= 0) {
            return;
        }

        $processing_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::PROCESSING->value)->status_total ?? 0);
        $failed_count     = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::FAILED->value)->status_total ?? 0);
        $success_count    = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::SUCCESSED->value)->status_total ?? 0);

        $final_status = match (true) {
            $processing_count > 0                     => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            $failed_count > 0 && $success_count > 0   => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $success_count === 0 => ProductUpdateBatchesStatusEnum::FAILED->value,
            default                                   => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status'          => $final_status,
            'total_items'     => $total_items,
            'processed_items' => max($success_count + $failed_count, 0),
            'failed_items'    => max($failed_count, 0),
            'finished_at'     => $processing_count > 0 ? null : now(),
            'options'         => [
                ...($batch->options ?? []),
                'restore_state'         => $processing_count > 0 ? 'processing' : 'finished',
                'restore_total_items'   => $total_items,
                'restore_success_items' => $success_count,
                'restore_failed_items'  => $failed_count,
                'restore_finished_at'   => $processing_count > 0 ? null : now()->toDateTimeString(),
            ],
        ]);
    }

    private function isOpenCartShop(Shop $shop): bool
    {
        return $this->shopApiIsOpenCartShop($shop);
    }

    private function resolveBaseUrlForDefaultApi(Shop $shop): string
    {
        return $this->shopApiResolveBaseUrlForDefaultApi($shop);
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        return $this->shopApiResolveApiBaseUrl($shop);
    }

    private function resolveRestoreUrl(Shop $shop, string $api_base_url): string
    {
        $options                    = is_array($shop->options) ? $shop->options : [];
        $part_api_url_restore_prods = Str::trim((string) Arr::get($options, 'part_api_url_restore_prods', ''));

        if ($part_api_url_restore_prods === '') {
            $part_api_url_restore_prods = Str::trim((string) ($shop->part_api_url_restore_prods ?? ''));
        }

        if ($part_api_url_restore_prods === '') {
            throw new RuntimeException('Missing part_api_url_restore_prods for restore API');
        }

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $part_api_url_restore_prods, 'opencart product restore');
    }

    private function resolveOpenCartLoginUrl(Shop $shop, string $api_base_url): string
    {
        return $this->shopApiResolveOpenCartLoginUrl($shop, $api_base_url);
    }

    private function resolveStoredAuthApiToken(Shop $shop): string
    {
        return $this->shopApiResolveStoredAuthApiToken($shop);
    }

    private function persistOpenCartAuthApiToken(Shop $shop, string $auth_api_token): void
    {
        $this->shopApiPersistOpenCartAuthApiToken($shop, $auth_api_token);
    }

    /**
     * @throws ConnectionException
     */
    private function requestOpenCartAuthApiToken(Shop $shop, string $api_base_url, int $timeout): string
    {
        return $this->shopApiRequestOpenCartAuthApiTokenWithKey($shop, $api_base_url, $timeout);
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartRequestWithAuthApiToken(
        string $request_url,
        string $auth_api_token,
        array $request_payload,
        int $timeout
    ): Response {
        return $this->shopApiSendOpenCartRequestWithQueryAuthToken(
            $request_url,
            $auth_api_token,
            $request_payload,
            $timeout
        );
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        return $this->shopApiApplyDebugCookieForDevelopment($request);
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        return $this->shopApiIsInvalidOpenCartAuthTokenResponse($response);
    }

    private function truncateResponseBody(string $response_body): string
    {
        return $this->shopApiTruncateResponseBody($response_body);
    }

    private function resolveAbsoluteEndpointUrl(string $base_url, string $endpoint, string $operation): string
    {
        return $this->shopApiResolveAbsoluteEndpointUrl($base_url, $endpoint, $operation);
    }

    private function buildFailedApiResponseMessage(string $operation, Response $response): string
    {
        return $this->shopApiBuildFailedResponseMessage($operation, $response);
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        return $this->shopApiNormalizeDateTimeValue($value);
    }
}
