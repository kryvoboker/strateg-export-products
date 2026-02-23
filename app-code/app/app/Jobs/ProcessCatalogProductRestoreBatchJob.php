<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessCatalogProductRestoreBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $product_ids
     * @param  list<int>  $shop_ids
     */
    public function __construct(
        public int $product_update_batch_id,
        public array $product_ids,
        public array $shop_ids,
        public ?int $requested_by_user_id = null
    ) {}

    public function handle(ProductBackupRestoreService $backup_restore_service): void
    {
        $batch = ProductUpdateBatch::query()->find($this->product_update_batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $product_ids = collect($this->product_ids)
            ->map(static fn ($product_id): int => (int) $product_id)
            ->filter(static fn (int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        $shop_ids = collect($this->shop_ids)
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $summary = [
            'product_update_batch_id'     => (int) $batch->id,
            'requested_by_user_id'        => $this->requested_by_user_id,
            'products_total'              => count($product_ids),
            'shops_total'                 => count($shop_ids),
            'queued'                      => 0,
            'already_exists'              => 0,
            'skipped_invalid_product'     => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'skipped_without_backup'      => 0,
            'skipped_invalid_backup'      => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        if ($product_ids === [] || $shop_ids === []) {
            $batch->update([
                'status'          => ProductUpdateBatchesStatusEnum::FAILED->value,
                'total_items'     => 0,
                'processed_items' => 0,
                'failed_items'    => 0,
                'finished_at'     => now(),
                'options'         => [
                    ...($batch->options ?? []),
                    'restore_state'         => 'finished',
                    'restore_total_items'   => 0,
                    'restore_success_items' => 0,
                    'restore_failed_items'  => 0,
                    'restore_finished_at'   => now()->toDateTimeString(),
                    'restore_summary'       => $summary,
                ],
            ]);

            Log::channel('stack')->warning('Catalog products restore batch skipped due to empty product_ids or shop_ids', $summary);

            return;
        }

        foreach ($product_ids as $product_id) {
            $product_exists = Product::query()->where('id', $product_id)->exists();
            if (! $product_exists) {
                $summary['skipped_invalid_product']++;

                continue;
            }

            foreach ($shop_ids as $shop_id) {
                try {
                    $existing_item = ProductUpdateItem::query()
                        ->where('product_update_batch_id', (int) $batch->id)
                        ->where('product_id', $product_id)
                        ->where('payload->operation', 'restore')
                        ->where('payload->shop_id', $shop_id)
                        ->orderByDesc('id')
                        ->first();

                    if ($existing_item instanceof ProductUpdateItem) {
                        $summary['already_exists']++;

                        continue;
                    }

                    $product_shop = ProductShop::query()
                        ->where('product_id', $product_id)
                        ->where('shop_id', $shop_id)
                        ->orderByDesc('id')
                        ->first();

                    if (! $product_shop instanceof ProductShop) {
                        $this->createFailedRestoreItem($batch, $product_id, $shop_id, 'Product is not bound to selected shop');
                        $summary['skipped_not_bound']++;
                        $summary['failed_created']++;

                        continue;
                    }

                    $external_product_id = (int) ($product_shop->external_product_id ?? 0);
                    if ($external_product_id <= 0) {
                        $this->createFailedRestoreItem($batch, $product_id, $shop_id, 'External product id is missing for restore');
                        $summary['skipped_without_external_id']++;
                        $summary['failed_created']++;

                        continue;
                    }

                    $backup = $backup_restore_service->resolveLatestValidExternalSnapshotForProductShop(
                        $product_id,
                        $shop_id,
                        $external_product_id
                    );

                    if ($backup === null) {
                        $raw_backup = ProductBackups::getLatestUnusedExternalSnapshotForProductShop(
                            $product_id,
                            $shop_id,
                            $external_product_id
                        );

                        if ($raw_backup === null) {
                            $this->createFailedRestoreItem($batch, $product_id, $shop_id, 'External unused backup is missing for restore');
                            $summary['skipped_without_backup']++;
                            $summary['failed_created']++;
                        } else {
                            $this->createFailedRestoreItem($batch, $product_id, $shop_id, 'External unused backup payload is invalid for restore');
                            $summary['skipped_invalid_backup']++;
                            $summary['failed_created']++;
                        }

                        continue;
                    }

                    $restore_item = ProductUpdateItem::query()->create([
                        'product_update_batch_id' => (int) $batch->id,
                        'product_id'              => $product_id,
                        'payload'                 => [
                            'operation'            => 'restore',
                            'shop_id'              => $shop_id,
                            'requested_product_id' => $product_id,
                            'target_product_id'    => $product_id,
                            'external_product_id'  => $external_product_id,
                            'backup_id'            => (int) $backup->id,
                            'requested_by_user_id' => $this->requested_by_user_id,
                            'triggered_from'       => 'catalog_products',
                        ],
                        'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                        'error_message' => null,
                        'processed_at'  => null,
                    ]);

                    ProcessCatalogProductRestoreItemJob::dispatch((int) $restore_item->id);
                    $summary['queued']++;
                } catch (QueryException $exception) {
                    $sql_state = (string) ($exception->errorInfo[0] ?? '');

                    if ($sql_state === '23505') {
                        $summary['already_exists']++;

                        continue;
                    }

                    Log::channel('stack')->error('Failed to create restore item in catalog restore batch', [
                        'batch_id'   => (int) $batch->id,
                        'product_id' => $product_id,
                        'shop_id'    => $shop_id,
                        'error_msg'  => $exception->getMessage(),
                    ]);

                    $summary['errors']++;
                } catch (Throwable $exception) {
                    Log::channel('stack')->error('Failed to queue restore item in catalog restore batch', [
                        'batch_id'   => (int) $batch->id,
                        'product_id' => $product_id,
                        'shop_id'    => $shop_id,
                        'error_msg'  => $exception->getMessage(),
                        'file'       => $exception->getFile(),
                        'line'       => $exception->getLine(),
                    ]);

                    $summary['errors']++;
                }
            }
        }

        $total_items = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('payload->operation', 'restore')
            ->count();

        $batch->update([
            'status'          => $total_items > 0 ? ProductUpdateBatchesStatusEnum::PROCESSING->value : ProductUpdateBatchesStatusEnum::FAILED->value,
            'total_items'     => $total_items,
            'processed_items' => 0,
            'failed_items'    => 0,
            'started_at'      => now(),
            'finished_at'     => $total_items > 0 ? null : now(),
            'options'         => [
                ...($batch->options ?? []),
                'restore_state'         => $total_items > 0 ? 'processing' : 'finished',
                'restore_total_items'   => $total_items,
                'restore_success_items' => 0,
                'restore_failed_items'  => 0,
                'restore_finished_at'   => $total_items > 0 ? null : now()->toDateTimeString(),
                'restore_summary'       => $summary,
            ],
        ]);

        Log::channel('daily')->info('Catalog products restore batch prepared', $summary);
    }

    private function createFailedRestoreItem(ProductUpdateBatch $batch, int $product_id, int $shop_id, string $error_message): void
    {
        Log::channel('daily')->warning('Catalog product restore skipped', [
            'batch_id'   => (int) $batch->id,
            'product_id' => $product_id,
            'shop_id'    => $shop_id,
            'error_msg'  => $error_message,
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => $product_id,
            'payload'                 => [
                'operation'            => 'restore',
                'shop_id'              => $shop_id,
                'requested_product_id' => $product_id,
                'target_product_id'    => $product_id,
                'requested_by_user_id' => $this->requested_by_user_id,
                'triggered_from'       => 'catalog_products',
            ],
            'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at'  => now(),
        ]);
    }
}
