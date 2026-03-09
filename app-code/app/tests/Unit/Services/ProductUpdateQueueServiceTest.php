<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Services\Products\ProductUpdateQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductUpdateQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('product_update_items');
        Schema::dropIfExists('product_update_batches');

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

        Schema::create('product_update_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_update_batch_id');
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

    public function test_it_queues_update_item_for_bound_product_with_external_id(): void
    {
        Queue::fake();

        $batch = ProductUpdateBatch::query()->create([
            'status' => ProductUpdateBatchesStatusEnum::NEW->value,
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'payload'                 => [
                'operation'           => 'prepare_update',
                'update_instructions' => [
                    'Product' => [
                        'fields' => [
                            'price' => ['action' => 'set', 'value' => 199.99],
                        ],
                    ],
                ],
            ],
            'status' => ProductUpdateItemsStatusEnum::SUCCESSED->value,
        ]);

        Schema::getConnection()->table('product_shop')->insert([
            'product_import_batch_id' => null,
            'product_id'              => 501,
            'shop_id'                 => 2,
            'external_product_id'     => 9001,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $record = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('product_id', 501)
            ->where('payload->operation', 'prepare_update')
            ->firstOrFail();

        $summary = app(ProductUpdateQueueService::class)->queueForSingleUpdateItem($record, [2], 77);

        self::assertSame(1, $summary['products_total']);
        self::assertSame(1, $summary['updates_queued']);
        self::assertSame(0, $summary['skipped_not_bound']);
        self::assertSame(0, $summary['skipped_without_external_id']);

        $update_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $batch->id)
            ->where('payload->operation', 'update')
            ->first();

        self::assertInstanceOf(ProductUpdateItem::class, $update_item);
        self::assertSame(ProductUpdateItemsStatusEnum::PROCESSING->value, $update_item->status);
        self::assertSame(2, (int) ($update_item->payload['shop_id'] ?? 0));
        self::assertSame(9001, (int) ($update_item->payload['external_product_id'] ?? 0));
        self::assertSame(77, (int) ($update_item->payload['requested_by_user_id'] ?? 0));
        self::assertSame(199.99, (float) (($update_item->payload['update_instructions']['Product']['fields']['price']['value'] ?? 0)));

        Queue::assertPushed(ProcessProductUpdateItemJob::class, static function (ProcessProductUpdateItemJob $job) use ($update_item): bool {
            return $job->product_update_item_id === (int) $update_item->id;
        });

        $batch->refresh();

        self::assertSame(ProductUpdateBatchesStatusEnum::PROCESSING->value, $batch->status);
        self::assertSame('processing', $batch->options['update_state'] ?? null);
    }

    public function test_it_retries_failed_update_item_and_marks_batch_processing(): void
    {
        Queue::fake();

        $batch = ProductUpdateBatch::query()->create([
            'status'  => ProductUpdateBatchesStatusEnum::FAILED->value,
            'options' => ['update_state' => 'finished'],
        ]);

        $record = ProductUpdateItem::query()->create([
            'product_update_batch_id' => (int) $batch->id,
            'product_id'              => 601,
            'payload'                 => [
                'operation'           => 'update',
                'shop_id'             => 3,
                'external_product_id' => 9901,
            ],
            'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => 'Previous failure',
            'processed_at'  => now(),
        ]);

        $summary = app(ProductUpdateQueueService::class)->retryFailedForSingleUpdateItem($record);

        self::assertSame(1, $summary['failed_found']);
        self::assertSame(1, $summary['queued']);

        $record->refresh();
        self::assertSame(ProductUpdateItemsStatusEnum::PROCESSING->value, $record->status);
        self::assertNull($record->error_message);
        self::assertNull($record->processed_at);

        Queue::assertPushed(ProcessProductUpdateItemJob::class, static function (ProcessProductUpdateItemJob $job) use ($record): bool {
            return $job->product_update_item_id === (int) $record->id;
        });

        $batch->refresh();
        self::assertSame(ProductUpdateBatchesStatusEnum::PROCESSING->value, $batch->status);
        self::assertSame('processing', $batch->options['update_state'] ?? null);
    }
}
