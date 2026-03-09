<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Services\Products\ProductShopBindingQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductShopBindingQueueServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_it_queues_only_one_binding_job_for_import_batch_when_at_least_one_shop_is_not_bound(): void
    {
        Queue::fake();

        $batch = ProductImportBatch::query()->create([
            'status' => ProductImportBatchesStatusEnum::NEW->value,
        ]);

        ProductImportItem::query()->create([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'payload'                 => ['source_meta' => ['row_number' => 12]],
            'status'                  => ProductImportItemsStatusEnum::SUCCESSED->value,
        ]);

        Schema::getConnection()->table('product_shop')->insert([
            'product_import_batch_id' => (int) $batch->id,
            'product_id'              => 501,
            'shop_id'                 => 1,
            'external_product_id'     => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $summary = app(ProductShopBindingQueueService::class)->queueForImportBatches(
            ProductImportBatch::query()->whereKey((int) $batch->id)->get(),
            [1, 2],
            77,
        );

        self::assertSame(1, $summary['batches_selected']);
        self::assertSame(1, $summary['products_total']);
        self::assertSame(1, $summary['jobs_queued']);
        self::assertSame(0, $summary['skipped_already_bound']);

        Queue::assertPushed(ProcessProductShopBindingJob::class, static function (ProcessProductShopBindingJob $job): bool {
            return $job->product_id === 501
                && $job->shop_ids === [1, 2]
                && $job->product_import_batch_id === 1
                && $job->requested_by_user_id === 77;
        });
    }
}
