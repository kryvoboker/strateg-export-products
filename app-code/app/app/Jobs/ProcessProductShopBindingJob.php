<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Supports\Services\Catalog\ProductShopBindingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProductShopBindingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<string, mixed> $source_payload
     */
    public function __construct(
        public int $product_id,
        public int $shop_id,
        public int $product_import_batch_id = 0,
        public array $source_payload = [],
        public ?int $requested_by_user_id = null,
    ) {}

    public function handle(ProductShopBindingService $product_shop_binding_service): void
    {
        try {
            $bind_result = $product_shop_binding_service->bindProductToShopAndReturnTargetProduct(
                $this->product_id,
                $this->shop_id,
                $this->product_import_batch_id,
                $this->source_payload,
            );

            $target_product_id = (int) ($bind_result['product_id'] ?? 0);

            if ($this->product_import_batch_id > 0 && $target_product_id > 0) {
                ProductImportItem::ensureBatchProductItem(
                    $this->product_import_batch_id,
                    $target_product_id,
                    $this->source_payload,
                );

                $this->syncBatchTotalItems($this->product_import_batch_id);
            }
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Product shop binding job failed', [
                'product_id' => $this->product_id,
                'shop_id' => $this->shop_id,
                'product_import_batch_id' => $this->product_import_batch_id,
                'requested_by_user_id' => $this->requested_by_user_id,
                'message' => $exception->getMessage(),
            ]);

            if ($this->product_import_batch_id > 0) {
                $this->syncBatchTotalItems($this->product_import_batch_id);
            }
        }
    }

    private function syncBatchTotalItems(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $total_items = ProductImportItem::query()
            ->where('product_import_batch_id', $batch_id)
            ->count();

        ProductImportBatch::query()
            ->whereKey($batch_id)
            ->update([
                'total_items' => $total_items,
            ]);
    }
}
