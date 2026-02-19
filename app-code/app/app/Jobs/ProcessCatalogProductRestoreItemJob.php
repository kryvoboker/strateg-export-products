<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
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
                throw new RuntimeException('Valid external backup for product/shop not found');
            }

            $backup_payload = is_array($backup->payload) ? $backup->payload : [];

            $request_payload = [
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
                throw new RuntimeException('Restore API failed with status '.$response->status().': '.$response->body());
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
        $url      = Str::startsWith($endpoint, ['http://', 'https://'])
            ? $endpoint
            : Str::rtrim($base_url, '/').'/'.Str::ltrim($endpoint, '/');

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

        if (Str::startsWith($part_api_url_restore_prods, ['http://', 'https://'])) {
            return $part_api_url_restore_prods;
        }

        return Str::rtrim($api_base_url, '/').'/'.Str::ltrim($part_api_url_restore_prods, '/');
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

        $response = Http::timeout($timeout)
            ->asForm();

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
        string $request_url,
        string $auth_api_token,
        array $request_payload,
        int $timeout
    ): Response {
        $url = $request_url;
        $url .= Str::contains($request_url, '?') ? '&' : '?';
        $url .= 'api_token='.urlencode($auth_api_token);

        $request = Http::timeout($timeout)
            ->asForm();
        $request = $this->applyDebugCookieForDevelopment($request);

        return $request->post($url, $request_payload);
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
}
