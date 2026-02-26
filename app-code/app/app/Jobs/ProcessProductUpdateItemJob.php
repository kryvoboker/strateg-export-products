<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\Traits\InteractsWithShopApi;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
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

class ProcessProductUpdateItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithShopApi;

    public function __construct(public int $product_update_item_id) {}

    public function handle(): void
    {
        $product_update_item = ProductUpdateItem::query()->find($this->product_update_item_id);
        if (! $product_update_item instanceof ProductUpdateItem) {
            return;
        }

        $payload             = is_array($product_update_item->payload) ? $product_update_item->payload : [];
        $shop_id             = (int) Arr::get($payload, 'shop_id', 0);
        $product_id          = (int) ($product_update_item->product_id ?? 0);
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
                throw new RuntimeException('Missing shop_id or product_id for update');
            }

            $shop = Shop::query()->find($shop_id);
            if (! $shop instanceof Shop) {
                throw new RuntimeException('Shop not found for update');
            }

            $product = Product::query()->find($product_id);
            if (! $product instanceof Product) {
                throw new RuntimeException('Product not found for update');
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
                throw new RuntimeException('External product id is missing for update');
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
                throw new RuntimeException('Backup payload does not contain valid product id');
            }

            $created_backup = ProductBackups::createUsingBackupForProduct($product_id, [
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id,
                'backup_product_id'   => $backup_product_id,
                'backup_payload'      => $backup_payload,
                'received_at'         => now()->toDateTimeString(),
            ], $shop_id, (string) $external_product_id);

            $update_instructions = Arr::get($payload, 'update_instructions', []);
            $request_payload     = $this->buildRequestPayload(
                $product,
                $shop_id,
                is_array($update_instructions) ? $update_instructions : []
            );
            Log::channel('daily')->info('Prepared update payload with shop-scoped catalog entities', [
                'product_id'        => $product_id,
                'shop_id'           => $shop_id,
                'category_ids'      => collect(Arr::get($request_payload, 'categories', []))->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
                'attribute_ids'     => collect(Arr::get($request_payload, 'attributes', []))->pluck('attribute_id')->map(static fn ($id): int => (int) $id)->values()->all(),
                'manufacturer_id'   => (int) (Arr::get($request_payload, 'product.manufacturer_id') ?? 0),
                'brand_id'          => (int) (Arr::get($request_payload, 'product.brand_id') ?? 0),
                'shop_language_ids' => collect(Arr::get($request_payload, 'shop_languages', []))->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
            ]);
            $response = $this->sendUpdateRequest($shop, $request_payload, $external_product_id);

            if (! $response->successful()) {
                throw new RuntimeException($this->buildFailedApiResponseMessage('Update API', $response));
            }

            $product_update_item->update([
                'status'        => ProductUpdateItemsStatusEnum::SUCCESSED->value,
                'error_message' => null,
                'processed_at'  => now(),
                'payload'       => [
                    ...$payload,
                    'operation'         => 'update',
                    'request_payload'   => $request_payload,
                    'response_status'   => $response->status(),
                    'response_body'     => $this->truncateResponseBody($response->body()),
                    'backup_id'         => (int) $created_backup->id,
                    'backup_product_id' => $backup_product_id,
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
                        'operation'         => 'update',
                        'request_payload'   => $request_payload,
                        'response_status'   => $response->status(),
                        'response_body'     => $this->truncateResponseBody($response->body()),
                        'backup_id'         => (int) $created_backup->id,
                        'backup_product_id' => $backup_product_id,
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to update product with id '.$product_id.' for shop with id '.$shop_id, [
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
                        'operation' => 'update',
                    ],
                ]);
            }
        } finally {
            $this->syncBatchStatusByUpdateItems((int) $product_update_item->product_update_batch_id);
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
            ->where('payload->operation', 'update')
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
                'operation' => 'update',
            ],
            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestPayload(Product $product, int $shop_id, array $update_instructions = []): array
    {
        $payload = app(ProductPayloadBuilderService::class)->build($product, $shop_id, [
            'include_product_binding_fields' => true,
            'update_directives'              => $this->buildApiUpdateDirectives($update_instructions),
        ]);

        Log::channel('daily')->debug('Update payload entity resolution summary', [
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
     * @param  array<string, mixed>  $update_instructions
     * @return array<string, array<string, array{action:string,value:mixed}>>
     */
    private function buildApiUpdateDirectives(array $update_instructions): array
    {
        $result = [];

        foreach ($update_instructions as $sheet_name => $sheet_data) {
            if (! is_array($sheet_data)) {
                continue;
            }

            $sheet_fields = Arr::get($sheet_data, 'fields', []);
            if (! is_array($sheet_fields) || $sheet_fields === []) {
                continue;
            }

            $normalized_fields = [];
            foreach ($sheet_fields as $field_key => $field_instruction) {
                if (! is_array($field_instruction)) {
                    continue;
                }

                $action = Str::trim((string) Arr::get($field_instruction, 'action', ''));
                if (! in_array($action, ['set', 'delete', 'no_change', 'skip'], true)) {
                    continue;
                }

                if ($action === 'skip') {
                    continue;
                }

                $normalized_fields[(string) $field_key] = [
                    'action' => $action,
                    'value'  => Arr::get($field_instruction, 'value'),
                ];
            }

            if ($normalized_fields === []) {
                continue;
            }

            $result[(string) $sheet_name] = $normalized_fields;
        }

        return $result;
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
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendUpdateRequest(Shop $shop, array $request_payload, int $external_product_id): Response
    {
        if ($this->isOpenCartShop($shop)) {
            return $this->sendOpenCartUpdateRequest($shop, $request_payload, $external_product_id);
        }

        return $this->sendDefaultUpdateRequest($shop, $request_payload, $external_product_id);
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
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendDefaultUpdateRequest(Shop $shop, array $request_payload, int $external_product_id): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $endpoint = Str::trim((string) Arr::get($options, 'product_update_endpoint', ''));
        if ($endpoint === '') {
            $endpoint = Str::trim((string) Arr::get($options, 'part_api_url_update_prods', ''));
        }

        if ($endpoint === '') {
            $endpoint = Str::trim((string) ($shop->part_api_url_export_prods ?? ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('Missing product update endpoint');
        }

        $base_url = $this->resolveBaseUrlForDefaultApi($shop);
        $timeout  = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $url = $this->resolveAbsoluteEndpointUrl($base_url, $endpoint, 'product update');

        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string) Arr::get($options, 'api_token', ''));
        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        return $request->post($url, [
            ...$request_payload,
            'operation'           => 'update',
            'external_product_id' => $external_product_id,
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
     * @param  array<string, mixed>  $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartUpdateRequest(Shop $shop, array $request_payload, int $external_product_id): Response
    {
        $options      = is_array($shop->options) ? $shop->options : [];
        $timeout      = max((int) Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $update_url   = $this->resolveUpdateUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $response = $this->sendOpenCartRequestWithAuthApiToken($update_url, $auth_api_token, [
            ...$request_payload,
            'operation'           => 'update',
            'external_product_id' => $external_product_id,
        ], $timeout);

        if (! $this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($update_url, $refreshed_auth_api_token, [
            ...$request_payload,
            'operation'           => 'update',
            'external_product_id' => $external_product_id,
        ], $timeout);
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

    private function resolveBaseUrlForDefaultApi(Shop $shop): string
    {
        return $this->shopApiResolveBaseUrlForDefaultApi($shop);
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        return $this->shopApiResolveApiBaseUrl($shop);
    }

    private function resolveUpdateUrl(Shop $shop, string $api_base_url): string
    {
        $options                   = is_array($shop->options) ? $shop->options : [];
        $part_api_url_update_prods = Str::trim((string) Arr::get($options, 'part_api_url_update_prods', ''));
        if ($part_api_url_update_prods === '') {
            $part_api_url_update_prods = Str::trim((string) ($shop->part_api_url_export_prods ?? ''));
        }

        if ($part_api_url_update_prods === '') {
            throw new RuntimeException('Missing part_api_url_update_prods/part_api_url_export_prods for update API');
        }

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $part_api_url_update_prods, 'opencart product update');
    }

    private function resolveBackupUrl(Shop $shop, string $api_base_url): string
    {
        $options                   = is_array($shop->options) ? $shop->options : [];
        $part_api_url_backup_prods = Str::trim((string) Arr::get($options, 'part_api_url_backup_prods', ''));

        if ($part_api_url_backup_prods === '') {
            throw new RuntimeException('Missing part_api_url_backup_prods for backup API');
        }

        return $this->resolveAbsoluteEndpointUrl($api_base_url, $part_api_url_backup_prods, 'opencart product backup');
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
        string $url,
        string $auth_api_token,
        array $request_payload,
        int $timeout,
    ): Response {
        return $this->shopApiSendOpenCartRequestWithQueryAuthToken(
            $url,
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

    private function syncBatchStatusByUpdateItems(int $batch_id): void
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
            ->whereRaw("(payload->>'operation') = 'update'")
            ->groupBy('status')
            ->get();

        $total_update_items = (int) $status_rows->sum('status_total');

        if ($total_update_items <= 0) {
            return;
        }

        $processing_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::PROCESSING->value)->status_total ?? 0);
        $failed_count     = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::FAILED->value)->status_total ?? 0);
        $updated_count    = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::SUCCESSED->value)->status_total ?? 0);

        if ($processing_count > 0) {
            $batch->update([
                'status'          => ProductUpdateBatchesStatusEnum::PROCESSING->value,
                'total_items'     => $total_update_items,
                'processed_items' => max($updated_count + $failed_count, 0),
                'failed_items'    => max($failed_count, 0),
                'finished_at'     => null,
                'options'         => [
                    ...($batch->options ?? []),
                    'update_state'         => 'processing',
                    'update_total_items'   => $total_update_items,
                    'update_success_items' => $updated_count,
                    'update_failed_items'  => $failed_count,
                    'update_finished_at'   => null,
                ],
            ]);

            return;
        }

        $final_status = match (true) {
            $failed_count > 0 && $updated_count > 0   => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $updated_count === 0 => ProductUpdateBatchesStatusEnum::FAILED->value,
            default                                   => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status'          => $final_status,
            'total_items'     => $total_update_items,
            'processed_items' => max($updated_count + $failed_count, 0),
            'failed_items'    => max($failed_count, 0),
            'finished_at'     => now(),
            'options'         => [
                ...($batch->options ?? []),
                'update_state'         => 'finished',
                'update_total_items'   => $total_update_items,
                'update_success_items' => $updated_count,
                'update_failed_items'  => $failed_count,
                'update_finished_at'   => now()->toDateTimeString(),
            ],
        ]);
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        return $this->shopApiNormalizeDateTimeValue($value);
    }
}
