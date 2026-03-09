<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductTableActionService
{
    /**
     * @param  iterable<mixed>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueUpdateForSelectedProducts(iterable $records, array $shop_ids): array
    {
        $requested_by_user_id = is_numeric(auth()->id()) ? (int) auth()->id() : null;
        return app(ProductUpdateQueueService::class)->queueForCatalogProducts(
            $records,
            $shop_ids,
            $requested_by_user_id,
        );
    }

    public function hasValidExternalBackupForAnyBoundShop(Product $record): bool
    {
        $product_id = (int) ($record->id ?? 0);
        if ($product_id <= 0) {
            return false;
        }

        /** @var ProductBackupRestoreService $restore_service */
        $restore_service = app(ProductBackupRestoreService::class);

        $product_shops = ProductShop::query()
            ->where('product_id', $product_id)
            ->get(['shop_id', 'external_product_id']);

        foreach ($product_shops as $product_shop) {
            $shop_id             = (int) ($product_shop->shop_id ?? 0);
            $external_product_id = (int) ($product_shop->external_product_id ?? 0);

            if ($shop_id <= 0 || $external_product_id <= 0) {
                continue;
            }

            if ($restore_service->hasValidLatestExternalSnapshotForProductShop($product_id, $shop_id, $external_product_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueRestoreForSelectedProducts(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total'         => 0,
            'shops_total'            => count($shop_ids),
            'restore_batches_queued' => 0,
            'errors'                 => 0,
        ];

        $product_ids = $records
            ->filter(static fn ($record): bool => $record instanceof Product)
            ->map(static fn (Product $record): int => (int) $record->id)
            ->filter(static fn (int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        $summary['products_total'] = count($product_ids);

        if ($product_ids === [] || $shop_ids === []) {
            return $summary;
        }

        $requested_by_user_id = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        try {
            $batch = ProductUpdateBatch::query()->create([
                'user_id'         => $requested_by_user_id,
                'source_type'     => ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
                'source_name'     => 'Catalog products restore API',
                'source_path'     => null,
                'status'          => ProductUpdateBatchesStatusEnum::PROCESSING->value,
                'total_items'     => 0,
                'processed_items' => 0,
                'failed_items'    => 0,
                'options'         => [
                    'triggered_from'       => 'catalog_products_restore',
                    'requested_by_user_id' => $requested_by_user_id,
                    'restore_state'        => 'processing',
                    'restore_started_at'   => now()->toDateTimeString(),
                    'restore_finished_at'  => null,
                ],
                'started_at'  => now(),
                'finished_at' => null,
            ]);

            ProcessCatalogProductRestoreBatchJob::dispatch(
                (int) $batch->id,
                $product_ids,
                $shop_ids,
                $requested_by_user_id
            );

            $summary['restore_batches_queued']++;
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to queue catalog products restore batch', [
                'product_ids'          => $product_ids,
                'shop_ids'             => $shop_ids,
                'requested_by_user_id' => $requested_by_user_id,
                'error_msg'            => $exception->getMessage(),
                'file'                 => $exception->getFile(),
                'line'                 => $exception->getLine(),
            ]);

            $summary['errors']++;
        }

        return $summary;
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function bindSelectedProductsToShops(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total'        => 0,
            'shops_total'           => count($shop_ids),
            'jobs_queued'           => 0,
            'skipped_already_bound' => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $summary['products_total']++;

            $source_item = ProductImportItem::query()
                ->where('product_id', (int) $record->id)
                ->orderByDesc('id')
                ->first();

            $source_payload = $source_item !== null && is_array($source_item->payload)
                ? $source_item->payload
                : [];

            $product_import_batch_id = (int) ($source_item?->product_import_batch_id ?? 0);

            foreach ($shop_ids as $shop_id) {
                $already_bound = ProductShop::query()
                    ->where('product_id', (int) $record->id)
                    ->where('shop_id', (int) $shop_id)
                    ->exists();

                if ($already_bound) {
                    $summary['skipped_already_bound']++;

                    continue;
                }

                ProcessProductShopBindingJob::dispatch(
                    (int) $record->id,
                    $shop_ids,
                    $product_import_batch_id,
                    $source_payload,
                    auth()->id()
                );

                $summary['jobs_queued']++;
                break;
            }
        }

        return $summary;
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueExportForSelectedProducts(Collection $records, array $shop_ids): array
    {
        /**
         * Export is skipped with explicit FAILED items when product is not bound to shop.
         * This keeps batch statistics consistent and visible to users.
         */
        $summary = [
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $summary['products_total']++;

            $source_item = ProductImportItem::query()
                ->where('product_id', (int) $record->id)
                ->orderByDesc('id')
                ->first();

            $source_batch_id = (int) ($source_item?->product_import_batch_id ?? 0);

            foreach ($shop_ids as $shop_id) {
                try {
                    $product_shop = ProductShop::query()
                        ->where('product_id', (int) $record->id)
                        ->where('shop_id', (int) $shop_id)
                        ->orderByDesc('id')
                        ->first();

                    if (! $product_shop instanceof ProductShop) {
                        $this->markExportAsFailedForNotBoundShop(
                            $source_batch_id,
                            (int) $record->id,
                            (int) $shop_id
                        );
                        $summary['skipped_not_bound']++;

                        continue;
                    }

                    $target_product_id = (int) ($product_shop->product_id ?? 0);
                    if ($target_product_id <= 0) {
                        $this->markExportAsFailedForNotBoundShop(
                            $source_batch_id,
                            (int) $record->id,
                            (int) $shop_id
                        );
                        $summary['skipped_not_bound']++;

                        continue;
                    }

                    $batch_id = $this->resolveBatchIdForProductShop($target_product_id, (int) $shop_id, $source_batch_id);
                    if ($batch_id <= 0) {
                        $summary['errors']++;

                        continue;
                    }

                    $existing_export_item = ProductExportItem::query()
                        ->forBatchProductShop($batch_id, $target_product_id, (int) $shop_id)
                        ->orderByDesc('id')
                        ->first();

                    if ($existing_export_item !== null) {
                        if ($existing_export_item->status === ProductExportItemsStatusEnum::FAILED->value) {
                            $summary['already_failed']++;
                        } else {
                            $summary['already_queued_or_exported']++;
                        }

                        continue;
                    }

                    $export_item = ProductExportItem::query()->create([
                        'batchable_type' => ProductImportBatch::class,
                        'batchable_id'   => $batch_id,
                        'product_id'     => $target_product_id,
                        'payload'        => [
                            'shop_id'              => (int) $shop_id,
                            'requested_product_id' => (int) $record->id,
                            'target_product_id'    => $target_product_id,
                            'requested_by_user_id' => auth()->id(),
                        ],
                        'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                        'error_message' => null,
                        'processed_at'  => null,
                    ]);

                    ProcessProductExportItemJob::dispatch((int) $export_item->id);
                    $summary['exports_queued']++;

                    $batch = ProductImportBatch::query()->find($batch_id);
                    if ($batch instanceof ProductImportBatch) {
                        $batch->update([
                            'status'  => ProductImportBatchesStatusEnum::PROCESSING->value,
                            'options' => [
                                ...($batch->options ?? []),
                                'export_state'       => 'processing',
                                'export_started_at'  => now()->toDateTimeString(),
                                'export_finished_at' => null,
                            ],
                        ]);
                    }
                } catch (Throwable $exception) {
                    Log::channel('stack')->warning('Failed to queue product export from products table', [
                        'product_id' => (int) ($record->id ?? 0),
                        'shop_id'    => (int) $shop_id,
                        'error_msg'  => $exception->getMessage(),
                    ]);

                    $summary['errors']++;
                }
            }
        }

        return $summary;
    }

    public function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id): void
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $resolved_batch_id = $batch_id;
        if ($resolved_batch_id <= 0) {
            $resolved_batch_id = (int) (ProductImportItem::query()
                ->where('product_id', $product_id)
                ->orderByDesc('id')
                ->value('product_import_batch_id') ?? 0);
        }

        if ($resolved_batch_id <= 0) {
            return;
        }

        $error_message = 'Product is not bound to selected shop. Export skipped.';

        $existing_export_item = ProductExportItem::query()
            ->forBatchProductShop($resolved_batch_id, $product_id, $shop_id)
            ->orderByDesc('id')
            ->first();

        if ($existing_export_item instanceof ProductExportItem) {
            $existing_export_item->update([
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at'  => now(),
                'payload'       => [
                    ...(is_array($existing_export_item->payload) ? $existing_export_item->payload : []),
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => null,
                    'failure_reason'       => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
            ]);
        } else {
            ProductExportItem::query()->create([
                'batchable_type' => ProductImportBatch::class,
                'batchable_id'   => $resolved_batch_id,
                'product_id'     => $product_id,
                'payload'        => [
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => null,
                    'failure_reason'       => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at'  => now(),
            ]);
        }

        Log::channel('stack')->warning('Products table export skipped: product is not bound to selected shop', [
            'batch_id'             => $resolved_batch_id,
            'product_id'           => $product_id,
            'shop_id'              => $shop_id,
            'requested_by_user_id' => auth()->id(),
        ]);
    }

    public function resolveBatchIdForProductShop(int $product_id, int $shop_id, int $fallback_batch_id = 0): int
    {
        $batch_id = (int) (ProductShop::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->value('product_import_batch_id') ?? 0);

        if ($batch_id > 0) {
            return $batch_id;
        }

        if ($fallback_batch_id > 0) {
            return $fallback_batch_id;
        }

        return (int) (ProductImportItem::query()
            ->where('product_id', $product_id)
            ->orderByDesc('id')
            ->value('product_import_batch_id') ?? 0);
    }

}
