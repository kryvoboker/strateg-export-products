<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Services\Products\ProductExportQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductExportQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_export_items');
        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('product_import_batches');

        Schema::create('product_import_batches', static function (Blueprint $table): void {
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

        Schema::create('product_import_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id');
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

        Schema::create('product_export_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('batchable_type');
            $table->unsignedBigInteger('batchable_id');
            $table->unsignedBigInteger('product_id');
            $table->json('payload')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_queues_export_jobs_for_bound_import_item_products(): void
    {
        Queue::fake();

        $batch = ProductImportBatch::query()->create([
            'status' => ProductImportBatchesStatusEnum::NEW->value,
        ]);

        $item = ProductImportItem::query()->create([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'payload'                 => ['source_meta' => ['row_number' => 12]],
            'status'                  => ProductImportItemsStatusEnum::SUCCESSED->value,
        ]);

        Schema::getConnection()->table('product_shop')->insert([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'shop_id'                 => 2,
            'external_product_id'     => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $summary = app(ProductExportQueueService::class)->queueForSingleImportItem($item, [2], 77);

        self::assertSame(1, $summary['products_total']);
        self::assertSame(1, $summary['exports_queued']);
        self::assertSame(0, $summary['skipped_not_bound']);

        $export_item = ProductExportItem::query()->first();

        self::assertInstanceOf(ProductExportItem::class, $export_item);
        self::assertSame(ProductImportBatch::class, $export_item->batchable_type);
        self::assertSame((int) $batch->id, (int) $export_item->batchable_id);
        self::assertSame(501, (int) $export_item->product_id);
        self::assertSame(ProductExportItemsStatusEnum::PROCESSING->value, $export_item->status);
        self::assertSame(2, (int) ($export_item->payload['shop_id'] ?? 0));
        self::assertSame(77, (int) ($export_item->payload['requested_by_user_id'] ?? 0));

        Queue::assertPushed(ProcessProductExportItemJob::class, static function (ProcessProductExportItemJob $job) use ($export_item): bool {
            return $job->product_export_item_id === (int) $export_item->id;
        });

        $batch->refresh();

        self::assertSame(ProductImportBatchesStatusEnum::PROCESSING->value, $batch->status);
        self::assertSame('processing', $batch->options['export_state'] ?? null);
    }

    public function test_it_marks_unbound_shop_exports_as_failed_placeholders(): void
    {
        Queue::fake();

        $batch = ProductImportBatch::query()->create([
            'status' => ProductImportBatchesStatusEnum::NEW->value,
        ]);

        $item = ProductImportItem::query()->create([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => 777,
            'payload'                 => ['source_meta' => ['row_number' => 44]],
            'status'                  => ProductImportItemsStatusEnum::SUCCESSED->value,
        ]);

        $summary = app(ProductExportQueueService::class)->queueForSingleImportItem($item, [3], 91);

        self::assertSame(1, $summary['products_total']);
        self::assertSame(0, $summary['exports_queued']);
        self::assertSame(1, $summary['skipped_not_bound']);

        $export_item = ProductExportItem::query()->first();

        self::assertInstanceOf(ProductExportItem::class, $export_item);
        self::assertSame(777, (int) $export_item->product_id);
        self::assertSame(ProductExportItemsStatusEnum::FAILED->value, $export_item->status);
        self::assertSame('Product is not bound to selected shop. Export skipped.', $export_item->error_message);
        self::assertSame(3, (int) ($export_item->payload['shop_id'] ?? 0));
        self::assertSame('not_bound_to_shop', (string) ($export_item->payload['failure_reason'] ?? ''));
        self::assertSame(91, (int) ($export_item->payload['requested_by_user_id'] ?? 0));

        Queue::assertNotPushed(ProcessProductExportItemJob::class);
    }
}
