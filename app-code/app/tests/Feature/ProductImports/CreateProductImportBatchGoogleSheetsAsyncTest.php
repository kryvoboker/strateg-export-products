<?php

declare(strict_types=1);

namespace Tests\Feature\ProductImports;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Filament\Resources\ProductImports\Pages\CreateProductImportBatch;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Products\Imports\ProductImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Revolution\Google\Sheets\Facades\Sheets;
use Tests\TestCase;

class CreateProductImportBatchGoogleSheetsAsyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_google_sheets_batch_is_enqueued_without_sync_export_to_excel(): void
    {
        Queue::fake();

        Auth::shouldReceive('id')
            ->once()
            ->andReturn(77);

        Sheets::shouldReceive('spreadsheet')
            ->never();

        $page = new CreateProductImportBatch();
        /** @var ProductImportBatch $batch */
        $batch = $this->invokeMethod($page, 'createBatchFromGoogleSheets', [[
            'sheets_url' => 'https://docs.google.com/spreadsheets/d/test_sheet_123/edit#gid=0',
        ]]);

        self::assertInstanceOf(ProductImportBatch::class, $batch);
        self::assertSame(ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value, (string) $batch->source_type);
        self::assertNull($batch->source_path);
        self::assertSame('google_sheets', (string) $batch->getOption('input_mode'));
        self::assertSame('test_sheet_123', (string) $batch->getOption('spreadsheet_id'));
        self::assertSame('pending', (string) $batch->getOption('export_state'));
        self::assertNull($batch->getOption('exported_files'));

        Queue::assertPushed(ProcessProductImportBatchJob::class, function (ProcessProductImportBatchJob $job) use ($batch): bool {
            return $job->batch_id === (int) $batch->id;
        });
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_import_batches');

        Schema::create('product_import_batches', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_type');
            $table->string('source_name')->nullable();
            $table->string('source_path')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $arguments);
    }
}

