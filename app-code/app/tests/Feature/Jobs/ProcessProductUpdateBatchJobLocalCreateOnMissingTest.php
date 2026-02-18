<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProductUpdateBatchJob;
use App\Models\Products\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ProcessProductUpdateBatchJobLocalCreateOnMissingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.db_prefix', 'spme_');
        $this->recreateSchema();
    }

    public function test_it_creates_missing_related_rows_for_update_flow_except_products_table(): void
    {
        $product = Product::query()->create([
            'product_import_item_id' => 1,
            'family_ulid'            => '01HFAMILYULID00000000000001',
            'model'                  => 'MODEL-1',
            'sku'                    => 'SKU-1',
            'ean'                    => 'EAN-1',
            'quantity'               => 1,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        Schema::getConnection()->table('shop_languages')->insert([
            'id'         => 1,
            'shop_id'    => 10,
            'code'       => 'uk',
            'name'       => 'Ukrainian',
            'is_active'  => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new ProcessProductUpdateBatchJob(1);

        $this->invokePrivateMethod($job, 'applyLocalProductDescriptionUpdates', [
            (int) $product->id,
            [
                'name' => ['action' => 'set', 'value' => 'Updated Name'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalImageUpdates', [
            (int) $product->id,
            [
                'image'      => ['action' => 'set', 'value' => '/img/a.jpg'],
                'sort_order' => ['action' => 'set', 'value' => '2'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalCategoryUpdates', [
            (int) $product->id,
            10,
            [
                'category_name' => ['action' => 'set', 'value' => 'Root > Child'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalAttributeUpdates', [
            (int) $product->id,
            10,
            [
                'attribute_name' => ['action' => 'set', 'value' => 'Color'],
                'attribute_text' => ['action' => 'set', 'value' => 'Red'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalSeoUrlUpdates', [
            (int) $product->id,
            10,
            [
                'query_value' => ['action' => 'set', 'value' => (string) $product->id],
                'keyword'     => ['action' => 'set', 'value' => 'product-url'],
                'sort_order'  => ['action' => 'set', 'value' => '1'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalSpecialUpdates', [
            (int) $product->id,
            [
                'user_group_id' => ['action' => 'set', 'value' => '1'],
                'price'         => ['action' => 'set', 'value' => '5.50'],
                'priority'      => ['action' => 'set', 'value' => '1'],
                'date_start'    => ['action' => 'set', 'value' => '2026-02-18 00:00:00'],
                'date_end'      => ['action' => 'set', 'value' => '2026-12-31 23:59:59'],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalDiscountUpdates', [
            (int) $product->id,
            [
                'user_group_id' => ['action' => 'set', 'value' => '1'],
                'quantity'      => ['action' => 'set', 'value' => '2'],
                'price'         => ['action' => 'set', 'value' => '4.50'],
                'priority'      => ['action' => 'set', 'value' => '1'],
                'date_start'    => ['action' => 'set', 'value' => '2026-02-18 00:00:00'],
                'date_end'      => ['action' => 'set', 'value' => '2026-12-31 23:59:59'],
            ],
        ]);

        self::assertDatabaseHas('product_descriptions', [
            'product_id' => (int) $product->id,
            'name'       => 'Updated Name',
        ]);

        self::assertDatabaseHas('product_images', [
            'product_id' => (int) $product->id,
            'image'      => '/img/a.jpg',
            'sort_order' => 2,
        ]);

        self::assertDatabaseCount('category_product', 1);
        self::assertDatabaseCount('categories', 2);
        self::assertDatabaseHas('category_descriptions', [
            'name' => 'Root',
        ]);
        self::assertDatabaseHas('category_descriptions', [
            'name' => 'Child',
        ]);

        self::assertDatabaseCount('product_to_attributes', 1);
        self::assertDatabaseHas('attribute_descriptions', [
            'name' => 'Color',
        ]);
        self::assertDatabaseHas('product_to_attributes', [
            'product_id' => (int) $product->id,
            'text'       => 'Red',
        ]);

        self::assertDatabaseHas('seo_urls', [
            'seoable_type' => Product::class,
            'seoable_id'   => (int) $product->id,
            'keyword'      => 'product-url',
        ]);

        self::assertDatabaseHas('product_specials', [
            'product_id'    => (int) $product->id,
            'user_group_id' => 1,
            'price'         => 5.5,
        ]);

        self::assertDatabaseHas('product_discounts', [
            'product_id'    => (int) $product->id,
            'user_group_id' => 1,
            'quantity'      => 2,
            'price'         => 4.5,
        ]);
    }

    public function test_it_applies_delete_and_no_change_for_related_tables(): void
    {
        $product = Product::query()->create([
            'product_import_item_id' => 1,
            'family_ulid'            => '01HFAMILYULID00000000000002',
            'model'                  => 'MODEL-2',
            'sku'                    => 'SKU-2',
            'ean'                    => 'EAN-2',
            'quantity'               => 1,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        Schema::getConnection()->table('shop_languages')->insert([
            'id'         => 1,
            'shop_id'    => 10,
            'code'       => 'uk',
            'name'       => 'Ukrainian',
            'is_active'  => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $category_id = Schema::getConnection()->table('categories')->insertGetId([
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Schema::getConnection()->table('category_descriptions')->insert([
            'category_id'       => $category_id,
            'shop_language_id'  => 1,
            'name'              => 'Existing Category',
            'description'       => null,
            'h1_title'          => 'Existing Category',
            'meta_title'        => 'Existing Category',
            'meta_description'  => null,
            'meta_keywords'     => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        Schema::getConnection()->table('category_product')->insert([
            'product_id'  => (int) $product->id,
            'category_id' => (int) $category_id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $attribute_id = Schema::getConnection()->table('attributes')->insertGetId([
            'sort_order' => 1,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::getConnection()->table('attribute_descriptions')->insert([
            'attribute_id'      => $attribute_id,
            'shop_language_id'  => 1,
            'name'              => 'Material',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        Schema::getConnection()->table('product_descriptions')->insert([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 1,
            'name'             => 'Original Name',
            'description'      => 'Original Description',
            'meta_title'       => 'Original Meta',
            'meta_description' => 'Original Meta Description',
            'meta_keywords'    => 'kw',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Schema::getConnection()->table('product_images')->insert([
            'product_id'  => (int) $product->id,
            'image'       => '/img/original.jpg',
            'sort_order'  => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Schema::getConnection()->table('product_to_attributes')->insert([
            'product_id'       => (int) $product->id,
            'attribute_id'     => (int) $attribute_id,
            'shop_language_id' => 1,
            'text'             => 'Wood',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Schema::getConnection()->table('seo_urls')->insert([
            'seoable_type'     => Product::class,
            'seoable_id'       => (int) $product->id,
            'shop_language_id' => 1,
            'query_value'      => (string) $product->id,
            'keyword'          => 'original-keyword',
            'sort_order'       => 5,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Schema::getConnection()->table('product_specials')->insert([
            'product_id'    => (int) $product->id,
            'user_group_id' => 1,
            'price'         => 5.5,
            'priority'      => 1,
            'date_start'    => '2026-02-18 00:00:00',
            'date_end'      => '2026-12-31 23:59:59',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        Schema::getConnection()->table('product_discounts')->insert([
            'product_id'    => (int) $product->id,
            'user_group_id' => 1,
            'quantity'      => 3,
            'price'         => 4.5,
            'priority'      => 1,
            'date_start'    => '2026-02-18 00:00:00',
            'date_end'      => '2026-12-31 23:59:59',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $job = new ProcessProductUpdateBatchJob(1);

        $this->invokePrivateMethod($job, 'applyLocalProductDescriptionUpdates', [
            (int) $product->id,
            [
                'name' => ['action' => 'no_change', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalImageUpdates', [
            (int) $product->id,
            [
                'image'      => ['action' => 'delete', 'value' => null],
                'sort_order' => ['action' => 'no_change', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalCategoryUpdates', [
            (int) $product->id,
            10,
            [
                'category_name' => ['action' => 'delete', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalAttributeUpdates', [
            (int) $product->id,
            10,
            [
                'attribute_name' => ['action' => 'set', 'value' => 'Material'],
                'attribute_text' => ['action' => 'delete', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalSeoUrlUpdates', [
            (int) $product->id,
            10,
            [
                'query_value' => ['action' => 'no_change', 'value' => null],
                'keyword'     => ['action' => 'delete', 'value' => null],
                'sort_order'  => ['action' => 'no_change', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalSpecialUpdates', [
            (int) $product->id,
            [
                'user_group_id' => ['action' => 'delete', 'value' => null],
                'price'         => ['action' => 'delete', 'value' => null],
                'priority'      => ['action' => 'delete', 'value' => null],
                'date_start'    => ['action' => 'delete', 'value' => null],
                'date_end'      => ['action' => 'delete', 'value' => null],
            ],
        ]);

        $this->invokePrivateMethod($job, 'applyLocalDiscountUpdates', [
            (int) $product->id,
            [
                'user_group_id' => ['action' => 'delete', 'value' => null],
                'quantity'      => ['action' => 'delete', 'value' => null],
                'price'         => ['action' => 'delete', 'value' => null],
                'priority'      => ['action' => 'delete', 'value' => null],
                'date_start'    => ['action' => 'delete', 'value' => null],
                'date_end'      => ['action' => 'delete', 'value' => null],
            ],
        ]);

        self::assertDatabaseHas('product_descriptions', [
            'product_id' => (int) $product->id,
            'name'       => 'Original Name',
        ]);
        self::assertDatabaseCount('product_images', 0);
        self::assertDatabaseCount('category_product', 0);
        self::assertDatabaseCount('product_to_attributes', 0);
        self::assertDatabaseCount('seo_urls', 0);
        self::assertDatabaseCount('product_specials', 0);
        self::assertDatabaseCount('product_discounts', 0);
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

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('shop_languages');
        Schema::dropIfExists('products');

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

        Schema::create('shop_languages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
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

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
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
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attribute_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name');
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

        Schema::create('seo_urls', static function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_specials', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });

        Schema::create('product_discounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });
    }
}
