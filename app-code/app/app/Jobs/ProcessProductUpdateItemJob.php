<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Attributes\AttributeDescription;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
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

class ProcessProductUpdateItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
                throw new RuntimeException('Backup API failed with status '.$backup_response->status().': '.$backup_response->body());
            }

            $backup_payload = $backup_response->json();
            if (! is_array($backup_payload)) {
                throw new RuntimeException('Backup API response is not a JSON object');
            }

            $backup_product_id = $this->resolveProductIdFromBackupPayload($backup_payload);
            if ($backup_product_id <= 0) {
                throw new RuntimeException('Backup payload does not contain valid product id');
            }

            ProductBackups::createUsingBackupForProduct($product_id, [
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
            $response = $this->sendUpdateRequest($shop, $request_payload, $external_product_id);

            if (! $response->successful()) {
                throw new RuntimeException('Update API failed with status '.$response->status().': '.$response->body());
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
                        'backup_product_id' => $backup_product_id,
                    ],
                ]);
            }
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to update product with id '.$product_id.' for shop with id '.$shop_id, [
                'error_msg'  => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'exception'  => $exception,
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
        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'productToManufacturerBrand.manufacturer.descriptions',
            'productToManufacturerBrand.brand.descriptions',
            'specials',
            'discounts',
        ]);

        $shop_languages = ShopLanguage::getActiveByShopId($shop_id);

        $shop_language_ids = $shop_languages
            ->pluck('id')
            ->map(static fn ($shop_language_id): int => (int) $shop_language_id)
            ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
            ->values()
            ->all();

        $shop_language_map_by_id = $shop_languages
            ->mapWithKeys(static fn (ShopLanguage $shop_language): array => [
                (int) $shop_language->id => [
                    'id'   => (int) $shop_language->id,
                    'code' => Str::lower(Str::trim((string) $shop_language->code)),
                    'name' => $shop_language->name,
                ],
            ])
            ->toArray();

        $seo_urls = SeoUrl::getForSeoableAndLanguageIds(Product::class, (int) $product->id, $shop_language_ids)
            ->map(static function (SeoUrl $seo_url) use ($shop_language_map_by_id): array {
                $shop_language_id = $seo_url->shop_language_id !== null ? (int) $seo_url->shop_language_id : null;

                return [
                    'shop_language_id'   => $seo_url->shop_language_id,
                    'shop_language_code' => $shop_language_id !== null
                        ? Arr::get($shop_language_map_by_id, $shop_language_id.'.code')
                        : null,
                    'query_value' => $seo_url->query_value,
                    'keyword'     => $seo_url->keyword,
                    'sort_order'  => $seo_url->sort_order,
                ];
            })
            ->values()
            ->all();

        $attribute_pairs = [];
        foreach ($product->productToAttributes as $product_to_attribute) {
            $attribute_id     = (int) ($product_to_attribute->attribute_id ?? 0);
            $shop_language_id = (int) ($product_to_attribute->shop_language_id ?? 0);
            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $attribute_pairs[] = [
                'attribute_id'     => $attribute_id,
                'shop_language_id' => $shop_language_id,
            ];
        }

        $attribute_description_map = AttributeDescription::getNameMapByAttributeLanguagePairs($attribute_pairs);

        return [
            'shop_id'           => $shop_id,
            'shop_languages'    => array_values($shop_language_map_by_id),
            'update_directives' => $this->buildApiUpdateDirectives($update_instructions),
            'product'           => [
                'id'              => $product->id,
                'model'           => $product->model,
                'sku'             => $product->sku,
                'ean'             => $product->ean,
                'quantity'        => $product->quantity,
                'minimum'         => $product->minimum,
                'image'           => $product->image,
                'price'           => $product->price,
                'manufacturer_id' => $product->productToManufacturerBrand?->manufacturer_id,
                'manufacturer'    => $product->productToManufacturerBrand?->manufacturer?->manufacturer_name,
                'brand_id'        => $product->productToManufacturerBrand?->brand_id,
                'brand'           => $product->productToManufacturerBrand?->brand?->brand_name,
                'is_active'       => (bool) $product->is_active,
                'date_available'  => $product->date_available?->toDateTimeString(),
                'date_added'      => $product->date_added?->toDateTimeString(),
            ],
            'descriptions' => $product->descriptions
                ->filter(static fn ($description): bool => $shop_language_ids === []
                    || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                ->map(static function ($description) use ($shop_language_map_by_id): array {
                    $shop_language_id = (int) $description->shop_language_id;

                    return [
                        'shop_language_id'   => $shop_language_id,
                        'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                        'name'               => $description->name,
                        'description'        => $description->description,
                        'meta_title'         => $description->meta_title,
                        'meta_description'   => $description->meta_description,
                        'meta_keywords'      => $description->meta_keywords,
                    ];
                })->values()->all(),
            'images' => $product->images
                ->map(static fn ($image): array => [
                    'image'      => $image->image,
                    'sort_order' => $image->sort_order,
                ])->values()->all(),
            'categories' => $product->categories
                ->map(static function ($category) use ($shop_language_ids, $shop_language_map_by_id): array {
                    $descriptions = $category->descriptions
                        ->filter(static fn ($description): bool => $shop_language_ids === []
                            || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                        ->map(static function ($description) use ($shop_language_map_by_id): array {
                            $shop_language_id = (int) $description->shop_language_id;

                            return [
                                'shop_language_id'   => $shop_language_id,
                                'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                                'name'               => $description->name,
                                'description'        => $description->description,
                                'h1_title'           => $description->h1_title,
                                'meta_title'         => $description->meta_title,
                                'meta_description'   => $description->meta_description,
                                'meta_keywords'      => $description->meta_keywords,
                            ];
                        })
                        ->values()
                        ->all();

                    return [
                        'id'           => $category->id,
                        'parent_id'    => $category->parent_id,
                        'name'         => Arr::get($descriptions, '0.name'),
                        'descriptions' => $descriptions,
                    ];
                })->values()->all(),
            'attributes' => $product->productToAttributes
                ->filter(static fn ($attribute): bool => $shop_language_ids === []
                    || in_array((int) $attribute->shop_language_id, $shop_language_ids, true))
                ->map(static function ($attribute) use ($attribute_description_map, $shop_language_map_by_id): array {
                    $shop_language_id = (int) $attribute->shop_language_id;
                    $attribute_id     = (int) $attribute->attribute_id;

                    return [
                        'attribute_id'       => $attribute_id,
                        'attribute_name'     => $attribute_description_map[$attribute_id.':'.$shop_language_id] ?? null,
                        'shop_language_id'   => $shop_language_id,
                        'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                        'text'               => $attribute->text,
                    ];
                })->values()->all(),
            'seo_urls' => $seo_urls,
            'specials' => $product->specials
                ->map(static fn ($special): array => [
                    'user_group_id' => $special->user_group_id,
                    'price'         => $special->price,
                    'priority'      => $special->priority,
                    'date_start'    => $special->date_start,
                    'date_end'      => $special->date_end,
                ])->values()->all(),
            'discounts' => $product->discounts
                ->map(static fn ($discount): array => [
                    'user_group_id' => $discount->user_group_id,
                    'quantity'      => $discount->quantity,
                    'price'         => $discount->price,
                    'priority'      => $discount->priority,
                    'date_start'    => $discount->date_start,
                    'date_end'      => $discount->date_end,
                ])->values()->all(),
        ];
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

    private function resolveBaseUrlForDefaultApi(Shop $shop): string
    {
        $api_url  = Str::trim((string) ($shop->api_url ?? ''));
        $base_url = $api_url !== '' ? $api_url : Str::trim((string) ($shop->base_url ?? ''));

        if ($base_url === '') {
            throw new RuntimeException('Missing api_url/base_url for API requests');
        }

        return Str::rtrim($base_url, '/');
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        $api_base_url = $this->resolveBaseUrlForDefaultApi($shop);

        if (validate_url($api_base_url) === false) {
            throw new RuntimeException('Invalid api_url/base_url for OpenCart API requests');
        }

        return $api_base_url;
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

        if (Str::startsWith($part_api_url_update_prods, ['http://', 'https://'])) {
            return $part_api_url_update_prods;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($part_api_url_update_prods, '/');
    }

    private function resolveBackupUrl(Shop $shop, string $api_base_url): string
    {
        $options                   = is_array($shop->options) ? $shop->options : [];
        $part_api_url_backup_prods = Str::trim((string) Arr::get($options, 'part_api_url_backup_prods', ''));

        if ($part_api_url_backup_prods === '') {
            throw new RuntimeException('Missing part_api_url_backup_prods for backup API');
        }

        if (Str::startsWith($part_api_url_backup_prods, ['http://', 'https://'])) {
            return $part_api_url_backup_prods;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($part_api_url_backup_prods, '/');
    }

    private function resolveOpenCartLoginUrl(Shop $shop, string $api_base_url): string
    {
        $part_api_url_login = $shop->part_api_url_login ?? '';

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($part_api_url_login, '/');
    }

    private function resolveStoredAuthApiToken(Shop $shop): string
    {
        $options = is_array($shop->options) ? $shop->options : [];

        return Str::trim((string) Arr::get($options, 'auth_api_token', ''));
    }

    private function persistOpenCartAuthApiToken(Shop $shop, string $auth_api_token): void
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
    private function requestOpenCartAuthApiToken(Shop $shop, string $api_base_url, int $timeout): string
    {
        $login_url = $this->resolveOpenCartLoginUrl($shop, $api_base_url);
        $options   = is_array($shop->options) ? $shop->options : [];

        $api_username = Str::trim((string) Arr::get($options, 'api_username', ''));
        $api_token    = Str::trim((string) ($shop->api_token ?? ''));

        if ($api_token === '') {
            throw new RuntimeException('Missing api_token for OpenCart login API request');
        }

        $response = Http::timeout($timeout)->asForm();

        $response = $this->applyDebugCookieForDevelopment($response)
            ->post($login_url, [
                'username' => $api_username,
                'key'      => $api_token,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenCart login API failed with status '.$response->status().': '.$response->body());
        }

        $response_data  = $response->json();
        $auth_api_token = Str::trim((string) Arr::get($response_data, 'api_token', ''));

        if ($auth_api_token === '') {
            throw new RuntimeException('OpenCart login API did not return api_token');
        }

        return $auth_api_token;
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
        $request_url = $url;
        $request_url .= Str::contains($url, '?') ? '&' : '?';
        $request_url .= 'api_token='.urlencode($auth_api_token);

        $request = Http::timeout($timeout)->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        return $request->post($request_url, $request_payload);
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        $is_debug_mode              = (bool) config('app.debug', false);
        $is_development_environment = app()->isLocal();

        if ($is_debug_mode === false || $is_development_environment === false) {
            return $request;
        }

        return $request->withHeaders([
            'Cookie' => 'XDEBUG_SESSION=PHPSTORM',
        ]);
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
    {
        if ($response->status() === SymfonyResponse::HTTP_UNAUTHORIZED || $response->status() === SymfonyResponse::HTTP_FORBIDDEN) {
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

    private function truncateResponseBody(string $response_body): string
    {
        return Str::limit(Str::trim($response_body), 20000);
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
}
