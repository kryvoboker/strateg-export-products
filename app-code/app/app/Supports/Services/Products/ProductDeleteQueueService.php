<?php

declare(strict_types=1);

namespace App\Supports\Services\Products;

use App\Enums\Product\Delete\ProductDeleteBatchesSourceTypeEnum;
use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Jobs\ProcessProductDeleteItemJob;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Models\Products\ProductShop;
use Illuminate\Support\Collection;
use App\Supports\Services\Products\Traits\NormalizesProductServiceInput;
use Illuminate\Support\Facades\Log;

class ProductDeleteQueueService
{
    use NormalizesProductServiceInput;

    /**
     * @param  Collection<int, ProductDeleteBatch>  $records
     * @return array<string, int>
     */
    public function queueForDeleteBatches(Collection $records): array
    {
        $summary = [
            'batches_selected'           => $records->count(),
            'batches_skipped_processing' => 0,
            'items_total'                => 0,
            'deletes_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_deleted'  => 0,
            'skipped_missing_payload'    => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductDeleteBatch) {
                continue;
            }

            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $items = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductDeleteItemsStatusEnum::NEW->value,
                    ProductDeleteItemsStatusEnum::FAILED->value,
                ])
                ->get();

            $summary['items_total'] += $items->count();

            $batch_summary = $this->queueForDeleteItems($items, true);
            $summary['deletes_queued'] += (int) ($batch_summary['deletes_queued'] ?? 0);
            $summary['already_failed'] += (int) ($batch_summary['already_failed'] ?? 0);
            $summary['already_queued_or_deleted'] += (int) ($batch_summary['already_queued_or_deleted'] ?? 0);
            $summary['skipped_missing_payload'] += (int) ($batch_summary['skipped_missing_payload'] ?? 0);
            $summary['errors'] += (int) ($batch_summary['errors'] ?? 0);

            $this->syncBatchStatusByItems((int) $batch->id);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductDeleteBatch>  $records
     * @return array<string, int>
     */
    public function retryFailedForDeleteBatches(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
            'errors'       => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductDeleteBatch) {
                continue;
            }

            $failed_items = $batch->items()
                ->where('status', ProductDeleteItemsStatusEnum::FAILED->value)
                ->get();

            $summary['failed_found'] += $failed_items->count();

            $batch_summary = $this->retryFailedForDeleteItems($failed_items);
            $summary['queued'] += (int) ($batch_summary['queued'] ?? 0);
            $summary['errors'] += (int) ($batch_summary['errors'] ?? 0);

            $this->syncBatchStatusByItems((int) $batch->id);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductDeleteItem>  $records
     * @return array<string, int>
     */
    public function queueForDeleteItems(Collection $records, bool $allow_retry_from_failed = true): array
    {
        $summary = [
            'items_total'               => $records->count(),
            'deletes_queued'            => 0,
            'already_failed'            => 0,
            'already_queued_or_deleted' => 0,
            'skipped_missing_payload'   => 0,
            'errors'                    => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductDeleteItem) {
                continue;
            }

            $queued = $this->queueDeleteItem($record, $allow_retry_from_failed);
            $summary['deletes_queued'] += (int) ($queued['deletes_queued'] ?? 0);
            $summary['already_failed'] += (int) ($queued['already_failed'] ?? 0);
            $summary['already_queued_or_deleted'] += (int) ($queued['already_queued_or_deleted'] ?? 0);
            $summary['skipped_missing_payload'] += (int) ($queued['skipped_missing_payload'] ?? 0);
            $summary['errors'] += (int) ($queued['errors'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductDeleteItem>  $records
     * @return array<string, int>
     */
    public function retryFailedForDeleteItems(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
            'errors'       => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductDeleteItem) {
                continue;
            }

            if ($record->status !== ProductDeleteItemsStatusEnum::FAILED->value) {
                continue;
            }

            $summary['failed_found']++;

            $queued = $this->queueDeleteItem($record, true);
            $summary['queued'] += (int) ($queued['deletes_queued'] ?? 0);
            $summary['errors'] += (int) ($queued['errors'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<int>  $product_ids
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForProductIdsAndShopIds(
        array $product_ids,
        array $shop_ids,
        string $triggered_from,
        ?int $requested_by_user_id = null
    ): array {
        $summary = [
            'batch_id'                   => 0,
            'products_total'             => 0,
            'shops_total'                => count($shop_ids),
            'deletes_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_deleted'  => 0,
            'skipped_not_bound'          => 0,
            'skipped_without_external_id' => 0,
            'errors'                     => 0,
        ];

        $normalized_product_ids = $this->normalizePositiveIntList($product_ids);
        $normalized_shop_ids    = $this->normalizePositiveIntList($shop_ids);

        if ($normalized_product_ids === [] || $normalized_shop_ids === []) {
            return $summary;
        }

        $batch = ProductDeleteBatch::query()->create([
            'user_id'         => $requested_by_user_id,
            'source_type'     => ProductDeleteBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
            'source_name'     => 'Local products delete',
            'source_path'     => null,
            'status'          => ProductDeleteBatchesStatusEnum::PROCESSING->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'triggered_from'       => $triggered_from,
                'requested_by_user_id' => $requested_by_user_id,
                'delete_state'         => 'processing',
                'delete_started_at'    => now()->toDateTimeString(),
                'delete_finished_at'   => null,
            ],
            'started_at'  => now(),
            'finished_at' => null,
        ]);

        $summary['batch_id'] = (int) $batch->id;

        foreach ($normalized_product_ids as $product_id) {
            $summary['products_total']++;

            foreach ($normalized_shop_ids as $shop_id) {
                $queued_summary = $this->queueSingleProductShopDelete(
                    $batch,
                    (int) $product_id,
                    (int) $shop_id,
                    $requested_by_user_id
                );

                $summary['deletes_queued'] += (int) ($queued_summary['deletes_queued'] ?? 0);
                $summary['already_failed'] += (int) ($queued_summary['already_failed'] ?? 0);
                $summary['already_queued_or_deleted'] += (int) ($queued_summary['already_queued_or_deleted'] ?? 0);
                $summary['skipped_not_bound'] += (int) ($queued_summary['skipped_not_bound'] ?? 0);
                $summary['skipped_without_external_id'] += (int) ($queued_summary['skipped_without_external_id'] ?? 0);
                $summary['errors'] += (int) ($queued_summary['errors'] ?? 0);
            }
        }

        $this->syncBatchStatusByItems((int) $batch->id);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function queueSingleProductShopDelete(
        ProductDeleteBatch $batch,
        int $product_id,
        int $shop_id,
        ?int $requested_by_user_id = null
    ): array {
        $summary = [
            'deletes_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_deleted'   => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

        if ((int) $batch->id <= 0 || $product_id <= 0 || $shop_id <= 0) {
            $summary['errors']++;

            return $summary;
        }

        try {
            $product_shop = ProductShop::resolveLatestByProductAndShop($product_id, $shop_id);

            if (! $product_shop instanceof ProductShop) {
                $summary['skipped_not_bound']++;

                Log::channel('daily')->warning('Product delete skipped because product is not bound to selected shop', [
                    'product_delete_batch_id' => (int) $batch->id,
                    'product_id'              => $product_id,
                    'shop_id'                 => $shop_id,
                ]);

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                $summary['skipped_without_external_id']++;

                Log::channel('daily')->warning('Product delete skipped because external_product_id is missing', [
                    'product_delete_batch_id' => (int) $batch->id,
                    'product_id'              => $product_id,
                    'shop_id'                 => $shop_id,
                ]);

                return $summary;
            }

            $existing_item = ProductDeleteItem::resolveLatestDeleteOperationItem(
                (int) $batch->id,
                $product_id,
                $shop_id
            );

            if ($existing_item instanceof ProductDeleteItem) {
                if ($existing_item->status === ProductDeleteItemsStatusEnum::FAILED->value) {
                    $summary['already_failed']++;
                } else {
                    $summary['already_queued_or_deleted']++;
                }

                return $summary;
            }

            $delete_item = ProductDeleteItem::query()->create([
                'product_delete_batch_id' => (int) $batch->id,
                'product_id'              => $product_id,
                'payload'                 => [
                    'operation'            => 'delete',
                    'shop_id'              => $shop_id,
                    'external_product_id'  => $external_product_id,
                    'requested_product_id' => $product_id,
                    'requested_by_user_id' => $requested_by_user_id,
                ],
                'status'        => ProductDeleteItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductDeleteItemJob::dispatch((int) $delete_item->id);
            $summary['deletes_queued']++;
        } catch (\Throwable $exception) {
            $summary['errors']++;

            Log::channel('stack')->error('Failed to queue product delete item', [
                'product_delete_batch_id' => (int) $batch->id,
                'product_id'              => $product_id,
                'shop_id'                 => $shop_id,
                'error_msg'               => $exception->getMessage(),
                'file'                    => $exception->getFile(),
                'line'                    => $exception->getLine(),
            ]);
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function queueDeleteItem(ProductDeleteItem $item, bool $allow_retry_from_failed = false): array
    {
        $summary = [
            'deletes_queued'            => 0,
            'already_failed'            => 0,
            'already_queued_or_deleted' => 0,
            'skipped_missing_payload'   => 0,
            'errors'                    => 0,
        ];

        if ($item->status === ProductDeleteItemsStatusEnum::PROCESSING->value) {
            $summary['already_queued_or_deleted']++;

            return $summary;
        }

        if ($item->status === ProductDeleteItemsStatusEnum::DELETED->value) {
            $summary['already_queued_or_deleted']++;

            return $summary;
        }

        if ($item->status === ProductDeleteItemsStatusEnum::FAILED->value && ! $allow_retry_from_failed) {
            $summary['already_failed']++;

            return $summary;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($item->payload) ? $item->payload : [];
        $shop_id = (int) data_get($payload, 'shop_id', (int) data_get($payload, 'resolved_shop_id', 0));

        if ($shop_id <= 0) {
            $summary['skipped_missing_payload']++;

            $item->update([
                'status'        => ProductDeleteItemsStatusEnum::FAILED->value,
                'error_message' => 'Missing shop_id for delete operation',
                'processed_at'  => now(),
            ]);

            return $summary;
        }

        $external_product_id = (int) data_get($payload, 'external_product_id', (int) data_get($payload, 'resolved_external_product_id', 0));

        $item->update([
            'status'        => ProductDeleteItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
            'payload'       => [
                ...$payload,
                'operation'           => 'delete',
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id > 0 ? $external_product_id : null,
            ],
        ]);

        ProcessProductDeleteItemJob::dispatch((int) $item->id);
        $summary['deletes_queued']++;

        return $summary;
    }

    public function syncBatchStatusByItems(int $product_delete_batch_id): void
    {
        if ($product_delete_batch_id <= 0) {
            return;
        }

        $batch = ProductDeleteBatch::query()->find($product_delete_batch_id);
        if (! $batch instanceof ProductDeleteBatch) {
            return;
        }

        $status_counters = ProductDeleteItem::resolveStatusCountersByBatchId($product_delete_batch_id);
        $total_items     = $status_counters['total'];
        if ($total_items <= 0) {
            $batch->update([
                'status'          => ProductDeleteBatchesStatusEnum::FAILED->value,
                'total_items'     => 0,
                'processed_items' => 0,
                'failed_items'    => 0,
                'finished_at'     => now(),
                'options'         => [
                    ...($batch->options ?? []),
                    'delete_state'       => 'failed',
                    'delete_finished_at' => now()->toDateTimeString(),
                    'last_error'         => 'No delete items were queued',
                ],
            ]);

            return;
        }

        $processing_count = $status_counters['processing'];
        $deleted_count    = $status_counters['deleted'];
        $failed_count     = $status_counters['failed'];

        $status = match (true) {
            $processing_count > 0                         => ProductDeleteBatchesStatusEnum::PROCESSING->value,
            $deleted_count > 0 && $failed_count > 0       => ProductDeleteBatchesStatusEnum::PARTIAL_FAILED->value,
            $deleted_count > 0 && $failed_count === 0     => ProductDeleteBatchesStatusEnum::COMPLETED->value,
            $failed_count > 0                             => ProductDeleteBatchesStatusEnum::FAILED->value,
            default                                        => ProductDeleteBatchesStatusEnum::PROCESSING->value,
        };

        $batch->update([
            'status'          => $status,
            'total_items'     => $total_items,
            'processed_items' => $deleted_count + $failed_count,
            'failed_items'    => $failed_count,
            'finished_at'     => $processing_count > 0 ? null : now(),
            'options'         => [
                ...($batch->options ?? []),
                'delete_state'         => $processing_count > 0 ? 'processing' : 'finished',
                'delete_finished_at'   => $processing_count > 0 ? null : now()->toDateTimeString(),
            ],
        ]);
    }
}
