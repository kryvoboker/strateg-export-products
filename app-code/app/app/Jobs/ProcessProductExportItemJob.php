<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Models\Attributes\AttributeDescription;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
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
use Throwable;

class ProcessProductExportItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $product_export_item_id) {}

    public function handle(): void
    {
        $product_export_item = ProductExportItem::query()->find($this->product_export_item_id);
        if ($product_export_item === null) {
            return;
        }

        $payload    = is_array($product_export_item->payload) ? $product_export_item->payload : [];
        $shop_id    = (int)Arr::get($payload, 'shop_id', 0);
        $product_id = (int)($product_export_item->product_id ?? 0);

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

            if (!$is_bound_to_shop) {
                throw new RuntimeException('Product is not bound to selected shop');
            }

            $request_payload = $this->buildRequestPayload($product, $shop_id);
            $response        = $this->sendExportRequest($shop, $request_payload);

            if (!$response->successful()) {
                throw new RuntimeException('Export API failed with status ' . $response->status() . ': ' . $response->body());
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
                'processed_at'  => get_now_date(),
                'payload'       => [
                    ...$payload,
                    'request_payload'     => $request_payload,
                    'response_status'     => $response->status(),
                    'response_body'       => $this->truncateResponseBody($response->body()),
                    'external_product_id' => $external_product_id ?: null,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to export product with id ' . $product_id . ' for shop with id ' . $shop_id, [
                'error_msg'  => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'exception'  => $exception,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
            ]);

            $product_export_item->update([
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => Str::limit(Str::trim($exception->getMessage()), 10000),
                'processed_at'  => get_now_date(),
            ]);
        } finally {
            $this->syncBatchStatusByExportItems((int)$product_export_item->product_import_batch_id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestPayload(Product $product, int $shop_id): array
    {
        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
        ]);

        $shop_languages = ShopLanguage::getActiveByShopId($shop_id);

        $shop_language_ids = $shop_languages
            ->pluck('id')
            ->map(static fn($shop_language_id): int => (int)$shop_language_id)
            ->filter(static fn(int $shop_language_id): bool => $shop_language_id > 0)
            ->values()
            ->all();

        $shop_language_map_by_id = $shop_languages
            ->mapWithKeys(static fn(ShopLanguage $shop_language): array => [
                (int)$shop_language->id => [
                    'id'   => (int)$shop_language->id,
                    'code' => Str::lower(Str::trim((string)$shop_language->code)),
                    'name' => $shop_language->name,
                ],
            ])
            ->toArray();

        $seo_urls = SeoUrl::getForSeoableAndLanguageIds(Product::class, (int) $product->id, $shop_language_ids)
            ->map(static function (SeoUrl $seo_url) use ($shop_language_map_by_id): array {
                $shop_language_id = $seo_url->shop_language_id !== null ? (int)$seo_url->shop_language_id : null;

                return [
                    'shop_language_id'   => $seo_url->shop_language_id,
                    'shop_language_code' => $shop_language_id !== null
                        ? Arr::get($shop_language_map_by_id, $shop_language_id . '.code')
                        : null,
                    'query_value'        => $seo_url->query_value,
                    'keyword'            => $seo_url->keyword,
                    'sort_order'         => $seo_url->sort_order,
                ];
            })
            ->values()
            ->all();

        $attribute_pairs = [];
        foreach ($product->productToAttributes as $product_to_attribute) {
            $attribute_id = (int) ($product_to_attribute->attribute_id ?? 0);
            $shop_language_id = (int) ($product_to_attribute->shop_language_id ?? 0);
            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $attribute_pairs[] = [
                'attribute_id' => $attribute_id,
                'shop_language_id' => $shop_language_id,
            ];
        }

        $attribute_description_map = AttributeDescription::getNameMapByAttributeLanguagePairs($attribute_pairs);

        return [
            'shop_id'        => $shop_id,
            'shop_languages' => array_values($shop_language_map_by_id),
            'product'        => [
                'id'             => $product->id,
                'model'          => $product->model,
                'sku'            => $product->sku,
                'ean'            => $product->ean,
                'quantity'       => $product->quantity,
                'minimum'        => $product->minimum,
                'image'          => $product->image,
                'price'          => $product->price,
                'is_active'      => (bool)$product->is_active,
                'date_available' => $product->date_available?->toDateTimeString(),
                'date_added'     => $product->date_added?->toDateTimeString(),
            ],
            'descriptions'   => $product->descriptions
                ->filter(static fn($description): bool => $shop_language_ids === []
                    || in_array((int)$description->shop_language_id, $shop_language_ids, true))
                ->map(static function ($description) use ($shop_language_map_by_id): array {
                    $shop_language_id = (int)$description->shop_language_id;

                    return [
                        'shop_language_id'   => $shop_language_id,
                        'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id . '.code'),
                        'name'               => $description->name,
                        'description'        => $description->description,
                        'meta_title'         => $description->meta_title,
                        'meta_description'   => $description->meta_description,
                        'meta_keywords'      => $description->meta_keywords,
                    ];
                })->values()->all(),
            'images'         => $product->images
                ->map(static fn($image): array => [
                    'image'      => $image->image,
                    'sort_order' => $image->sort_order,
                ])->values()->all(),
            'categories'     => $product->categories
                ->map(static function ($category) use ($shop_language_ids, $shop_language_map_by_id): array {
                    $descriptions = $category->descriptions
                        ->filter(static fn($description): bool => $shop_language_ids === []
                            || in_array((int)$description->shop_language_id, $shop_language_ids, true))
                        ->map(static function ($description) use ($shop_language_map_by_id): array {
                            $shop_language_id = (int)$description->shop_language_id;

                            return [
                                'shop_language_id'   => $shop_language_id,
                                'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id . '.code'),
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
            'attributes'     => $product->productToAttributes
                ->filter(static fn($attribute): bool => $shop_language_ids === []
                    || in_array((int)$attribute->shop_language_id, $shop_language_ids, true))
                ->map(static function ($attribute) use ($attribute_description_map, $shop_language_map_by_id): array {
                    $shop_language_id = (int)$attribute->shop_language_id;
                    $attribute_id     = (int)$attribute->attribute_id;

                    return [
                        'attribute_id'       => $attribute_id,
                        'attribute_name'     => $attribute_description_map[$attribute_id . ':' . $shop_language_id] ?? null,
                        'shop_language_id'   => $shop_language_id,
                        'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id . '.code'),
                        'text'               => $attribute->text,
                    ];
                })->values()->all(),
            'seo_urls'       => $seo_urls,
            'specials'       => $product->specials
                ->map(static fn($special): array => [
                    'user_group_id' => $special->user_group_id,
                    'price'         => $special->price,
                    'priority'      => $special->priority,
                    'date_start'    => $special->date_start,
                    'date_end'      => $special->date_end,
                ])->values()->all(),
            'discounts'      => $product->discounts
                ->map(static fn($discount): array => [
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
     * @param array<string, mixed> $request_payload
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
     * @param array<string, mixed> $request_payload
     *
     * @throws ConnectionException
     */
    private function sendDefaultExportRequest(Shop $shop, array $request_payload): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $endpoint = Str::trim((string)Arr::get($options, 'product_export_endpoint'));
        if ($endpoint === '') {
            $endpoint = Str::trim((string)($shop->part_api_url_export_prods ?? ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('Missing product export endpoint!');
        }

        $api_url  = Str::trim((string)($shop->api_url ?? ''));
        $base_url = $api_url !== ''
            ? $api_url
            : Str::trim((string)$shop->base_url);

        $timeout = max((int)Arr::get($options, 'api_timeout', 30), 5);
        $url     = Str::startsWith($endpoint, ['http://', 'https://'])
            ? $endpoint
            : Str::rtrim($base_url, '/') . '/' . Str::ltrim($endpoint, '/');

        $request = Http::timeout($timeout)
            ->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        $api_token = Str::trim((string)Arr::get($options, 'api_token', ''));

        if ($api_token !== '') {
            $request = $request->withToken($api_token);
        }

        $header_name  = Str::trim((string)Arr::get($options, 'api_header_name', ''));
        $header_value = Str::trim((string)Arr::get($options, 'api_header_value', ''));

        if ($header_name !== '' && $header_value !== '') {
            $request = $request->withHeaders([
                $header_name => $header_value,
            ]);
        }

        return $request->post($url, $request_payload);
    }

    /**
     * @param array<string, mixed> $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartExportRequest(Shop $shop, array $request_payload): Response
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $timeout      = max((int)Arr::get($options, 'api_timeout', 30), 5);
        $api_base_url = $this->resolveApiBaseUrl($shop);
        $export_url   = $this->resolveExportUrl($shop, $api_base_url);

        $auth_api_token = $this->resolveStoredAuthApiToken($shop);
        if ($auth_api_token === '') {
            $auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
            $this->persistOpenCartAuthApiToken($shop, $auth_api_token);
        }

        $response = $this->sendOpenCartRequestWithAuthApiToken($export_url, $auth_api_token, $request_payload, $timeout);
        if (!$this->isInvalidOpenCartAuthTokenResponse($response)) {
            return $response;
        }

        $refreshed_auth_api_token = $this->requestOpenCartAuthApiToken($shop, $api_base_url, $timeout);
        $this->persistOpenCartAuthApiToken($shop, $refreshed_auth_api_token);

        return $this->sendOpenCartRequestWithAuthApiToken($export_url, $refreshed_auth_api_token, $request_payload, $timeout);
    }

    private function isOpenCartShop(Shop $shop): bool
    {
        $shop_type = Str::lower(Str::trim((string)($shop->type ?? '')));

        $allowed_types = config('app.allowed_projects_types.opencart', []);

        if (!is_array($allowed_types)) {
            return false;
        }

        foreach ($allowed_types as $allowed_type) {
            if (Str::lower(Str::trim((string)$allowed_type)) === $shop_type) {
                return true;
            }
        }

        return false;
    }

    private function resolveApiBaseUrl(Shop $shop): string
    {
        $api_url      = Str::trim((string)($shop->api_url ?? ''));
        $base_url     = Str::trim((string)($shop->base_url ?? ''));
        $api_base_url = $api_url !== '' ? $api_url : $base_url;

        if ($api_base_url === '') {
            throw new RuntimeException('Missing api_url/base_url for OpenCart API requests');
        }

        if (validate_url($api_base_url) === false) {
            throw new RuntimeException('Invalid api_url/base_url for OpenCart API requests');
        }

        return Str::rtrim($api_base_url, '/');
    }

    private function resolveExportUrl(Shop $shop, string $api_base_url): string
    {
        $part_api_url_export_prods = Str::trim((string)($shop->part_api_url_export_prods ?? ''));
        if ($part_api_url_export_prods === '') {
            throw new RuntimeException('Missing part_api_url_export_prods for export API');
        }

        if (Str::startsWith($part_api_url_export_prods, ['http://', 'https://'])) {
            return $part_api_url_export_prods;
        }

        return Str::rtrim($api_base_url, '/') . '/' . Str::ltrim($part_api_url_export_prods, '/');
    }

    private function resolveOpenCartLoginUrl(Shop $shop, string $api_base_url): string
    {
        $options = is_array($shop->options) ? $shop->options : [];

        $part_api_url_login = $shop->part_api_url_login ?? '';

        return Str::rtrim($api_base_url, '/') . '/' . Str::ltrim($part_api_url_login, '/');
    }

    private function resolveStoredAuthApiToken(Shop $shop): string
    {
        $options = is_array($shop->options) ? $shop->options : [];

        return Str::trim((string)Arr::get($options, 'auth_api_token', ''));
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

        $api_username = Str::trim((string)Arr::get($options, 'api_username', ''));
        $api_token    = Str::trim((string)($shop->api_token ?? ''));

        if ($api_token === '') {
            throw new RuntimeException('Missing api_token for OpenCart login API request');
        }

        $response = Http::timeout($timeout)
            ->asForm();

        $response = $this->applyDebugCookieForDevelopment($response)
            ->post($login_url, [
                'username' => $api_username,
                'key'      => $api_token,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('OpenCart login API failed with status ' . $response->status() . ': ' . $response->body());
        }

        $response_data  = $response->json();
        $auth_api_token = Str::trim((string)Arr::get($response_data, 'api_token', ''));

        if ($auth_api_token === '') {
            throw new RuntimeException('OpenCart login API did not return api_token');
        }

        return $auth_api_token;
    }

    /**
     * @param array<string, mixed> $request_payload
     *
     * @throws ConnectionException
     */
    private function sendOpenCartRequestWithAuthApiToken(
        string $export_url,
        string $auth_api_token,
        array  $request_payload,
        int    $timeout
    ): Response {
        $url = $export_url;
        $url .= Str::contains($export_url, '?') ? '&' : '?';
        $url .= 'api_token=' . urlencode($auth_api_token);

        $request = Http::timeout($timeout)
            ->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        return $request
            ->post($url, $request_payload);
    }

    private function applyDebugCookieForDevelopment(PendingRequest $request): PendingRequest
    {
        $is_debug_mode              = (bool)config('app.debug', false);
        $is_development_environment = app()->isLocal();

        if ($is_debug_mode === false || $is_development_environment === false) {
            return $request;
        }

//        return $request;

        return $request->withHeaders([
            'Cookie' => 'XDEBUG_SESSION=PHPSTORM',
        ]);
    }

    private function isInvalidOpenCartAuthTokenResponse(Response $response): bool
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
        ) {
            return true;
        }

        $response_data = $response->json();
        if (!is_array($response_data)) {
            return false;
        }

        if (Arr::has($response_data, 'error_invalid_token')) {
            return true;
        }

        $error_text = Str::lower(Str::trim((string)Arr::get($response_data, 'error', '')));

        return $error_text !== '' && Str::contains($error_text, 'token');
    }

    /**
     * @param array<string, mixed>|null $response_data
     */
    private function resolveExternalProductIdFromResponse(array|null $response_data): int
    {
        if (!is_array($response_data)) {
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
            if (is_numeric($candidate_id) && (int)$candidate_id > 0) {
                return (int)$candidate_id;
            }
        }

        return 0;
    }

    private function truncateResponseBody(string $response_body): string
    {
        return Str::limit(Str::trim($response_body), 20000);
    }

    private function syncBatchStatusByExportItems(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductImportBatch::query()->find($batch_id);
        if ($batch === null) {
            return;
        }

        $status_counters = ProductExportItem::getBatchStatusCounters($batch_id);
        $total_export_items = (int) ($status_counters['total'] ?? 0);
        if ($total_export_items <= 0) {
            return;
        }
        $processing_count = (int) ($status_counters['processing'] ?? 0);
        $failed_count = (int) ($status_counters['failed'] ?? 0);
        $exported_count = (int) ($status_counters['exported'] ?? 0);

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
                'export_finished_at'    => get_now_date()->toDateTimeString(),
            ],
        ]);
    }
}
