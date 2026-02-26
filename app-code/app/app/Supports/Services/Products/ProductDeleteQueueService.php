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
use App\Supports\Services\Products\Traits\NormalizesProductServiceInput;
use Illuminate\Support\Facades\Log;

class ProductDeleteQueueService
{
    use NormalizesProductServiceInput;

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
