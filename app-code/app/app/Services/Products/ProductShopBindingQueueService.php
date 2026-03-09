<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\ProductShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductShopBindingQueueService
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
            'jobs_queued'                => 0,
            'skipped_already_bound'      => 0,
        ];

        Log::channel('daily')->info('Queueing product shop bindings for import batches started', [
            'service'     => self::class,
            'action_type' => 'import_batches',
            'batch_ids'   => $records->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
            'shop_ids'    => $normalized_shop_ids,
            'user_id'     => $user_id,
        ]);

        foreach ($records as $batch) {
            if (! $batch instanceof ProductImportBatch) {
                continue;
            }

            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $batch_product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            $summary['products_total'] += count($batch_product_ids);

            foreach ($batch_product_ids as $product_id) {
                $source_item = $batch->items()
                    ->where('product_id', $product_id)
                    ->orderBy('id')
                    ->first();

                $source_payload = $source_item instanceof ProductImportItem && is_array($source_item->payload)
                    ? $source_item->payload
                    : [];

                $job_queued = $this->queueSingleProductBinding(
                    $product_id,
                    (int) $batch->id,
                    $normalized_shop_ids,
                    $source_payload,
                    $user_id,
                );

                if ($job_queued === true) {
                    $summary['jobs_queued']++;

                    continue;
                }

                $summary['skipped_already_bound']++;
            }
        }

        Log::channel('daily')->info('Queueing product shop bindings for import batches finished', [
            'service'     => self::class,
            'action_type' => 'import_batches',
            'shop_ids'    => $normalized_shop_ids,
            'user_id'     => $user_id,
            'summary'     => $summary,
        ]);

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
            'jobs_queued'                   => 0,
            'items_skipped_already_bound'   => 0,
            'products_skipped'              => 0,
        ];

        Log::channel('daily')->info('Queueing product shop bindings for import items started', [
            'service'     => self::class,
            'action_type' => 'import_items',
            'item_ids'    => $records->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all(),
            'shop_ids'    => $normalized_shop_ids,
            'user_id'     => $user_id,
        ]);

        $processed_keys = [];

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
            if ($batch_id <= 0) {
                $summary['products_skipped']++;

                continue;
            }

            $record_key = $batch_id.':'.$product_id;
            if (in_array($record_key, $processed_keys, true)) {
                continue;
            }

            $processed_keys[] = $record_key;
            $summary['products_total']++;

            $source_payload = is_array($record->payload) ? $record->payload : [];
            $job_queued = $this->queueSingleProductBinding(
                $product_id,
                $batch_id,
                $normalized_shop_ids,
                $source_payload,
                $user_id,
            );

            if ($job_queued === true) {
                $summary['jobs_queued']++;

                continue;
            }

            $summary['items_skipped_already_bound']++;
        }

        Log::channel('daily')->info('Queueing product shop bindings for import items finished', [
            'service'     => self::class,
            'action_type' => 'import_items',
            'shop_ids'    => $normalized_shop_ids,
            'user_id'     => $user_id,
            'summary'     => $summary,
        ]);

        return $summary;
    }

    /**
     * @param  list<int>  $shop_ids
     * @param  array<string, mixed>  $source_payload
     */
    private function queueSingleProductBinding(
        int $product_id,
        int $batch_id,
        array $shop_ids,
        array $source_payload,
        ?int $user_id,
    ): bool {
        foreach ($shop_ids as $shop_id) {
            if (ProductShop::isProductBoundToShop($product_id, $shop_id)) {
                continue;
            }

            ProcessProductShopBindingJob::dispatch(
                $product_id,
                $shop_ids,
                $batch_id,
                $source_payload,
                $user_id,
            );

            return true;
        }

        return false;
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
