<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessProductUpdateItemJobBackupFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_it_stops_update_flow_when_backup_request_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example.test/api/backup' => Http::response(['error' => 'backup failed'], 500),
            'shop.example.test/api/update' => Http::response(['status' => 'ok'], 200),
        ]);

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => null,
            'source_type'     => 'excel_file',
            'source_name'     => 'test.xlsx',
            'source_path'     => '/tmp/test.xlsx',
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        $shop = Shop::query()->create([
            'name'                      => 'Test Shop',
            'type'                      => 'custom',
            'base_url'                  => 'http://shop.example.test',
            'api_url'                   => null,
            'api_token'                 => null,
            'part_api_url_login'        => null,
            'part_api_url_export_prods' => '/api/update',
            'is_active'                 => true,
            'options'                   => [
                'part_api_url_backup_prods' => '/api/backup',
                'part_api_url_update_prods' => '/api/update',
                'api_timeout'               => 10,
            ],
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => 1,
            'model'                  => 'MODEL-1',
            'sku'                    => 'SKU-1',
            'ean'                    => 'EAN-1',
            'quantity'               => 5,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 333,
        ]);

        $update_item = ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => (int) $product->id,
            'payload'                 => [
                'operation'           => 'update',
                'shop_id'             => (int) $shop->id,
                'update_instructions' => [
                    'Product' => [
                        'fields' => [
                            'sku' => ['action' => 'set', 'value' => 'SKU-UPDATED'],
                        ],
                    ],
                ],
            ],
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        (new ProcessProductUpdateItemJob((int) $update_item->id))->handle();

        $update_item->refresh();

        self::assertSame(ProductUpdateItemsStatusEnum::FAILED->value, $update_item->status);
        self::assertStringContainsString('Backup API failed', (string) $update_item->error_message);
        self::assertSame(0, ProductBackups::query()->count());
        self::assertSame(1, ProductExportItem::query()->count());

        $product_export_item = ProductExportItem::query()->first();
        self::assertInstanceOf(ProductExportItem::class, $product_export_item);
        self::assertSame(ProductUpdateBatch::class, (string) $product_export_item->batchable_type);
        self::assertSame((int) $batch->id, (int) $product_export_item->batchable_id);
        self::assertSame(ProductExportItemsStatusEnum::FAILED->value, (string) $product_export_item->status);
        self::assertStringContainsString('Backup API failed', (string) $product_export_item->error_message);

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/backup'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/api/update'));

    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_backups');
        Schema::dropIfExists('product_update_items');
        Schema::dropIfExists('product_update_batches');
        Schema::dropIfExists('product_export_items');
        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('products');
        Schema::dropIfExists('shops');

        Schema::create('shops', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50);
            $table->string('base_url');
            $table->string('api_url')->nullable();
            $table->string('api_token', 500)->nullable();
            $table->string('part_api_url_login')->nullable();
            $table->string('part_api_url_export_prods')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->string('marked_to_shop')->nullable();
            $table->string('model')->nullable();
            $table->string('sku')->nullable();
            $table->string('ean')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('minimum')->default(1);
            $table->string('image')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamp('date_available')->nullable();
            $table->timestamp('date_added')->nullable();
            $table->timestamps();
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });

        Schema::create('product_update_batches', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_type', 100)->default('excel_file');
            $table->string('source_name', 2000)->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 100)->default('new');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_update_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_update_batch_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 100)->nullable()->default('new');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_export_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('batchable_type');
            $table->unsignedBigInteger('batchable_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_backups', static function (Blueprint $table): void {
            $table->id();
            $table->string('backupable_type')->nullable();
            $table->unsignedBigInteger('backupable_id')->nullable();
            $table->string('backup_source', 100)->nullable();
            $table->string('backup_kind', 150)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });
    }
}
