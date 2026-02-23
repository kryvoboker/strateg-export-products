<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Jobs\ProcessProductDeleteItemJob;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessProductDeleteItemJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_it_fails_delete_when_backup_payload_is_invalid(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example.test/api/backup' => Http::response(['status' => 'ok'], 200),
            'shop.example.test/api/delete' => Http::response(['status' => 'ok'], 200),
        ]);

        [$delete_item] = $this->seedDeleteItem();

        (new ProcessProductDeleteItemJob((int) $delete_item->id))->handle(app(\App\Supports\Services\Products\ProductDeleteQueueService::class));

        $delete_item->refresh();

        self::assertSame(ProductDeleteItemsStatusEnum::FAILED->value, (string) $delete_item->status);
        self::assertStringContainsString('Backup payload does not contain valid product id', (string) $delete_item->error_message);
        self::assertSame(0, ProductBackups::query()->count());

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/backup'));
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/api/delete'));
    }

    public function test_it_deletes_product_when_backup_payload_is_valid(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop.example.test/api/backup' => Http::response(['product_id' => 777, 'name' => 'Test'], 200),
            'shop.example.test/api/delete' => Http::response(['status' => 'ok'], 200),
        ]);

        [$delete_item, $batch] = $this->seedDeleteItem();

        (new ProcessProductDeleteItemJob((int) $delete_item->id))->handle(app(\App\Supports\Services\Products\ProductDeleteQueueService::class));

        $delete_item->refresh();
        $batch->refresh();

        self::assertSame(ProductDeleteItemsStatusEnum::DELETED->value, (string) $delete_item->status);
        self::assertNull($delete_item->error_message);
        self::assertSame(ProductDeleteBatchesStatusEnum::COMPLETED->value, (string) $batch->status);

        $backup = ProductBackups::query()->first();
        self::assertInstanceOf(ProductBackups::class, $backup);
        self::assertSame((int) $delete_item->product_id, (int) $backup->backupable_id);
        self::assertSame('external_api', (string) $backup->backup_source);
        self::assertSame('external_product_snapshot', (string) $backup->backup_kind);

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/backup'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/delete'));
    }

    /**
     * @return array{0: ProductDeleteItem, 1: ProductDeleteBatch}
     */
    private function seedDeleteItem(): array
    {
        $batch = ProductDeleteBatch::query()->create([
            'user_id'         => null,
            'source_type'     => 'Excel File',
            'source_name'     => 'delete.xlsx',
            'source_path'     => '/tmp/delete.xlsx',
            'status'          => ProductDeleteBatchesStatusEnum::PROCESSING->value,
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
            'part_api_url_export_prods' => null,
            'part_api_url_update_prods' => null,
            'part_api_url_restore_prods'=> null,
            'part_api_url_delete_prods' => '/api/delete',
            'is_active'                 => true,
            'options'                   => [
                'part_api_url_backup_prods' => '/api/backup',
                'api_timeout'               => 10,
            ],
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => 1,
            'family_ulid'            => '01K3MTKP37TZCVQKEVJ47X5NBH',
            'model'                  => 'MODEL-DELETE-1',
            'sku'                    => 'SKU-DELETE-1',
            'ean'                    => 'EAN-DELETE-1',
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

        $item = ProductDeleteItem::query()->create([
            'product_delete_batch_id' => (int) $batch->id,
            'product_id'              => (int) $product->id,
            'payload'                 => [
                'operation'           => 'delete',
                'shop_id'             => (int) $shop->id,
                'external_product_id' => 333,
            ],
            'status'        => ProductDeleteItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        return [$item, $batch];
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_backups');
        Schema::dropIfExists('product_delete_items');
        Schema::dropIfExists('product_delete_batches');
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
            $table->string('part_api_url_update_prods')->nullable();
            $table->string('part_api_url_restore_prods')->nullable();
            $table->string('part_api_url_delete_prods')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->char('family_ulid', 26)->nullable();
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

        Schema::create('product_delete_batches', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_name', 2000)->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 100)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_delete_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_delete_batch_id');
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
