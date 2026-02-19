<?php

declare(strict_types=1);

namespace Tests\Feature\ProductImports;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SingleProductExportToShopOneLiveTest extends TestCase
{
    public function test_it_exports_single_import_product_to_shop_one_via_real_api(): void
    {
        if (! $this->isLiveTestEnabled()) {
            self::markTestSkipped('Set LIVE_OPENCART_EXPORT_TEST=true to run live export test.');
        }

        if (config('database.default') !== 'pgsql' || ! Schema::hasTable('shops')) {
            self::markTestSkipped('Live export test must run on the main pgsql database with existing schema.');
        }

        $shop = Shop::query()->find(1);

        self::assertInstanceOf(Shop::class, $shop, 'Shop id=1 must exist for live export test');
        self::assertNotSame('', trim((string) $shop->part_api_url_export_prods), 'Shop id=1 must have part_api_url_export_prods');
        self::assertNotSame('', trim((string) $shop->part_api_url_login), 'Shop id=1 must have part_api_url_login');
        self::assertNotSame('', trim((string) $shop->api_token), 'Shop id=1 must have api_token');

        $timestamp = CarbonImmutable::now()->format('YmdHis');

        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value,
            'source_name'     => 'Live single export test '.$timestamp,
            'source_path'     => null,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'is_live_test' => true,
                'created_at'   => $timestamp,
            ],
            'started_at'  => now(),
            'finished_at' => null,
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => null,
            'payload'                 => [
                'source_meta' => [
                    'row_number'   => 1,
                    'is_live_test' => true,
                ],
            ],
            'status'        => ProductImportItemsStatusEnum::SUCCESSED->value,
            'error_message' => null,
            'processed_at'  => now(),
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => (int) $import_item->id,
            'family_ulid'            => (string) \Illuminate\Support\Str::ulid(),
            'marked_to_shop'         => null,
            'model'                  => 'LIVE-EXPORT-'.$timestamp,
            'sku'                    => 'LIVE-SKU-'.$timestamp,
            'ean'                    => substr('590'.$timestamp, 0, 14),
            'quantity'               => 3,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 123.45,
            'is_active'              => true,
            'date_available'         => now(),
            'date_added'             => now(),
        ]);

        $import_item->update([
            'product_id' => (int) $product->id,
        ]);

        ProductDescription::query()->create([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 1,
            'name'             => 'Live Export UA '.$timestamp,
            'description'      => 'Live export test description UA',
            'meta_title'       => 'Live Export UA '.$timestamp,
            'meta_description' => 'Live export test meta description UA',
            'meta_keywords'    => 'live,export,ua',
        ]);

        ProductDescription::query()->create([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 2,
            'name'             => 'Live Export EN '.$timestamp,
            'description'      => 'Live export test description EN',
            'meta_title'       => 'Live Export EN '.$timestamp,
            'meta_description' => 'Live export test meta description EN',
            'meta_keywords'    => 'live,export,en',
        ]);

        ProductDescription::query()->create([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 3,
            'name'             => 'Live Export RU '.$timestamp,
            'description'      => 'Live export test description RU',
            'meta_title'       => 'Live Export RU '.$timestamp,
            'meta_description' => 'Live export test meta description RU',
            'meta_keywords'    => 'live,export,ru',
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => (int) $product->id,
            'shop_id'                 => 1,
            'external_product_id'     => null,
        ]);

        $export_item = ProductExportItem::query()->create([
            'batchable_type' => ProductImportBatch::class,
            'batchable_id'   => (int) $batch->id,
            'product_id'     => (int) $product->id,
            'payload'        => [
                'shop_id'              => 1,
                'requested_product_id' => (int) $product->id,
                'target_product_id'    => (int) $product->id,
                'requested_by_user_id' => null,
                'is_live_test'         => true,
            ],
            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        (new ProcessProductExportItemJob((int) $export_item->id))->handle();

        $export_item->refresh();

        self::assertSame(
            ProductExportItemsStatusEnum::EXPORTED->value,
            (string) $export_item->status,
            (string) $export_item->error_message
        );

        $product_shop = ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->where('shop_id', 1)
            ->first();

        self::assertInstanceOf(ProductShop::class, $product_shop);
        self::assertGreaterThan(
            0,
            (int) ($product_shop->external_product_id ?? 0),
            'external_product_id must be saved after successful export'
        );

        self::assertSame(200, (int) (($export_item->payload['response_status'] ?? 0)));
    }

    private function isLiveTestEnabled(): bool
    {
        $flag = (string) env('LIVE_OPENCART_EXPORT_TEST', 'false');

        return in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
    }
}
