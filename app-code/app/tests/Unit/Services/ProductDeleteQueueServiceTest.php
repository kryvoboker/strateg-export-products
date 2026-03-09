<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Jobs\ProcessProductDeleteItemJob;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductDeleteQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('product_delete_items');
        Schema::dropIfExists('product_delete_batches');

        Schema::create('product_delete_batches', static function (Blueprint $table): void {
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

        Schema::create('product_delete_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_delete_batch_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_product_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_queues_existing_delete_item(): void
    {
        Queue::fake();

        $batch = ProductDeleteBatch::query()->create([
            'status' => ProductDeleteBatchesStatusEnum::NEW->value,
        ]);

        $item = ProductDeleteItem::query()->create([
            'product_delete_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'payload'                 => [
                'shop_id'             => 2,
                'external_product_id' => 8001,
            ],
            'status' => ProductDeleteItemsStatusEnum::NEW->value,
        ]);

        $summary = app(ProductDeleteQueueService::class)->queueDeleteItem($item, true);

        self::assertSame(1, $summary['deletes_queued']);
        self::assertSame(0, $summary['errors']);

        $item->refresh();
        self::assertSame(ProductDeleteItemsStatusEnum::PROCESSING->value, $item->status);
        self::assertSame('delete', $item->payload['operation'] ?? null);

        Queue::assertPushed(ProcessProductDeleteItemJob::class, static function (ProcessProductDeleteItemJob $job) use ($item): bool {
            return $job->product_delete_item_id === (int) $item->id;
        });
    }

    public function test_it_retries_failed_delete_batches_through_shared_service(): void
    {
        Queue::fake();

        $batch = ProductDeleteBatch::query()->create([
            'status'  => ProductDeleteBatchesStatusEnum::FAILED->value,
            'options' => ['delete_state' => 'finished'],
        ]);

        ProductDeleteItem::query()->create([
            'product_delete_batch_id' => (int) $batch->id,
            'product_id'              => 701,
            'payload'                 => [
                'operation'           => 'delete',
                'shop_id'             => 5,
                'external_product_id' => 8801,
            ],
            'status'        => ProductDeleteItemsStatusEnum::FAILED->value,
            'error_message' => 'Failed previously',
            'processed_at'  => now(),
        ]);

        $summary = app(ProductDeleteQueueService::class)->retryFailedForDeleteBatches(
            ProductDeleteBatch::query()->whereKey((int) $batch->id)->get()
        );

        self::assertSame(1, $summary['failed_found']);
        self::assertSame(1, $summary['queued']);
        self::assertSame(0, $summary['errors']);

        $item = ProductDeleteItem::query()->firstOrFail();
        $item->refresh();

        self::assertSame(ProductDeleteItemsStatusEnum::PROCESSING->value, $item->status);
        self::assertNull($item->error_message);
        self::assertNull($item->processed_at);

        Queue::assertPushed(ProcessProductDeleteItemJob::class, static function (ProcessProductDeleteItemJob $job) use ($item): bool {
            return $job->product_delete_item_id === (int) $item->id;
        });
    }
}
