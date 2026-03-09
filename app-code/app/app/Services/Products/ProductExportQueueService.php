<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\ProductShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductExportQueueService
{
    /**
     * @param  Collection<int, ProductImportBatch>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForImportBatches(Collection $records, array $shop_ids, ?int $user_id = null): array
    {
        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);

        $summary = [
            'batches_selected'           => $records->count(),
            'batches_skipped_processing' => 0,
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductImportBatch) {
                continue;
            }

            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductImportItemsStatusEnum::SUCCESSED->value,
                    ProductImportItemsStatusEnum::NORMALIZED->value,
                ])
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            $summary['products_total'] += count($product_ids);

            foreach ($product_ids as $product_id) {
                $item_summary = $this->queueExportForProduct(
                    $product_id,
                    (int) $batch->id,
                    $normalized_shop_ids,
                    $user_id,
                );

                $summary['exports_queued'] += $item_summary['exports_queued'];
                $summary['already_failed'] += $item_summary['already_failed'];
                $summary['already_queued_or_exported'] += $item_summary['already_queued_or_exported'];
                $summary['skipped_not_bound'] += $item_summary['skipped_not_bound'];
                $summary['errors'] += $item_summary['errors'];
            }

            ProductImportBatch::query()->whereKey((int) $batch->id)->update([
                'total_items' => ProductImportItem::query()
                    ->where('product_import_batch_id', (int) $batch->id)
                    ->count(),
            ]);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductImportItem>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForImportItems(Collection $records, array $shop_ids, ?int $user_id = null): array
    {
        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);

        $summary = [
            'items_selected'                => $records->count(),
            'items_skipped_processing'      => 0,
            'items_skipped_without_product' => 0,
            'products_total'                => 0,
            'exports_queued'                => 0,
            'already_failed'                => 0,
            'already_queued_or_exported'    => 0,
            'skipped_not_bound'             => 0,
            'errors'                        => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductImportItem) {
                continue;
            }

            if ($record->status === ProductImportItemsStatusEnum::PROCESSING->value) {
                $summary['items_skipped_processing']++;

                continue;
            }

            $product_id = (int) ($record->product_id ?? 0);
            if ($product_id <= 0) {
                $summary['items_skipped_without_product']++;

                continue;
            }

            $batch_id = (int) ($record->product_import_batch_id ?? 0);
            $item_summary = $this->queueExportForProduct(
                $product_id,
                $batch_id,
                $normalized_shop_ids,
                $user_id,
            );

            $summary['products_total'] += $item_summary['products_total'];
            $summary['exports_queued'] += $item_summary['exports_queued'];
            $summary['already_failed'] += $item_summary['already_failed'];
            $summary['already_queued_or_exported'] += $item_summary['already_queued_or_exported'];
            $summary['skipped_not_bound'] += $item_summary['skipped_not_bound'];
            $summary['errors'] += $item_summary['errors'];
        }

        return $summary;
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForSingleImportItem(ProductImportItem $record, array $shop_ids, ?int $user_id = null): array
    {
        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

        return $this->queueExportForProduct(
            $product_id,
            $batch_id,
            $this->normalizeShopIds($shop_ids),
            $user_id,
        );
    }

    /**
     * @param  Collection<int, ProductImportBatch>  $records
     * @return array<string, int>
     */
    public function retryFailedForImportBatches(Collection $records): array
    {
        $batch_ids = $records
            ->map(static fn (ProductImportBatch $batch): int => (int) $batch->id)
            ->filter(static fn (int $batch_id): bool => $batch_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($batch_ids === []) {
            return [
                'failed_found' => 0,
                'queued'       => 0,
            ];
        }

        $failed_export_items = ProductExportItem::query()
            ->where('batchable_type', ProductImportBatch::class)
            ->whereIn('batchable_id', $batch_ids)
            ->where('status', ProductExportItemsStatusEnum::FAILED->value)
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($failed_export_items as $failed_export_item) {
            $failed_export_item->update([
                'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductExportItemJob::dispatch((int) $failed_export_item->id);
            $queued++;
        }

        if ($queued > 0) {
            foreach ($batch_ids as $batch_id) {
                $this->markBatchAsExporting($batch_id);
            }
        }

        return [
            'failed_found' => $failed_export_items->count(),
            'queued'       => $queued,
        ];
    }

    /**
     * @param  Collection<int, ProductImportItem>  $records
     * @return array<string, int>
     */
    public function retryFailedForImportItems(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductImportItem) {
                continue;
            }

            $product_id = (int) ($record->product_id ?? 0);
            $batch_id   = (int) ($record->product_import_batch_id ?? 0);

            if ($product_id <= 0 || $batch_id <= 0) {
                continue;
            }

            $failed_export_items = ProductExportItem::query()
                ->forBatchable(ProductImportBatch::class, $batch_id)
                ->where('product_id', $product_id)
                ->where('status', ProductExportItemsStatusEnum::FAILED->value)
                ->orderBy('id')
                ->get();

            $summary['failed_found'] += $failed_export_items->count();

            $queued_for_item = 0;
            foreach ($failed_export_items as $failed_export_item) {
                $failed_export_item->update([
                    'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                    'error_message' => null,
                    'processed_at'  => null,
                ]);

                ProcessProductExportItemJob::dispatch((int) $failed_export_item->id);
                $queued_for_item++;
                $summary['queued']++;
            }

            if ($queued_for_item > 0) {
                $this->markBatchAsExporting($batch_id);
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function retryFailedForSingleImportItem(ProductImportItem $record): array
    {
        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

        if ($product_id <= 0 || $batch_id <= 0) {
            return [
                'failed_found' => 0,
                'queued'       => 0,
            ];
        }

        $failed_export_items = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('status', ProductExportItemsStatusEnum::FAILED->value)
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($failed_export_items as $failed_export_item) {
            $failed_export_item->update([
                'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductExportItemJob::dispatch((int) $failed_export_item->id);
            $queued++;
        }

        if ($queued > 0) {
            $this->markBatchAsExporting($batch_id);
        }

        return [
            'failed_found' => $failed_export_items->count(),
            'queued'       => $queued,
        ];
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function queueExportForProduct(int $requested_product_id, int $batch_id, array $shop_ids, ?int $user_id): array
    {
        $summary = [
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        if ($requested_product_id <= 0 || $batch_id <= 0) {
            return $summary;
        }

        $summary['products_total'] = 1;

        foreach ($shop_ids as $shop_id) {
            try {
                $product_shop = ProductShop::resolveLatestByProductAndShop($requested_product_id, $shop_id);
                if (! $product_shop instanceof ProductShop) {
                    $this->markExportAsFailedForNotBoundShop($batch_id, $requested_product_id, $shop_id, $user_id);
                    $summary['skipped_not_bound']++;

                    continue;
                }

                $target_product_id = (int) ($product_shop->product_id ?? 0);
                if ($target_product_id <= 0) {
                    $this->markExportAsFailedForNotBoundShop($batch_id, $requested_product_id, $shop_id, $user_id);
                    $summary['skipped_not_bound']++;

                    continue;
                }

                $resolved_batch_id = (int) ($product_shop->product_import_batch_id ?? 0);
                if ($resolved_batch_id <= 0) {
                    $resolved_batch_id = $batch_id;
                }

                $existing_export_item = ProductExportItem::query()
                    ->forBatchProductShop($resolved_batch_id, $target_product_id, $shop_id)
                    ->orderByDesc('id')
                    ->first();

                if ($existing_export_item instanceof ProductExportItem) {
                    if ($existing_export_item->status === ProductExportItemsStatusEnum::FAILED->value) {
                        $summary['already_failed']++;
                    } else {
                        $summary['already_queued_or_exported']++;
                    }

                    continue;
                }

                $export_item = ProductExportItem::query()->create([
                    'batchable_type' => ProductImportBatch::class,
                    'batchable_id'   => $resolved_batch_id,
                    'product_id'     => $target_product_id,
                    'payload'        => [
                        'shop_id'              => $shop_id,
                        'requested_product_id' => $requested_product_id,
                        'target_product_id'    => $target_product_id,
                        'requested_by_user_id' => $user_id,
                    ],
                    'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                    'error_message' => null,
                    'processed_at'  => null,
                ]);

                ProcessProductExportItemJob::dispatch((int) $export_item->id);
                $summary['exports_queued']++;

                $this->markBatchAsExporting($resolved_batch_id);
            } catch (\Throwable $exception) {
                $summary['errors']++;

                Log::channel('stack')->error('Product export queue preparation failed', [
                    'service'              => self::class,
                    'requested_product_id' => $requested_product_id,
                    'batch_id'             => $batch_id,
                    'shop_id'              => $shop_id,
                    'requested_by_user_id' => $user_id,
                    'message'              => $exception->getMessage(),
                    'file'                 => $exception->getFile(),
                    'line'                 => $exception->getLine(),
                ]);
            }
        }

        return $summary;
    }

    private function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id, ?int $user_id): void
    {
        if ($batch_id <= 0 || $product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $error_message = 'Product is not bound to selected shop. Export skipped.';

        $existing_export_item = ProductExportItem::query()
            ->forBatchProductShop($batch_id, $product_id, $shop_id)
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
                    'requested_by_user_id' => $user_id,
                ],
            ]);
        } else {
            ProductExportItem::query()->create([
                'batchable_type' => ProductImportBatch::class,
                'batchable_id'   => $batch_id,
                'product_id'     => $product_id,
                'payload'        => [
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => null,
                    'failure_reason'       => 'not_bound_to_shop',
                    'requested_by_user_id' => $user_id,
                ],
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at'  => now(),
            ]);
        }

        Log::channel('stack')->warning('Product export skipped: product is not bound to selected shop', [
            'service'              => self::class,
            'batch_id'             => $batch_id,
            'product_id'           => $product_id,
            'shop_id'              => $shop_id,
            'requested_by_user_id' => $user_id,
        ]);
    }

    private function markBatchAsExporting(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductImportBatch::query()->find($batch_id);
        if (! $batch instanceof ProductImportBatch) {
            return;
        }

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

    /**
     * @param  list<int>  $shop_ids
     * @return list<int>
     */
    private function normalizeShopIds(array $shop_ids): array
    {
        return collect($shop_ids)
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
