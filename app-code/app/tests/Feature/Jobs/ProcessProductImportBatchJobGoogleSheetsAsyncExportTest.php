<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Products\Imports\ProductImportBatch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Revolution\Google\Sheets\Facades\Sheets;
use RuntimeException;
use Tests\TestCase;

class ProcessProductImportBatchJobGoogleSheetsAsyncExportTest extends TestCase
{
    private const array REQUIRED_SHEETS = [
        'Product',
        'Description',
        'Image',
        'Product Category',
        'Product Attribute',
        'Seo Url',
        'Special',
        'Discount',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
        Storage::fake(config('filesystems.default'));
    }

    public function test_it_exports_google_sheets_to_excel_files_async_and_reuses_existing_files(): void
    {
        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value,
            'source_name'     => 'Google Sheet test',
            'source_path'     => null,
            'status'          => 'New',
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'       => 'google_sheets',
                'spreadsheet_id'   => 'sheet_123',
                'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/sheet_123/edit#gid=0',
            ],
        ]);

        $rows_by_sheet = $this->buildRowsBySheet();

        Sheets::shouldReceive('spreadsheet')
            ->times(1 + count(self::REQUIRED_SHEETS))
            ->with('sheet_123')
            ->andReturnSelf();

        Sheets::shouldReceive('sheetList')
            ->once()
            ->andReturn(self::REQUIRED_SHEETS);

        foreach (self::REQUIRED_SHEETS as $sheet_name) {
            Sheets::shouldReceive('sheet')
                ->once()
                ->with($sheet_name)
                ->andReturnSelf();
        }

        Sheets::shouldReceive('all')
            ->times(count(self::REQUIRED_SHEETS))
            ->andReturn(
                $rows_by_sheet['Product'],
                $rows_by_sheet['Description'],
                $rows_by_sheet['Image'],
                $rows_by_sheet['Product Category'],
                $rows_by_sheet['Product Attribute'],
                $rows_by_sheet['Seo Url'],
                $rows_by_sheet['Special'],
                $rows_by_sheet['Discount'],
            );

        $job          = new ProcessProductImportBatchJob((int) $batch->id);
        $source_paths = $this->invokePrivateMethod($job, 'resolveGoogleSheetExportedFiles', [$batch]);

        self::assertNotEmpty($source_paths);

        foreach ($source_paths as $source_path) {
            self::assertTrue(Storage::exists($source_path));
        }

        $batch->refresh();
        self::assertNotNull($batch->source_path);
        self::assertSame('exported', (string) $batch->getOption('export_state'));
        self::assertSame($source_paths, array_values($batch->getOption('exported_files', [])));

        $source_paths_second_run = $this->invokePrivateMethod($job, 'resolveGoogleSheetExportedFiles', [$batch]);
        self::assertSame($source_paths, $source_paths_second_run);
    }

    public function test_it_throws_when_google_sheets_missing_required_sheets(): void
    {
        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value,
            'source_name'     => 'Google Sheet invalid',
            'source_path'     => null,
            'status'          => 'New',
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'       => 'google_sheets',
                'spreadsheet_id'   => 'sheet_invalid',
                'google_sheet_url' => 'https://docs.google.com/spreadsheets/d/sheet_invalid/edit#gid=0',
            ],
        ]);

        Sheets::shouldReceive('spreadsheet')
            ->once()
            ->with('sheet_invalid')
            ->andReturnSelf();

        Sheets::shouldReceive('sheetList')
            ->once()
            ->andReturn(['Product']);

        $job = new ProcessProductImportBatchJob((int) $batch->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required sheet');

        $this->invokePrivateMethod($job, 'resolveGoogleSheetExportedFiles', [$batch]);
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
     * @return array<string, array<int, array<int, string>>>
     */
    private function buildRowsBySheet(): array
    {
        return [
            'Product' => [
                ['Product Id', 'Model', 'SKU', 'EAN', 'Quantity', 'Minimum', 'Image', 'Price', 'Manufacturer', 'Brand', 'Is Active', 'Date Available', 'Date Added'],
                ['1', 'MODEL-1', 'SKU-1', 'EAN-1', '10', '1', 'catalog/product_1.jpg', '100', 'Man', 'Brand', '1', '2026-01-01 00:00:00', '2026-01-01 00:00:00'],
            ],
            'Description' => [
                ['Product Id', 'Name', 'Description', 'Meta Title', 'Meta Description', 'Meta Keywords'],
                ['1', 'Name 1', 'Description 1', 'Meta Title 1', 'Meta Description 1', 'Meta Keywords 1'],
            ],
            'Image' => [
                ['Product Id', 'Image', 'Sort Order'],
                ['1', 'catalog/product_1.jpg', '1'],
            ],
            'Product Category' => [
                ['Product Id', 'Category Name'],
                ['1', 'Category 1'],
            ],
            'Product Attribute' => [
                ['Product Id', 'Attribute Name', 'Attribute Text'],
                ['1', 'Color', 'Red'],
            ],
            'Seo Url' => [
                ['Product Id', 'Query Key', 'Query Value', 'Keyword', 'Sort Order'],
                ['1', 'product_id', '1', 'model-1', '1'],
            ],
            'Special' => [
                ['Product Id', 'User Group Id', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['1', '1', '90', '1', '', ''],
            ],
            'Discount' => [
                ['Product Id', 'User Group Id', 'Quantity', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['1', '1', '2', '80', '1', '', ''],
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $arguments);
    }
}

