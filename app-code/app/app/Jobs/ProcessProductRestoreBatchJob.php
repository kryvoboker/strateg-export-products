<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Updates\ProductBackups;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProductRestoreBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $product_import_item_ids
     */
    public function __construct(public array $product_import_item_ids, public ?int $requested_by_user_id = null) {}

    public function handle(ProductBackupRestoreService $restore_service): void
    {
        $item_ids = collect($this->product_import_item_ids)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($item_ids === []) {
            Log::channel('stack')->warning('Bulk product restore skipped because item ids are empty', [
                'requested_by_user_id' => $this->requested_by_user_id,
            ]);

            return;
        }

        $items = ProductImportItem::query()
            ->whereIn('id', $item_ids)
            ->orderBy('id')
            ->get(['id', 'product_id', 'product_import_batch_id']);

        $summary = [
            'items_selected'                  => count($item_ids),
            'items_found'                     => $items->count(),
            'items_skipped_without_product'   => 0,
            'items_skipped_duplicate_product' => 0,
            'items_skipped_no_backup'         => 0,
            'items_skipped_invalid_backup'    => 0,
            'items_restored'                  => 0,
            'items_failed'                    => 0,
        ];

        $processed_product_ids = [];

        foreach ($items as $item) {
            $product_id = (int) ($item->product_id ?? 0);
            if ($product_id <= 0) {
                $summary['items_skipped_without_product']++;

                continue;
            }

            if (in_array($product_id, $processed_product_ids, true)) {
                $summary['items_skipped_duplicate_product']++;

                continue;
            }

            $processed_product_ids[] = $product_id;

            if (! ProductBackups::hasUnusedLocalSnapshotForProduct($product_id)) {
                $summary['items_skipped_no_backup']++;

                Log::channel('daily')->warning('Bulk product restore skipped: no available unused local backup found', [
                    'product_import_item_id'  => (int) $item->id,
                    'product_import_batch_id' => (int) ($item->product_import_batch_id ?? 0),
                    'product_id'              => $product_id,
                    'requested_by_user_id'    => $this->requested_by_user_id,
                ]);

                continue;
            }

            $backup = $restore_service->resolveLatestValidLocalSnapshotForProduct($product_id);
            if (! $backup instanceof ProductBackups) {
                $summary['items_skipped_invalid_backup']++;

                Log::channel('daily')->warning('Bulk product restore skipped: no valid unused local backup found', [
                    'product_import_item_id'  => (int) $item->id,
                    'product_import_batch_id' => (int) ($item->product_import_batch_id ?? 0),
                    'product_id'              => $product_id,
                    'requested_by_user_id'    => $this->requested_by_user_id,
                ]);

                continue;
            }

            try {
                $restore_service->restoreFromBackup($backup);
                $summary['items_restored']++;
            } catch (Throwable $exception) {
                $summary['items_failed']++;

                Log::channel('stack')->error('Bulk product restore failed for item', [
                    'product_import_item_id'  => (int) $item->id,
                    'product_import_batch_id' => (int) ($item->product_import_batch_id ?? 0),
                    'product_id'              => $product_id,
                    'requested_by_user_id'    => $this->requested_by_user_id,
                    'error_msg'               => $exception->getMessage(),
                    'file'                    => $exception->getFile(),
                    'line'                    => $exception->getLine(),
                    'exception'               => $exception,
                ]);
            }
        }

        Log::channel('daily')->info('Bulk product restore completed', [
            ...$summary,
            'requested_by_user_id' => $this->requested_by_user_id,
        ]);
    }
}
