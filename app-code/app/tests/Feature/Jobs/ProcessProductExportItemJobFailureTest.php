<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessProductExportItemJobFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_it_marks_export_as_failed_when_opencart_master_token_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        [$export_item, $shop] = $this->makeOpenCartExportItem([
            'api_token' => null,
        ]);

        (new ProcessProductExportItemJob((int) $export_item->id))->handle();

        $export_item->refresh();

        self::assertSame(ProductExportItemsStatusEnum::FAILED->value, (string) $export_item->status);
        self::assertStringContainsString('Missing api_token for OpenCart login API request', (string) $export_item->error_message);
        self::assertNull(
            ProductShop::query()
                ->where('product_id', (int) $export_item->product_id)
                ->where('shop_id', (int) $shop->id)
                ->value('external_product_id')
        );
        Http::assertNothingSent();
    }

    public function test_it_marks_export_as_failed_when_opencart_api_returns_server_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop-export-test.local/api/login' => Http::response([
                'api_token' => 'auth-token-1',
            ], 200),
            'shop-export-test.local/api/export*' => Http::response([
                'error' => 'export failed',
            ], 500),
        ]);

        [$export_item] = $this->makeOpenCartExportItem([
            'api_token' => 'master-token',
        ]);

        (new ProcessProductExportItemJob((int) $export_item->id))->handle();

        $export_item->refresh();

        self::assertSame(ProductExportItemsStatusEnum::FAILED->value, (string) $export_item->status);
        self::assertStringContainsString('Export API failed with status 500', (string) $export_item->error_message);
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/login'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/api/export'));
    }

    public function test_it_marks_export_as_failed_when_api_response_has_no_external_product_id(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'shop-export-test.local/api/login' => Http::response([
                'api_token' => 'auth-token-2',
            ], 200),
            'shop-export-test.local/api/export*' => Http::response([
                'success' => true,
                'id'      => null,
            ], 200),
        ]);

        [$export_item] = $this->makeOpenCartExportItem([
            'api_token' => 'master-token',
        ]);

        (new ProcessProductExportItemJob((int) $export_item->id))->handle();

        $export_item->refresh();

        self::assertSame(ProductExportItemsStatusEnum::FAILED->value, (string) $export_item->status);
        self::assertStringContainsString('does not contain external product id', (string) $export_item->error_message);
    }

    /**
     * @param  array<string, mixed>  $shop_override
     * @return array{0: ProductExportItem, 1: Shop}
     */
    private function makeOpenCartExportItem(array $shop_override = []): array
    {
        $import_batch = ProductImportBatch::query()->create([
            'user_id'         => null,
            'source_type'     => ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value,
            'source_name'     => 'export-failure-test',
            'source_path'     => null,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [],
            'started_at'      => now(),
            'finished_at'     => null,
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => (int) $import_batch->id,
            'product_id'              => null,
            'payload'                 => ['source_meta' => ['row_number' => 1]],
            'status'                  => ProductImportItemsStatusEnum::SUCCESSED->value,
            'error_message'           => null,
            'processed_at'            => now(),
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => (int) $import_item->id,
            'family_ulid'            => (string) Str::ulid(),
            'marked_to_shop'         => null,
            'model'                  => 'FAILED-EXPORT-MODEL',
            'sku'                    => 'FAILED-EXPORT-SKU',
            'ean'                    => '5900000000001',
            'quantity'               => 10,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 100.50,
            'is_active'              => true,
            'date_available'         => now(),
            'date_added'             => now(),
        ]);

        $import_item->update([
            'product_id' => (int) $product->id,
        ]);

        $shop = Shop::query()->create(array_merge([
            'name'                       => 'OpenCart Test Shop',
            'type'                       => 'opencart 3',
            'base_url'                   => 'https://shop-export-test.local',
            'api_url'                    => 'https://shop-export-test.local',
            'api_token'                  => 'master-token',
            'part_api_url_login'         => '/api/login',
            'part_api_url_export_prods'  => '/api/export',
            'part_api_url_update_prods'  => '/api/update',
            'part_api_url_restore_prods' => '/api/restore',
            'is_active'                  => true,
            'options'                    => [
                'api_timeout'  => 10,
                'api_username' => 'api-user',
            ],
        ], $shop_override));

        ProductShop::query()->create([
            'product_import_batch_id' => (int) $import_batch->id,
            'product_id'              => (int) $product->id,
            'shop_id'                 => (int) $shop->id,
            'external_product_id'     => null,
        ]);

        $export_item = ProductExportItem::query()->create([
            'batchable_type' => ProductImportBatch::class,
            'batchable_id'   => (int) $import_batch->id,
            'product_id'     => (int) $product->id,
            'payload'        => [
                'operation'            => 'export',
                'shop_id'              => (int) $shop->id,
                'requested_product_id' => (int) $product->id,
            ],
            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        return [$export_item, $shop];
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_export_items');
        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('product_import_batches');
        Schema::dropIfExists('shop_languages');
        Schema::dropIfExists('shops');

        Schema::create('shops', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50);
            $table->string('base_url');
            $table->string('api_url')->nullable();
            $table->string('api_token', 500)->nullable();
            $table->string('part_api_url_login')->nullable();
            $table->string('part_api_url_export_prods')->nullable();
            $table->string('part_api_url_update_prods')->nullable();
            $table->string('part_api_url_restore_prods')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_languages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_import_batches', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_type', 100)->default('admin_panel');
            $table->string('source_name', 2000)->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 100)->default('new');
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
            $table->string('status', 100)->nullable()->default('new');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->string('family_ulid', 26)->nullable();
            $table->string('marked_to_shop')->nullable();
            $table->string('model')->nullable();
            $table->string('sku')->nullable();
            $table->string('ean')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('minimum')->default(1);
            $table->string('image')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamp('date_available')->nullable();
            $table->timestamp('date_added')->nullable();
            $table->timestamps();
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });

        Schema::create('product_export_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('batchable_type');
            $table->unsignedBigInteger('batchable_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
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
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
        });

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('category_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
        });

        Schema::create('attribute_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('product_specials', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(0);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });

        Schema::create('product_discounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(0);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_urls', static function (Blueprint $table): void {
            $table->id();
            $table->morphs('seoable');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }
}
