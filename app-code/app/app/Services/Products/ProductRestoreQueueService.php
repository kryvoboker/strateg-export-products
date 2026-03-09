<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessProductRestoreBatchJob;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\Updates\ProductUpdateBatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class ProductRestoreQueueService
{
    /**
     * @param  iterable<mixed>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function queueForCatalogProducts(iterable $records, array $shop_ids, ?int $requested_by_user_id = null): array
    {
        $normalized_shop_ids = $this->normalizePositiveIntList($shop_ids);

        $summary = [
            'products_total'         => 0,
            'shops_total'            => count($normalized_shop_ids),
            'restore_batches_queued' => 0,
            'errors'                 => 0,
        ];

        $product_ids = collect($records)
            ->filter(static fn ($record): bool => $record instanceof Product)
            ->map(static fn (Product $record): int => (int) $record->id)
            ->filter(static fn (int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        $summary['products_total'] = count($product_ids);

        if ($product_ids === [] || $normalized_shop_ids === []) {
            return $summary;
        }

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
                $normalized_shop_ids,
                $requested_by_user_id
            );

            $summary['restore_batches_queued']++;
        } catch (Throwable $exception) {
            logger()->channel('stack')->error('Failed to queue catalog products restore batch', [
                'service'              => self::class,
                'product_ids'          => $product_ids,
                'shop_ids'             => $normalized_shop_ids,
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
     * @param  iterable<mixed>  $records
     * @return array<string, int>
     */
    public function queueForImportItems(iterable $records, ?int $requested_by_user_id = null): array
    {
        $record_ids = collect($records)
            ->filter(static fn ($record): bool => $record instanceof ProductImportItem)
            ->map(static fn (ProductImportItem $record): int => (int) $record->id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $summary = [
            'items_selected'         => count($record_ids),
            'restore_batches_queued' => 0,
            'errors'                 => 0,
        ];

        if ($record_ids === []) {
            return $summary;
        }

        try {
            ProcessProductRestoreBatchJob::dispatch($record_ids, $requested_by_user_id);
            $summary['restore_batches_queued']++;
        } catch (Throwable $exception) {
            logger()->channel('stack')->error('Failed to queue import items restore batch', [
                'service'              => self::class,
                'product_import_item_ids' => $record_ids,
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
     * @param  array<int|string, mixed>  $values
     * @return list<int>
     */
    private function normalizePositiveIntList(array $values): array
    {
        return collect($values)
            ->map(static fn ($value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values()
            ->all();
    }
}
