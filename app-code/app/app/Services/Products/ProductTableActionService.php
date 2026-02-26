<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductTableActionService
{
    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueUpdateForSelectedProducts(Collection $records, array $shop_ids): array
    {
        /**
         * One batch groups all selected products and target shops.
         * Each product/shop pair produces at most one update item due to duplicate checks.
         */
        $summary = [
            'products_total'              => 0,
            'shops_total'                 => count($shop_ids),
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        if ($shop_ids === []) {
            return $summary;
        }

        $requested_by_user_id = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => $requested_by_user_id,
            'source_type'     => ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
            'source_name'     => 'Catalog products local update',
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'triggered_from'       => 'catalog_products',
                'requested_by_user_id' => $requested_by_user_id,
                'update_state'         => 'processing',
                'update_started_at'    => now()->toDateTimeString(),
                'update_finished_at'   => null,
            ],
            'started_at'  => now(),
            'finished_at' => null,
        ]);

        foreach ($records as $record) {
            if (! $record instanceof Product) {
                continue;
            }

            $summary['products_total']++;
            $product_id = (int) ($record->id ?? 0);

            foreach ($shop_ids as $shop_id) {
                $queued_result = $this->createLocalUpdateItemAndDispatch(
                    (int) $batch->id,
                    $product_id,
                    (int) $shop_id,
                    $requested_by_user_id
                );

                $summary['updates_queued'] += (int) Arr::get($queued_result, 'updates_queued', 0);
                $summary['already_failed'] += (int) Arr::get($queued_result, 'already_failed', 0);
                $summary['already_queued_or_exported'] += (int) Arr::get($queued_result, 'already_queued_or_exported', 0);
                $summary['skipped_not_bound'] += (int) Arr::get($queued_result, 'skipped_not_bound', 0);
                $summary['skipped_without_external_id'] += (int) Arr::get($queued_result, 'skipped_without_external_id', 0);
                $summary['failed_created'] += (int) Arr::get($queued_result, 'failed_created', 0);
                $summary['errors'] += (int) Arr::get($queued_result, 'errors', 0);
            }
        }

        $this->syncLocalUpdateBatchStatus((int) $batch->id);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function createLocalUpdateItemAndDispatch(
        int $batch_id,
        int $product_id,
        int $shop_id,
        ?int $requested_by_user_id = null
    ): array {
        /**
         * This method is intentionally idempotent inside one batch+shop scope.
         * Repeated calls should report "already_*" states instead of creating duplicates.
         */
        $summary = [
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        try {
            $existing_update_item = ProductUpdateItem::query()
                ->where('product_update_batch_id', $batch_id)
                ->where('product_id', $product_id)
                ->where('payload->operation', 'update')
                ->where('payload->shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();

            if ($existing_update_item instanceof ProductUpdateItem) {
                if ($existing_update_item->status === ProductUpdateItemsStatusEnum::FAILED->value) {
                    $summary['already_failed']++;
                } else {
                    $summary['already_queued_or_exported']++;
                }

                return $summary;
            }

            $product_shop = ProductShop::query()
                ->where('product_id', $product_id)
                ->where('shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();

            if (! $product_shop instanceof ProductShop) {
                $this->createLocalFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'Product is not bound to selected shop',
                    $requested_by_user_id
                );
                $summary['skipped_not_bound']++;
                $summary['failed_created']++;

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                $this->createLocalFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'External product id is missing for update',
                    $requested_by_user_id
                );
                $summary['skipped_without_external_id']++;
                $summary['failed_created']++;

                return $summary;
            }

            $update_item = ProductUpdateItem::query()->create([
                'product_update_batch_id' => $batch_id,
                'product_id'              => $product_id,
                'payload'                 => [
                    'operation'            => 'update',
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => $product_id,
                    'external_product_id'  => $external_product_id,
                    'requested_by_user_id' => $requested_by_user_id,
                    'triggered_from'       => 'catalog_products',
                    'update_instructions'  => [],
                ],
                'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductUpdateItemJob::dispatch((int) $update_item->id);
            $summary['updates_queued']++;
        } catch (QueryException $exception) {
            $sql_state = (string) ($exception->errorInfo[0] ?? '');

            if ($sql_state === '23505') {
                $summary['already_queued_or_exported']++;

                return $summary;
            }

            Log::channel('stack')->error('Failed to create local product update item', [
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'message'    => $exception->getMessage(),
            ]);

            $summary['errors']++;
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to queue local product update', [
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'message'    => $exception->getMessage(),
            ]);

            $summary['errors']++;
        }

        return $summary;
    }

    public function syncLocalUpdateBatchStatus(int $batch_id): void
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
            ->where('payload->operation', 'update')
            ->groupBy('status')
            ->get();

        $total_update_items = (int) $status_rows->sum(static fn ($row): int => (int) ($row->status_total ?? 0));
        if ($total_update_items <= 0) {
            return;
        }

        $processing_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::PROCESSING->value)->status_total ?? 0);
        $failed_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::FAILED->value)->status_total ?? 0);
        $updated_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::SUCCESSED->value)->status_total ?? 0);

        $final_status = match (true) {
            $processing_count > 0                     => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            $failed_count > 0 && $updated_count > 0   => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $updated_count === 0 => ProductUpdateBatchesStatusEnum::FAILED->value,
            default                                   => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status'          => $final_status,
            'total_items'     => $total_update_items,
            'processed_items' => max($updated_count + $failed_count, 0),
            'failed_items'    => max($failed_count, 0),
            'finished_at'     => $processing_count > 0 ? null : now(),
            'options'         => [
                ...($batch->options ?? []),
                'update_state'         => $processing_count > 0 ? 'processing' : 'finished',
                'update_total_items'   => $total_update_items,
                'update_success_items' => $updated_count,
                'update_failed_items'  => $failed_count,
                'update_finished_at'   => $processing_count > 0 ? null : now()->toDateTimeString(),
            ],
        ]);
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
            $shop_id = (int) ($product_shop->shop_id ?? 0);
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

                ProcessProductShopBindingJob::dispatchSync(
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

    private function createLocalFailedUpdateItem(
        int $batch_id,
        int $product_id,
        int $shop_id,
        string $error_message,
        ?int $requested_by_user_id = null
    ): void {
        Log::channel('stack')->error('Local product update skipped', [
            'batch_id'   => $batch_id,
            'product_id' => $product_id,
            'shop_id'    => $shop_id,
            'message'    => $error_message,
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => $batch_id,
            'product_id'              => $product_id,
            'payload'                 => [
                'operation'            => 'update',
                'shop_id'              => $shop_id,
                'requested_product_id' => $product_id,
                'target_product_id'    => $product_id,
                'requested_by_user_id' => $requested_by_user_id,
                'triggered_from'       => 'catalog_products',
                'update_instructions'  => [],
            ],
            'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at'  => now(),
        ]);
    }
}
