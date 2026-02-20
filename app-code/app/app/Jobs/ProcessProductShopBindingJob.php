<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Supports\Services\Catalog\ProductShopBindingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProductShopBindingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 120;

    /**
     * @param  array<string, mixed>  $source_payload
     */
    public function __construct(
        public int $product_id,
        public int $shop_id,
        public int $product_import_batch_id = 0,
        public array $source_payload = [],
        public ?int $requested_by_user_id = null,
    ) {}

    public function uniqueId(): string
    {
        return 'product-shop-binding:'.$this->shop_id.':'.$this->product_id;
    }

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
            $bound             = (int) ($bind_result['bound'] ?? 0);
            $duplicated        = (int) ($bind_result['duplicated'] ?? 0);
            $catalog_sync_summary = is_array($bind_result['catalog_sync_summary'] ?? null)
                ? $bind_result['catalog_sync_summary']
                : [
                    'categories_relinked'   => 0,
                    'categories_assigned'   => 0,
                    'categories_created'    => 0,
                    'categories_reused'     => 0,
                    'attributes_relinked'   => 0,
                    'attributes_assigned'   => 0,
                    'attributes_created'    => 0,
                    'attributes_reused'     => 0,
                    'manufacturer_relinked' => 0,
                    'manufacturers_assigned' => 0,
                    'manufacturers_created' => 0,
                    'manufacturers_reused'  => 0,
                    'brand_relinked'        => 0,
                    'brands_assigned'       => 0,
                    'brands_created'        => 0,
                    'brands_reused'         => 0,
                ];
            $catalog_strategy_summary = $this->buildCatalogStrategySummary($catalog_sync_summary);

            if ($target_product_id > 0 && $bound === 0 && $duplicated === 0) {
                Log::channel('daily')->info('Product shop binding skipped because already bound', [
                    'source_product_id'       => $this->product_id,
                    'target_product_id'       => $target_product_id,
                    'shop_id'                 => $this->shop_id,
                    'product_import_batch_id' => $this->product_import_batch_id,
                    'requested_by_user_id'    => $this->requested_by_user_id,
                ]);
            }

            Log::channel('daily')->info('Product shop binding processed', [
                'source_product_id'       => $this->product_id,
                'target_product_id'       => $target_product_id,
                'shop_id'                 => $this->shop_id,
                'product_import_batch_id' => $this->product_import_batch_id,
                'requested_by_user_id'    => $this->requested_by_user_id,
                'bound'                   => $bound,
                'duplicated'              => $duplicated,
                'catalog_sync_summary'    => $catalog_sync_summary,
                'catalog_strategy_summary' => $catalog_strategy_summary,
            ]);

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
                'product_id'              => $this->product_id,
                'shop_id'                 => $this->shop_id,
                'product_import_batch_id' => $this->product_import_batch_id,
                'requested_by_user_id'    => $this->requested_by_user_id,
                'ai_translation_enabled'  => (bool) config('app.ai_translation_enabled', true),
                'message'                 => $exception->getMessage(),
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

    /**
     * @param  array<string, mixed>  $catalog_sync_summary
     * @return array{assigned:int,reused:int,duplicated:int}
     */
    private function buildCatalogStrategySummary(array $catalog_sync_summary): array
    {
        return [
            'assigned' => (int) ($catalog_sync_summary['categories_assigned'] ?? 0)
                + (int) ($catalog_sync_summary['attributes_assigned'] ?? 0)
                + (int) ($catalog_sync_summary['manufacturers_assigned'] ?? 0)
                + (int) ($catalog_sync_summary['brands_assigned'] ?? 0),
            'reused'   => (int) ($catalog_sync_summary['categories_reused'] ?? 0)
                + (int) ($catalog_sync_summary['attributes_reused'] ?? 0)
                + (int) ($catalog_sync_summary['manufacturers_reused'] ?? 0)
                + (int) ($catalog_sync_summary['brands_reused'] ?? 0),
            'duplicated' => (int) ($catalog_sync_summary['categories_created'] ?? 0)
                + (int) ($catalog_sync_summary['attributes_created'] ?? 0)
                + (int) ($catalog_sync_summary['manufacturers_created'] ?? 0)
                + (int) ($catalog_sync_summary['brands_created'] ?? 0),
        ];
    }
}
