<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessCatalogProductRestoreItemJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_it_restores_product_via_api_using_valid_external_backup(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example.test/api/restore' => Http::response(['status' => 'ok'], 200),
        ]);

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => null,
            'source_type'     => 'Local Products',
            'source_name'     => 'catalog restore',
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        $shop = Shop::query()->create([
            'name'                       => 'Test Shop',
            'type'                       => 'custom',
            'base_url'                   => 'http://shop.example.test',
            'api_url'                    => null,
            'api_token'                  => null,
            'part_api_url_login'         => null,
            'part_api_url_export_prods'  => '/api/export',
            'part_api_url_restore_prods' => '/api/restore',
            'is_active'                  => true,
            'options'                    => [
                'product_restore_endpoint' => '/api/restore',
            ],
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => 10,
            'model'                  => 'MODEL-RESTORE-1',
            'sku'                    => 'SKU-RESTORE-1',
            'ean'                    => 'EAN-RESTORE-1',
            'quantity'               => 10,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 100,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 555,
        ]);

        $backup = ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => (int) $shop->id,
            'external_product_id' => '555',
            'payload'             => [
                'id'   => 555,
                'data' => [
                    'name' => 'Remote product snapshot',
                ],
            ],
            'is_used' => false,
        ]);

        $update_item = ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => (int) $product->id,
            'payload'                 => [
                'operation'           => 'restore',
                'shop_id'             => (int) $shop->id,
                'external_product_id' => 555,
            ],
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        (new ProcessCatalogProductRestoreItemJob((int) $update_item->id))->handle(app(ProductBackupRestoreService::class));

        $update_item->refresh();
        $backup->refresh();

        self::assertSame(ProductUpdateItemsStatusEnum::SUCCESSED->value, (string) $update_item->status);
        self::assertNull($update_item->error_message);
        self::assertTrue((bool) $backup->is_used);

        $product_export_item = ProductExportItem::query()->first();
        self::assertInstanceOf(ProductExportItem::class, $product_export_item);
        self::assertSame(ProductExportItemsStatusEnum::EXPORTED->value, (string) $product_export_item->status);
        self::assertSame(ProductUpdateBatch::class, (string) $product_export_item->batchable_type);
        self::assertSame((int) $batch->id, (int) $product_export_item->batchable_id);

        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), '/api/restore')) {
                return false;
            }

            $data = $request->data();

            return ($data['operation'] ?? null) === 'restore'
                && (int) ($data['external_product_id'] ?? 0) === 555
                && array_key_exists('backup_payload', $data);
        });
    }

    public function test_it_marks_item_as_failed_when_external_backup_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => null,
            'source_type'     => 'Local Products',
            'source_name'     => 'catalog restore',
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        $shop = Shop::query()->create([
            'name'                       => 'Test Shop',
            'type'                       => 'custom',
            'base_url'                   => 'http://shop.example.test',
            'api_url'                    => null,
            'api_token'                  => null,
            'part_api_url_login'         => null,
            'part_api_url_export_prods'  => '/api/export',
            'part_api_url_restore_prods' => '/api/restore',
            'is_active'                  => true,
            'options'                    => [
                'product_restore_endpoint' => '/api/restore',
            ],
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => 20,
            'model'                  => 'MODEL-RESTORE-2',
            'sku'                    => 'SKU-RESTORE-2',
            'ean'                    => 'EAN-RESTORE-2',
            'quantity'               => 7,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 120,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 777,
        ]);

        $update_item = ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => (int) $product->id,
            'payload'                 => [
                'operation'           => 'restore',
                'shop_id'             => (int) $shop->id,
                'external_product_id' => 777,
            ],
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        (new ProcessCatalogProductRestoreItemJob((int) $update_item->id))->handle(app(ProductBackupRestoreService::class));

        $update_item->refresh();

        self::assertSame(ProductUpdateItemsStatusEnum::FAILED->value, (string) $update_item->status);
        self::assertStringContainsString('Valid external backup', (string) $update_item->error_message);

        Http::assertNothingSent();
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_backups');
        Schema::dropIfExists('product_export_items');
        Schema::dropIfExists('product_update_items');
        Schema::dropIfExists('product_update_batches');
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
            $table->string('part_api_url_restore_prods')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->string('family_ulid', 26)->nullable();
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
