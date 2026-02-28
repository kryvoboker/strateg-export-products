<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Product;
use App\Models\Seo\SeoUrl;
use App\Supports\Services\Products\ProductShopBindingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProcessProductImportBatchSeoUrlsLanguagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.ai_translation_enabled', false);
        config()->set('open-ai.api_key', 'test-key');

        $this->recreateSchema();
        Storage::fake(config('filesystems.default'));
    }

    public function test_it_creates_seo_urls_for_all_active_shop_languages_after_import_and_binding(): void
    {
        Schema::disableForeignKeyConstraints();
        \DB::table('shops')->insert([
            'id'         => 1,
            'name'       => 'Shop One',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('shop_languages')->insert([
            [
                'id'         => 2,
                'shop_id'    => 1,
                'code'       => 'uk',
                'name'       => 'Ukrainian',
                'is_active'  => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id'         => 10,
                'shop_id'    => 1,
                'code'       => 'en',
                'name'       => 'English',
                'is_active'  => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id'         => 11,
                'shop_id'    => 1,
                'code'       => 'de',
                'name'       => 'Deutsch',
                'is_active'  => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Schema::enableForeignKeyConstraints();

        $source_path = 'upload/excel/2026/02/google_sheet_20260223_141107_1_XGoGC5HhRMRGDD7nxjjYpQNIS23Z0scbJ9B7cCnDmY.xlsx';
        $this->createSeoLanguageFixture($source_path);

        $batch = ProductImportBatch::query()->create([
            'user_id'         => 1,
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => 'SEO languages fixture',
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
        ]);

        (new ProcessProductImportBatchJob((int) $batch->id))->handle();

        $batch->refresh();
        self::assertSame(ProductImportBatchesStatusEnum::COMPLETED->value, (string) $batch->status);
        self::assertSame(1, (int) $batch->total_items);
        self::assertSame(0, (int) $batch->failed_items);

        $product = Product::query()->where('model', 'SEO-LANG-1')->first();
        self::assertNotNull($product);
        $product_id = (int) ($product?->id ?? 0);
        self::assertGreaterThan(0, $product_id);

        self::assertDatabaseHas('product_shop', [
            'product_id' => $product_id,
            'shop_id'    => 1,
        ]);

        app(ProductShopBindingService::class)->synchronizeProductTranslationsForShop($product_id, 1);

        $seo_rows = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->orderBy('shop_language_id')
            ->get();

        self::assertSame(3, $seo_rows->count());
        self::assertSame([2, 10, 11], $seo_rows->pluck('shop_language_id')->map(static fn ($id): int => (int) $id)->all());

        self::assertDatabaseHas('seo_urls', [
            'seoable_type'     => Product::class,
            'seoable_id'       => $product_id,
            'shop_language_id' => 2,
            'keyword'          => 'manual-uk-keyword',
            'query_value'      => (string) $product_id,
        ]);

        $en_keyword = (string) (SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->where('shop_language_id', 10)
            ->value('keyword') ?? '');
        $de_keyword = (string) (SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->where('shop_language_id', 11)
            ->value('keyword') ?? '');

        self::assertNotSame('', $en_keyword);
        self::assertNotSame('', $de_keyword);
        self::assertStringContainsString('-en', $en_keyword);
        self::assertStringContainsString('-de', $de_keyword);

        $duplicate_count = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->where('query_value', (string) $product_id)
            ->selectRaw('COUNT(*) as total_rows, COUNT(DISTINCT shop_language_id) as unique_langs')
            ->first();

        self::assertNotNull($duplicate_count);
        self::assertSame(
            (int) ($duplicate_count->total_rows ?? 0),
            (int) ($duplicate_count->unique_langs ?? 0)
        );
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('brand_shop');
        Schema::dropIfExists('manufacturer_shop');
        Schema::dropIfExists('attribute_shop');
        Schema::dropIfExists('category_shop');
        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_to_manufacturer_brand');
        Schema::dropIfExists('brand_descriptions');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('manufacturer_descriptions');
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('product_import_batches');
        Schema::dropIfExists('shop_languages');
        Schema::dropIfExists('shops');
        Schema::dropIfExists('ai_translation_caches');

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
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
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
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
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
        });

        Schema::create('product_to_manufacturer_brand', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('manufacturer_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->timestamps();
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
            $table->unique(['seoable_type', 'seoable_id', 'shop_language_id', 'query_value'], 'seo_urls_unique_scope');
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
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->string('external_product_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_id']);
        });

        Schema::create('category_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_category_id')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_id']);
        });

        Schema::create('attribute_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_attribute_id')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_id']);
        });

        Schema::create('manufacturer_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('manufacturer_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_manufacturer_id')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'shop_id']);
        });

        Schema::create('brand_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_brand_id')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'shop_id']);
        });

        Schema::create('ai_translation_caches', static function (Blueprint $table): void {
            $table->id();
            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');
            $table->string('hash', 64);
            $table->longText('prompt');
            $table->longText('answer');
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'hash'], 'ai_translation_caches_scope_hash_unique');
        });
    }

    private function createSeoLanguageFixture(string $path): void
    {
        $spreadsheet = new Spreadsheet();

        $sheets = [
            'Product' => [
                ['Product Id', 'Shop Id', 'Model', 'SKU', 'EAN', 'Quantity', 'Minimum', 'Image', 'Price', 'Manufacturer', 'Brand', 'Is Active', 'Date Available', 'Date Added'],
                ['', '1', 'SEO-LANG-1', 'SEO-SKU-1', '9991112223334', '4', '1', 'catalog/seo-lang-1.jpg', '299', 'Apple', 'iPhone', '1', '2026-02-23 10:00:00', '2026-02-23 10:00:00'],
            ],
            'Description' => [
                ['Product Id', 'Shop Language Code', 'Name', 'Description', 'Meta Title', 'Meta Description', 'Meta Keywords'],
                ['', 'uk', 'Телефон SEO', 'Опис товару', 'Мета заголовок', 'Мета опис', 'ключ,seo'],
            ],
            'Image' => [
                ['Product Id', 'Image', 'Sort Order'],
                ['', 'catalog/seo-lang-1-1.jpg', '1'],
            ],
            'Product Category' => [
                ['Product Id', 'Category Name'],
                ['', 'Electronics > Smartphones'],
            ],
            'Product Attribute' => [
                ['Product Id', 'Shop Language Code', 'Attribute Name', 'Attribute Text'],
                ['', 'uk', 'Color', 'Black'],
            ],
            'Seo Url' => [
                ['Product Id', 'Shop Language Code', 'Query Key', 'Query Value', 'Keyword', 'Sort Order'],
                ['', 'uk', 'product_id', '', 'manual-uk-keyword', '1'],
            ],
            'Special' => [
                ['Product Id', 'User Group Id', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '259', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
            ],
            'Discount' => [
                ['Product Id', 'User Group Id', 'Quantity', 'Price', 'Priority', 'Date Start', 'Date End'],
                ['', '1', '2', '249', '1', '2026-02-23 00:00:00', '2027-02-23 00:00:00'],
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
