<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessProductRestoreBatchJob;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Services\Products\ProductRestoreQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductRestoreQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('product_update_batches');
        Schema::dropIfExists('products');

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->string('family_ulid')->nullable();
            $table->unsignedBigInteger('marked_to_shop')->nullable();
            $table->string('model')->nullable();
            $table->string('sku')->nullable();
            $table->string('ean')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('minimum')->default(0);
            $table->string('image')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('date_available')->nullable();
            $table->timestamp('date_added')->nullable();
            $table->timestamps();
        });

        Schema::create('product_import_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_update_batches', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_path')->nullable();
            $table->string('status')->nullable();
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_queues_catalog_restore_batch(): void
    {
        Queue::fake();

        $product = Product::query()->create([
            'model' => 'RESTORE-501',
        ]);

        $summary = app(ProductRestoreQueueService::class)->queueForCatalogProducts(
            Product::query()->whereKey((int) $product->id)->get(),
            [1, 2],
            null,
        );

        self::assertSame(1, $summary['products_total']);
        self::assertSame(2, $summary['shops_total']);
        self::assertSame(1, $summary['restore_batches_queued']);

        $batch = ProductUpdateBatch::query()->firstOrFail();

        self::assertSame(ProductUpdateBatchesStatusEnum::PROCESSING->value, $batch->status);
        self::assertSame('catalog_products_restore', $batch->options['triggered_from'] ?? null);

        Queue::assertPushed(ProcessCatalogProductRestoreBatchJob::class, static function (ProcessCatalogProductRestoreBatchJob $job) use ($batch, $product): bool {
            return $job->product_update_batch_id === (int) $batch->id
                && $job->product_ids === [(int) $product->id]
                && $job->shop_ids === [1, 2];
        });
    }

    public function test_it_queues_import_items_restore_batch(): void
    {
        Queue::fake();

        $first = ProductImportItem::query()->create(['product_import_batch_id' => 10, 'product_id' => 501]);
        $second = ProductImportItem::query()->create(['product_import_batch_id' => 10, 'product_id' => 502]);

        $summary = app(ProductRestoreQueueService::class)->queueForImportItems(
            ProductImportItem::query()->whereIn('id', [(int) $first->id, (int) $second->id])->get(),
            null,
        );

        self::assertSame(2, $summary['items_selected']);
        self::assertSame(1, $summary['restore_batches_queued']);
        self::assertSame(0, $summary['errors']);

        Queue::assertPushed(ProcessProductRestoreBatchJob::class, static function (ProcessProductRestoreBatchJob $job) use ($first, $second): bool {
            return $job->product_import_item_ids === [(int) $first->id, (int) $second->id];
        });
    }
}
