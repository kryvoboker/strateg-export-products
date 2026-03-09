<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Filament\Resources\ProductUpdates\Pages\ListProductUpdateBatches;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductUpdateQueueService
{
    /**
     * @param  Collection<int, ProductUpdateBatch>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForUpdateBatches(Collection $records, array $shop_ids, ?int $requested_by_user_id = null): array
    {
        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);

        $summary = [
            'batches_selected'            => $records->count(),
            'batches_skipped_processing'  => 0,
            'products_total'              => 0,
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductUpdateBatch) {
                continue;
            }

            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductUpdateItemsStatusEnum::SUCCESSED->value,
                    ProductUpdateItemsStatusEnum::NORMALIZED->value,
                    ProductUpdateItemsStatusEnum::NEW->value,
                ])
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            $summary['products_total'] += count($product_ids);

            foreach ($product_ids as $product_id) {
                foreach ($normalized_shop_ids as $shop_id) {
                    $queued_result = $this->queueUpdateForBatchProductShop(
                        (int) $batch->id,
                        $product_id,
                        $shop_id,
                        $requested_by_user_id,
                    );

                    $summary['updates_queued'] += (int) Arr::get($queued_result, 'updates_queued', 0);
                    $summary['already_failed'] += (int) Arr::get($queued_result, 'already_failed', 0);
                    $summary['already_queued_or_exported'] += (int) Arr::get($queued_result, 'already_queued_or_exported', 0);
                    $summary['skipped_not_bound'] += (int) Arr::get($queued_result, 'skipped_not_bound', 0);
                    $summary['skipped_without_external_id'] += (int) Arr::get($queued_result, 'skipped_without_external_id', 0);
                    $summary['errors'] += (int) Arr::get($queued_result, 'errors', 0);
                }
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductUpdateItem>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForUpdateItems(Collection $records, array $shop_ids, ?int $requested_by_user_id = null): array
    {
        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);

        $summary = [
            'items_selected'                => $records->count(),
            'items_skipped_processing'      => 0,
            'items_skipped_without_product' => 0,
            'products_total'                => 0,
            'updates_queued'                => 0,
            'already_failed'                => 0,
            'already_queued_or_exported'    => 0,
            'skipped_not_bound'             => 0,
            'skipped_without_external_id'   => 0,
            'errors'                        => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductUpdateItem) {
                continue;
            }

            if ($record->status === ProductUpdateItemsStatusEnum::PROCESSING->value) {
                $summary['items_skipped_processing']++;

                continue;
            }

            if ((int) ($record->product_id ?? 0) <= 0) {
                $summary['items_skipped_without_product']++;

                continue;
            }

            $item_summary = $this->queueForSingleUpdateItem($record, $normalized_shop_ids, $requested_by_user_id);
            $summary['products_total'] += (int) ($item_summary['products_total'] ?? 0);
            $summary['updates_queued'] += (int) ($item_summary['updates_queued'] ?? 0);
            $summary['already_failed'] += (int) ($item_summary['already_failed'] ?? 0);
            $summary['already_queued_or_exported'] += (int) ($item_summary['already_queued_or_exported'] ?? 0);
            $summary['skipped_not_bound'] += (int) ($item_summary['skipped_not_bound'] ?? 0);
            $summary['skipped_without_external_id'] += (int) ($item_summary['skipped_without_external_id'] ?? 0);
            $summary['errors'] += (int) ($item_summary['errors'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForSingleUpdateItem(ProductUpdateItem $record, array $shop_ids, ?int $requested_by_user_id = null): array
    {
        $summary = [
            'products_total'              => 0,
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_update_batch_id ?? 0);

        if ($product_id <= 0 || $batch_id <= 0) {
            return $summary;
        }

        $summary['products_total'] = 1;

        foreach ($this->normalizeShopIds($shop_ids) as $shop_id) {
            $queued_result = $this->queueUpdateForBatchProductShop(
                $batch_id,
                $product_id,
                $shop_id,
                $requested_by_user_id,
            );

            $summary['updates_queued'] += (int) Arr::get($queued_result, 'updates_queued', 0);
            $summary['already_failed'] += (int) Arr::get($queued_result, 'already_failed', 0);
            $summary['already_queued_or_exported'] += (int) Arr::get($queued_result, 'already_queued_or_exported', 0);
            $summary['skipped_not_bound'] += (int) Arr::get($queued_result, 'skipped_not_bound', 0);
            $summary['skipped_without_external_id'] += (int) Arr::get($queued_result, 'skipped_without_external_id', 0);
            $summary['errors'] += (int) Arr::get($queued_result, 'errors', 0);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductUpdateBatch>  $records
     * @return array<string, int>
     */
    public function retryFailedForUpdateBatches(Collection $records): array
    {
        $batch_ids = $records
            ->map(static fn (ProductUpdateBatch $batch): int => (int) $batch->id)
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

        $failed_update_items = ProductUpdateItem::query()
            ->whereIn('product_update_batch_id', $batch_ids)
            ->where('status', ProductUpdateItemsStatusEnum::FAILED->value)
            ->where('payload->operation', 'update')
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($failed_update_items as $failed_update_item) {
            $failed_update_item->update([
                'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductUpdateItemJob::dispatch((int) $failed_update_item->id);
            $queued++;
        }

        if ($queued > 0) {
            foreach ($batch_ids as $batch_id) {
                $this->markBatchAsUpdating($batch_id);
            }
        }

        return [
            'failed_found' => $failed_update_items->count(),
            'queued'       => $queued,
        ];
    }

    /**
     * @param  Collection<int, ProductUpdateItem>  $records
     * @return array<string, int>
     */
    public function retryFailedForUpdateItems(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductUpdateItem) {
                continue;
            }

            if ($record->status !== ProductUpdateItemsStatusEnum::FAILED->value) {
                continue;
            }

            $summary['failed_found']++;

            $queued_result = $this->retryFailedForSingleUpdateItem($record);
            $summary['queued'] += (int) ($queued_result['queued'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function retryFailedForSingleUpdateItem(ProductUpdateItem $record): array
    {
        if ($record->status !== ProductUpdateItemsStatusEnum::FAILED->value) {
            return [
                'failed_found' => 0,
                'queued'       => 0,
            ];
        }

        $record->update([
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        ProcessProductUpdateItemJob::dispatch((int) $record->id);

        $batch_id = (int) ($record->product_update_batch_id ?? 0);
        if ($batch_id > 0) {
            $this->markBatchAsUpdating($batch_id);
        }

        return [
            'failed_found' => 1,
            'queued'       => 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function queueUpdateForBatchProductShop(
        int $batch_id,
        int $product_id,
        int $shop_id,
        ?int $requested_by_user_id = null
    ): array {
        $summary = [
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

        try {
            $product_shop = ProductShop::resolveLatestByProductAndShop($product_id, $shop_id);

            if (! $product_shop instanceof ProductShop) {
                $summary['skipped_not_bound']++;

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                $summary['skipped_without_external_id']++;

                return $summary;
            }

            $existing_update_item = ProductUpdateItem::resolveLatestUpdateOperationItem($batch_id, $product_id, $shop_id);

            if ($existing_update_item instanceof ProductUpdateItem) {
                if ($existing_update_item->status === ProductUpdateItemsStatusEnum::FAILED->value) {
                    $summary['already_failed']++;
                } else {
                    $summary['already_queued_or_exported']++;
                }

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
                    'update_instructions'  => $this->resolvePreparedUpdateInstructions($batch_id, $product_id),
                ],
                'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductUpdateItemJob::dispatch((int) $update_item->id);
            $summary['updates_queued']++;

            $this->markBatchAsUpdating($batch_id);
        } catch (\Throwable $exception) {
            $summary['errors']++;

            Log::channel('stack')->error('Failed to queue product update item', [
                'service'    => self::class,
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'message'    => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
            ]);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePreparedUpdateInstructions(int $batch_id, int $product_id): array
    {
        if ($batch_id <= 0 || $product_id <= 0) {
            return [];
        }

        $prepared_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', $batch_id)
            ->where('product_id', $product_id)
            ->where('payload->operation', 'prepare_update')
            ->orderByDesc('id')
            ->first();

        if (! $prepared_item instanceof ProductUpdateItem) {
            return [];
        }

        $prepared_payload    = is_array($prepared_item->payload) ? $prepared_item->payload : [];
        $update_instructions = Arr::get($prepared_payload, 'update_instructions', []);

        if (! is_array($update_instructions)) {
            return [];
        }

        return ListProductUpdateBatches::sanitizeImmutableProductFieldsFromUpdateInstructions($update_instructions);
    }

    private function markBatchAsUpdating(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductUpdateBatch::query()->find($batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $batch->update([
            'status'  => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'options' => [
                ...($batch->options ?? []),
                'update_state'       => 'processing',
                'update_started_at'  => now()->toDateTimeString(),
                'update_finished_at' => null,
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
