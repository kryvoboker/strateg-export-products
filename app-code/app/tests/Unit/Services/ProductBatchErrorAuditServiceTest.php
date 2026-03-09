<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Services\Products\ProductBatchErrorAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductBatchErrorAuditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Schema::dropIfExists('product_import_batches');
        Schema::dropIfExists('product_update_batches');
        Schema::dropIfExists('product_delete_batches');

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
    }

    public function test_it_writes_public_import_error_audit_and_exposes_filename_and_url(): void
    {
        $batch = ProductImportBatch::query()->create([
            'source_type' => 'google_sheet',
            'source_name' => 'Google Sheet Import',
            'options'     => [],
        ]);

        $metadata = app(ProductBatchErrorAuditService::class)->syncImportBatchAudit($batch, [
            [
                'sheet'       => 'Product',
                'row'         => 17,
                'source_path' => 'google-sheets://batch-48',
                'message'     => 'Missing Product Id',
            ],
        ]);

        $batch->forceFill([
            'options' => $metadata,
        ]);

        self::assertSame('Missing Product Id', $metadata['last_error']);
        self::assertIsString($metadata['error_log_path']);
        self::assertNotSame('', $metadata['error_log_path']);
        Storage::disk('public')->assertExists((string) $metadata['error_log_path']);
        self::assertTrue($batch->hasErrorLog());
        self::assertStringContainsString('import-batch-'.$batch->id.'-errors-', (string) $batch->getErrorLogFileName());
        self::assertStringContainsString('/storage/', (string) $batch->getErrorLogUrl());
    }

    public function test_it_replaces_stale_update_error_audit_and_can_clear_it(): void
    {
        $batch = ProductUpdateBatch::query()->create([
            'source_type' => 'excel_file',
            'source_name' => 'Update File',
            'options'     => [],
        ]);

        $first_metadata = app(ProductBatchErrorAuditService::class)->syncUpdateBatchAudit($batch, [
            [
                'row'     => 3,
                'message' => 'Product not found',
            ],
        ]);

        $batch->forceFill([
            'options' => $first_metadata,
        ]);

        Storage::disk('public')->assertExists((string) $first_metadata['error_log_path']);

        $second_metadata = app(ProductBatchErrorAuditService::class)->syncUpdateBatchAudit($batch, [
            [
                'row'     => 4,
                'message' => 'External Product Id is missing',
            ],
        ]);

        self::assertNotSame($first_metadata['error_log_path'], $second_metadata['error_log_path']);
        Storage::disk('public')->assertMissing((string) $first_metadata['error_log_path']);
        Storage::disk('public')->assertExists((string) $second_metadata['error_log_path']);

        $batch->forceFill([
            'options' => $second_metadata,
        ]);

        $cleared_metadata = app(ProductBatchErrorAuditService::class)->syncUpdateBatchAudit($batch, []);

        self::assertNull($cleared_metadata['last_error']);
        self::assertNull($cleared_metadata['error_log_path']);
        Storage::disk('public')->assertMissing((string) $second_metadata['error_log_path']);
    }

    public function test_it_writes_delete_audit_from_exception_message(): void
    {
        $batch = ProductDeleteBatch::query()->create([
            'source_type' => 'admin_panel',
            'source_name' => 'Manual Delete',
            'options'     => [],
        ]);

        $metadata = app(ProductBatchErrorAuditService::class)->syncDeleteBatchAudit(
            $batch,
            [],
            new \RuntimeException('Delete batch exploded'),
        );

        self::assertSame('Delete batch exploded', $metadata['last_error']);
        Storage::disk('public')->assertExists((string) $metadata['error_log_path']);
    }
}
