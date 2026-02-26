<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductToAttribute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProcessProductImportBatchJobExcelFullCycleDuplicatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
        Storage::fake(config('filesystems.default'));
    }

    public function test_it_runs_full_excel_import_cycle_and_does_not_duplicate_catalog_entities(): void
    {
        $source_path = 'upload/excel/2026/02/google_sheet_20260223_141107_1_XGoGC5HhRMRGDD7nxjjYpQNIS23Z0scbJ9B7cCnDmY.xlsx';
        $this->createExcelFixture($source_path);

        $first_batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => 'Fixture 1',
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        (new ProcessProductImportBatchJob((int) $first_batch->id))->handle();

        $first_batch->refresh();
        self::assertSame(ProductImportBatchesStatusEnum::COMPLETED->value, (string) $first_batch->status);
        self::assertSame(2, (int) $first_batch->total_items);
        self::assertSame(0, (int) $first_batch->failed_items);

        self::assertSame(2, Product::query()->count());
        self::assertSame(2, Category::query()->count());
        self::assertSame(1, Attribute::query()->count());
        self::assertSame(1, Manufacturer::query()->count());
        self::assertSame(1, Brand::query()->count());
        self::assertSame(1, CategoryDescription::query()->where('name', 'Electronics')->count());
        self::assertSame(1, CategoryDescription::query()->where('name', 'Smartphones')->count());
        self::assertSame(1, AttributeDescription::query()->where('name', 'Color')->count());
        self::assertSame(1, ManufacturerDescription::query()->where('name', 'Apple')->count());
        self::assertSame(1, BrandDescription::query()->where('name', 'iPhone')->count());

        $second_batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => 'Fixture 2',
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        (new ProcessProductImportBatchJob((int) $second_batch->id))->handle();

        $second_batch->refresh();
        self::assertSame(ProductImportBatchesStatusEnum::COMPLETED->value, (string) $second_batch->status);
        self::assertSame(2, (int) $second_batch->total_items);
        self::assertSame(0, (int) $second_batch->failed_items);

        self::assertSame(4, Product::query()->count());
        self::assertSame(2, Category::query()->count());
        self::assertSame(1, Attribute::query()->count());
        self::assertSame(1, Manufacturer::query()->count());
        self::assertSame(1, Brand::query()->count());
        self::assertSame(1, CategoryDescription::query()->where('name', 'Electronics')->count());
        self::assertSame(1, CategoryDescription::query()->where('name', 'Smartphones')->count());
        self::assertSame(1, AttributeDescription::query()->where('name', 'Color')->count());
        self::assertSame(1, ManufacturerDescription::query()->where('name', 'Apple')->count());
        self::assertSame(1, BrandDescription::query()->where('name', 'iPhone')->count());
    }

    public function test_it_maps_attribute_names_to_values_by_position_and_normalizes_html_entities(): void
    {
        $source_path = 'upload/excel/2026/02/attribute_pairs_valid.xlsx';
        $this->createExcelFixtureForSingleAttributeRow(
            $source_path,
            'Color|Memory&nbsp;Size|GPS|Cable&nbsp;Length',
            'Black|64&nbsp;GB|Yes|3&nbsp;m'
        );

        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => 'Attribute Pairs Valid',
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        (new ProcessProductImportBatchJob((int) $batch->id))->handle();

        $product_id = (int) Product::query()->value('id');
        self::assertGreaterThan(0, $product_id);

        /** @var array<string, string> $attribute_map */
        $attribute_map = DB::table('product_to_attributes as product_to_attribute')
            ->join('attribute_descriptions as attribute_description', function ($join): void {
                $join->on('attribute_description.attribute_id', '=', 'product_to_attribute.attribute_id')
                    ->whereNull('attribute_description.shop_language_id');
            })
            ->where('product_to_attribute.product_id', $product_id)
            ->pluck('product_to_attribute.text', 'attribute_description.name')
            ->all();

        self::assertSame([
            'Color'        => 'Black',
            'Memory Size'  => '64 GB',
            'GPS'          => 'Yes',
            'Cable Length' => '3 m',
        ], $attribute_map);

        foreach ($attribute_map as $attribute_text) {
            self::assertStringNotContainsString('|', $attribute_text);
        }
    }

    public function test_it_marks_item_failed_and_skips_attribute_upsert_on_attribute_pairs_mismatch(): void
    {
        $source_path = 'upload/excel/2026/02/attribute_pairs_invalid.xlsx';
        $this->createExcelFixtureForSingleAttributeRow(
            $source_path,
            'Color|Memory|GPS|Cable',
            'Black|64 GB|Yes'
        );

        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => 'Attribute Pairs Invalid',
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        (new ProcessProductImportBatchJob((int) $batch->id))->handle();

        $item = ProductImportItem::query()->first();
        self::assertInstanceOf(ProductImportItem::class, $item);
        self::assertSame(ProductImportItemsStatusEnum::FAILED->value, (string) $item->status);
        self::assertStringContainsString('Invalid product attribute mapping', (string) $item->error_message);

        self::assertSame(1, Product::query()->count());
        self::assertSame(0, ProductToAttribute::query()->count());
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_to_manufacturer_brand');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('product_import_batches');
        Schema::dropIfExists('brand_descriptions');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('manufacturer_descriptions');
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('shops');
        Schema::dropIfExists('shop_languages');

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
            $table->dateTime('date_available')->nullable();
            $table->dateTime('date_added')->nullable();
            $table->timestamps();
        });

        Schema::create('product_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_language_id']);
        });

        Schema::create('product_images', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image');
            $table->integer('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('category_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_language_id']);
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
            $table->unique(['product_id', 'category_id']);
        });

        Schema::create('attributes', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attribute_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_language_id']);
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_id', 'shop_language_id']);
        });

        Schema::create('manufacturers', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('manufacturer_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('manufacturer_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'shop_language_id']);
        });

        Schema::create('brands', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brand_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'shop_language_id']);
        });

        Schema::create('product_to_manufacturer_brand', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id']);
        });

        Schema::create('seo_urls', static function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('query_key')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable();
            $table->integer('sort_order')->default(1);
            $table->timestamps();
            $table->unique(['seoable_type', 'seoable_id', 'shop_language_id', 'query_value']);
        });

        Schema::create('product_specials', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->dateTime('date_start')->nullable();
            $table->dateTime('date_end')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'user_group_id']);
        });

        Schema::create('product_discounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->default(1);
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->dateTime('date_start')->nullable();
            $table->dateTime('date_end')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'user_group_id']);
        });

        Schema::create('shops', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_languages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    private function createExcelFixture(string $path): void
    {
        $spreadsheet = new Spreadsheet();

        $sheets = [
            'Product' => [
                ['Product Id', 'Model', 'SKU', 'EAN', 'Quantity', 'Minimum', 'Image', 'Price', 'Manufacturer', 'Brand', 'Is Active', 'Date Available', 'Date Added'],
                ['', 'IP-14-PRO', 'SKU-14P', '1111111111111', '5', '1', 'catalog/iphone14pro.jpg', '1200', 'Apple', 'iPhone', '1', '2026-02-23 10:00:00', '2026-02-23 10:00:00'],
                ['', 'IP-14-MAX', 'SKU-14M', '2222222222222', '8', '1', 'catalog/iphone14max.jpg', '1400', 'Apple', 'iPhone', '1', '2026-02-23 10:00:00', '2026-02-23 10:00:00'],
            ],
            'Description' => [
                ['Product Id', 'Name', 'Description', 'Meta Title', 'Meta Description', 'Meta Keywords'],
                ['', 'iPhone 14 Pro', 'Phone description 1', 'Meta 1', 'Meta desc 1', 'iphone,pro'],
                ['', 'iPhone 14 Max', 'Phone description 2', 'Meta 2', 'Meta desc 2', 'iphone,max'],
            ],
            'Image' => [
                ['Product Id', 'Image', 'Sort Order'],
                ['', 'catalog/iphone14pro-1.jpg', '1'],
                ['', 'catalog/iphone14max-1.jpg', '1'],
            ],
            'Product Category' => [
                ['Product Id', 'Category Name'],
                ['', 'Electronics > Smartphones'],
                ['', 'Electronics > Smartphones'],
            ],
            'Product Attribute' => [
                ['Product Id', 'Attribute Name', 'Attribute Text'],
                ['', 'Color', 'Black'],
                ['', 'Color', 'White'],
            ],
            'Seo Url' => [
                ['Product Id', 'Query Key', 'Query Value', 'Keyword', 'Sort Order'],
                ['', 'product_id', '', 'iphone-14-pro', '1'],
                ['', 'product_id', '', 'iphone-14-max', '1'],
            ],
            'Special' => [
                ['Product Id', 'User Group Id', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '1100', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
                ['', '1', '1300', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
            ],
            'Discount' => [
                ['Product Id', 'User Group Id', 'Quantity', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '2', '1050', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
                ['', '1', '2', '1250', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
            ],
        ];

        $sheet_index = 0;
        foreach ($sheets as $sheet_name => $rows) {
            $sheet = $sheet_index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($sheet_index);

            $sheet->setTitle($sheet_name);
            $sheet->fromArray($rows, null, 'A1');
            $sheet_index++;
        }

        $full_path = Storage::path($path);
        File::ensureDirectoryExists(dirname($full_path));

        $writer = new Xlsx($spreadsheet);
        $writer->save($full_path);

        $spreadsheet->disconnectWorksheets();

        self::assertTrue(Storage::exists($path));
    }

    private function createExcelFixtureForSingleAttributeRow(
        string $path,
        string $attribute_names,
        string $attribute_values
    ): void {
        $spreadsheet = new Spreadsheet();

        $sheets = [
            'Product' => [
                ['Product Id', 'Model', 'SKU', 'EAN', 'Quantity', 'Minimum', 'Image', 'Price', 'Manufacturer', 'Brand', 'Is Active', 'Date Available', 'Date Added'],
                ['', 'ATTR-TEST', 'SKU-ATTR-TEST', '5555555555555', '5', '1', 'catalog/attr-test.jpg', '100', 'Apple', 'iPhone', '1', '2026-02-23 10:00:00', '2026-02-23 10:00:00'],
            ],
            'Description' => [
                ['Product Id', 'Name', 'Description', 'Meta Title', 'Meta Description', 'Meta Keywords'],
                ['', 'Attribute Test Product', 'Description', 'Meta', 'Meta desc', 'attr,test'],
            ],
            'Image' => [
                ['Product Id', 'Image', 'Sort Order'],
                ['', 'catalog/attr-test-1.jpg', '1'],
            ],
            'Product Category' => [
                ['Product Id', 'Category Name'],
                ['', 'Electronics > Smartphones'],
            ],
            'Product Attribute' => [
                ['Product Id', 'Attribute Name', 'Attribute Text'],
                ['', $attribute_names, $attribute_values],
            ],
            'Seo Url' => [
                ['Product Id', 'Query Key', 'Query Value', 'Keyword', 'Sort Order'],
                ['', 'product_id', '', 'attribute-test-product', '1'],
            ],
            'Special' => [
                ['Product Id', 'User Group Id', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '95', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
            ],
            'Discount' => [
                ['Product Id', 'User Group Id', 'Quantity', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '2', '90', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
            ],
        ];

        $sheet_index = 0;
        foreach ($sheets as $sheet_name => $rows) {
            $sheet = $sheet_index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($sheet_index);

            $sheet->setTitle($sheet_name);
            $sheet->fromArray($rows, null, 'A1');
            $sheet_index++;
        }

        $full_path = Storage::path($path);
        File::ensureDirectoryExists(dirname($full_path));

        $writer = new Xlsx($spreadsheet);
        $writer->save($full_path);

        $spreadsheet->disconnectWorksheets();

        self::assertTrue(Storage::exists($path));
    }
}
