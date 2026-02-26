<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Jobs\Traits\InteractsWithShopApi;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use App\Services\Products\Payload\ProductPayloadBuilderService;
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

class ProcessProductExportItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithShopApi;

    public function __construct(public int $product_export_item_id) {}

    public function handle(): void
    {
        $product_export_item = ProductExportItem::query()->find($this->product_export_item_id);
        if ($product_export_item === null) {
            return;
        }

        $payload    = is_array($product_export_item->payload) ? $product_export_item->payload : [];
        $shop_id    = (int) Arr::get($payload, 'shop_id', 0);
        $product_id = (int) ($product_export_item->product_id ?? 0);

        $product_export_item->update([
            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        try {
            if ($shop_id <= 0 || $product_id <= 0) {
                throw new RuntimeException('Missing shop_id or product_id for export');
            }

            $shop = Shop::query()->find($shop_id);
            if ($shop === null) {
                throw new RuntimeException('Shop not found for export');
            }

            $product = Product::query()->find($product_id);
            if ($product === null) {
                throw new RuntimeException('Product not found for export');
            }

            $is_bound_to_shop = ProductShop::isProductBoundToShop($product_id, $shop_id);

            if (! $is_bound_to_shop) {
                throw new RuntimeException('Product is not bound to selected shop');
            }

            $request_payload = $this->buildRequestPayload($product, $shop_id);
            Log::channel('daily')->info('Prepared export payload with shop-scoped catalog entities', [
                'product_id'        => $product_id,
                'shop_id'           => $shop_id,
                'category_ids'      => collect(Arr::get($request_payload, 'categories', []))->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                'attribute_ids'     => collect(Arr::get($request_payload, 'attributes', []))->pluck('attribute_id')->map(static fn ($id): int => (int) $id)->values()->all(),
                'manufacturer_id'   => (int) (Arr::get($request_payload, 'manufacturer_brand.manufacturer_id') ?? 0),
                'brand_id'          => (int) (Arr::get($request_payload, 'manufacturer_brand.brand_id') ?? 0),
                'shop_language_ids' => collect(Arr::get($request_payload, 'shop_languages', []))->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
            ]);
            $response = $this->sendExportRequest($shop, $request_payload);

            if (! $response->successful()) {
                throw new RuntimeException($this->buildFailedApiResponseMessage('Export API', $response));
            }

            $response_data       = $response->json();
            $external_product_id = $this->resolveExternalProductIdFromResponse($response_data);

            if ($external_product_id <= 0) {
                throw new RuntimeException('Export API response does not contain external product id');
            }

            ProductShop::updateExternalProductId($product_id, $shop_id, $external_product_id);

            $product_export_item->update([
                'status'        => ProductExportItemsStatusEnum::EXPORTED->value,
                'error_message' => null,
                'processed_at'  => now(),
                'payload'       => [
                    ...$payload,
                    'request_payload'     => $request_payload,
                    'response_status'     => $response->status(),
                    'response_body'       => $this->truncateResponseBody($response->body()),
                    'external_product_id' => $external_product_id,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to export product with id '.$product_id.' for shop with id '.$shop_id, [
                'error_msg'  => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
            ]);

            $product_export_item->update([
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => Str::limit(Str::trim($exception->getMessage()), 10000),
                'processed_at'  => now(),
            ]);
        } finally {
            $this->syncBatchStatusByExportItems($product_export_item);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestPayload(Product $product, int $shop_id): array
    {
        $payload = app(ProductPayloadBuilderService::class)->build($product, $shop_id, [
            'include_product_binding_fields' => false,
        ]);

        Log::channel('daily')->debug('Export payload entity resolution summary', [
            'product_id'               => (int) $product->id,
            'shop_id'                  => $shop_id,
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

    private function isEntityInShopScope(int $entity_shop_id, int $shop_id): bool
    {
        if ($shop_id <= 0) {
            return true;
        }

        return $entity_shop_id === $shop_id;
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendExportRequest(Shop $shop, array $request_payload): Response
    {
        if ($this->isOpenCartShop($shop)) {
            return $this->sendOpenCartExportRequest($shop, $request_payload);
        }

        return $this->sendDefaultExportRequest($shop, $request_payload);
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendDefaultExportRequest(Shop $shop, array $request_payload): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $endpoint = Str::trim((string) Arr::get($options, 'product_export_endpoint'));
        if ($endpoint === '') {
            $endpoint = Str::trim((string) ($shop->part_api_url_export_prods ?? ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('Missing product export endpoint!');
        }

        $api_url  = Str::trim((string) ($shop->api_url ?? ''));
        $base_url = $api_url !== ''
            ? $api_url
            : Str::trim((string) $shop->base_url);

        $timeout = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $url = $this->resolveAbsoluteEndpointUrl($base_url, $endpoint, 'product export');

        $request = Http::timeout($timeout)
            ->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string) Arr::get($options, 'api_token', ''));

        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        $header_name  = Str::trim((string) Arr::get($options, 'api_header_name', ''));
        $header_value = Str::trim((string) Arr::get($options, 'api_header_value', ''));

        if ($header_name !== '' && $header_value !== '') {
            $request = $request->withHeaders([
                $header_name => $header_value,
            ]);
        }

        return $request->post($url, $request_payload);
    }

    /**
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartExportRequest(Shop $shop, array $request_payload): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $timeout      = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $export_url   = $this->resolveExportUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $response = $this->sendOpenCartRequestWithAuthApiToken($export_url, $auth_api_token, $request_payload, $timeout);
        if (! $this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($export_url, $refreshed_auth_api_token, $request_payload, $timeout);
    }

    private function isOpenCartShop(Shop $shop): bool
    {
        return $this->shopApiIsOpenCartShop($shop);
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        return $this->shopApiResolveApiBaseUrl($shop);
    }

    private function resolveExportUrl(Shop $shop, string $api_base_url): string
    {
        $part_api_url_export_prods = Str::trim((string) ($shop->part_api_url_export_prods ?? ''));
        if ($part_api_url_export_prods === '') {
            throw new RuntimeException('Missing part_api_url_export_prods for export API');
        }

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $part_api_url_export_prods, 'opencart product export');
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
        string $export_url,
        string $auth_api_token,
        array $request_payload,
        int $timeout
    ): Response {
        return $this->shopApiSendOpenCartRequestWithQueryAuthToken(
            $export_url,
            $auth_api_token,
            $request_payload,
            $timeout
        );
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        return $this->shopApiApplyDebugCookieForDevelopment($request);
    }

    protected function shopApiShouldApplyDebugCookieForDevelopment(): bool
    {
        return (bool) config('app.debug', false)
            && (bool) config('app.enable_xdebug_session', false)
            && app()->isLocal();
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        return $this->shopApiIsInvalidOpenCartAuthTokenResponse($response);
    }

    /**
     * @param  array<string, mixed>|null  $response_data
     */
    private function resolveExternalProductIdFromResponse(?array $response_data): int
    {
        if (! is_array($response_data)) {
            return 0;
        }

        $candidate_paths = [
            'external_product_id',
            'external.id',
            'external.product_id',
            'data.external_product_id',
            'data.id',
            'result.external_product_id',
            'result.id',
            'product.id',
            'id',
        ];

        foreach ($candidate_paths as $candidate_path) {
            $candidate_id = Arr::get($response_data, $candidate_path);
            if (is_numeric($candidate_id) && (int) $candidate_id > 0) {
                return (int) $candidate_id;
            }
        }

        return 0;
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

    private function syncBatchStatusByExportItems(ProductExportItem $product_export_item): void
    {
        $batchable_type = Str::trim((string) ($product_export_item->batchable_type ?? ''));
        $batchable_id   = (int) ($product_export_item->batchable_id ?? 0);

        if ($batchable_type !== ProductImportBatch::class || $batchable_id <= 0) {
            return;
        }

        $batch = ProductImportBatch::query()->find($batchable_id);
        if ($batch === null) {
            return;
        }

        $status_counters    = ProductExportItem::getBatchableStatusCounters(ProductImportBatch::class, $batchable_id);
        $total_export_items = $status_counters['total'] ?? 0;
        if ($total_export_items <= 0) {
            return;
        }
        $processing_count = $status_counters['processing'] ?? 0;
        $failed_count     = $status_counters['failed'] ?? 0;
        $exported_count   = $status_counters['exported'] ?? 0;

        if ($processing_count > 0) {
            $batch->update([
                'status'  => ProductImportBatchesStatusEnum::PROCESSING->value,
                'options' => [
                    ...($batch->options ?? []),
                    'export_state'          => 'processing',
                    'export_total_items'    => $total_export_items,
                    'export_exported_items' => $exported_count,
                    'export_failed_items'   => $failed_count,
                    'export_finished_at'    => null,
                ],
            ]);

            return;
        }

        $final_status = match (true) {
            $failed_count > 0 && $exported_count > 0   => ProductImportBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $exported_count === 0 => ProductImportBatchesStatusEnum::FAILED->value,
            default                                    => ProductImportBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status'  => $final_status,
            'options' => [
                ...($batch->options ?? []),
                'export_state'          => 'finished',
                'export_total_items'    => $total_export_items,
                'export_exported_items' => $exported_count,
                'export_failed_items'   => $failed_count,
                'export_finished_at'    => now()->toDateTimeString(),
            ],
        ]);
    }
}
