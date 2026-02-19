<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessCatalogProductRestoreItemJob;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessCatalogProductRestoreBatchJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_it_queues_only_products_with_valid_external_backups_and_creates_failed_items_for_others(): void
    {
        Queue::fake();

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => null,
            'source_type'     => 'Local Products',
            'source_name'     => 'catalog restore',
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        $shop = Shop::query()->create([
            'name'                       => 'Restore Shop',
            'type'                       => 'custom',
            'base_url'                   => 'http://shop.example.test',
            'api_url'                    => null,
            'api_token'                  => null,
            'part_api_url_login'         => null,
            'part_api_url_export_prods'  => '/api/export',
            'part_api_url_restore_prods' => '/api/restore',
            'is_active'                  => true,
            'options'                    => [],
        ]);

        $product_valid = Product::query()->create([
            'product_import_item_id' => 1,
            'model'                  => 'MODEL-VALID',
            'sku'                    => 'SKU-VALID',
            'ean'                    => 'EAN-VALID',
            'quantity'               => 1,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        $product_missing_backup = Product::query()->create([
            'product_import_item_id' => 2,
            'model'                  => 'MODEL-MISSING',
            'sku'                    => 'SKU-MISSING',
            'ean'                    => 'EAN-MISSING',
            'quantity'               => 1,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        $product_invalid_backup = Product::query()->create([
            'product_import_item_id' => 3,
            'model'                  => 'MODEL-INVALID',
            'sku'                    => 'SKU-INVALID',
            'ean'                    => 'EAN-INVALID',
            'quantity'               => 1,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product_valid->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 111,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product_missing_backup->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 222,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => null,
            'product_id'              => (int) $product_invalid_backup->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => 333,
        ]);

        ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product_valid->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => (int) $shop->id,
            'external_product_id' => '111',
            'payload'             => [
                'id'       => 111,
                'snapshot' => ['name' => 'valid backup'],
            ],
            'is_used' => false,
        ]);

        ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product_invalid_backup->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => (int) $shop->id,
            'external_product_id' => '333',
            'payload'             => [],
            'is_used'             => false,
        ]);

        $job = new ProcessCatalogProductRestoreBatchJob(
            (int) $batch->id,
            [(int) $product_valid->id, (int) $product_missing_backup->id, (int) $product_invalid_backup->id],
            [(int) $shop->id],
            1
        );

        $job->handle(app(ProductBackupRestoreService::class));

        Queue::assertPushed(ProcessCatalogProductRestoreItemJob::class, 1);

        $queued_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('product_id', (int) $product_valid->id)
            ->first();

        self::assertInstanceOf(ProductUpdateItem::class, $queued_item);
        self::assertSame(ProductUpdateItemsStatusEnum::PROCESSING->value, (string) $queued_item->status);

        $missing_backup_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('product_id', (int) $product_missing_backup->id)
            ->first();

        self::assertInstanceOf(ProductUpdateItem::class, $missing_backup_item);
        self::assertSame(ProductUpdateItemsStatusEnum::FAILED->value, (string) $missing_backup_item->status);
        self::assertStringContainsString('backup is missing', Str::lower((string) $missing_backup_item->error_message));

        $invalid_backup_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('product_id', (int) $product_invalid_backup->id)
            ->first();

        self::assertInstanceOf(ProductUpdateItem::class, $invalid_backup_item);
        self::assertSame(ProductUpdateItemsStatusEnum::FAILED->value, (string) $invalid_backup_item->status);
        self::assertStringContainsString('payload is invalid', Str::lower((string) $invalid_backup_item->error_message));
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_backups');
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
