<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProductExportItemJob;
use App\Models\Products\Product;
use App\Models\Shops\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ProcessProductExportItemJobShopScopePayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.db_prefix', 'spme_');
        $this->recreateSchema();
    }

    public function test_it_builds_export_payload_only_from_target_shop_scoped_categories_and_attributes(): void
    {
        $shop = Shop::query()->create([
            'name'                       => 'Scoped Shop',
            'type'                       => 'opencart 3',
            'base_url'                   => 'https://shop-scope-test.local',
            'api_url'                    => 'https://shop-scope-test.local',
            'api_token'                  => 'token',
            'part_api_url_login'         => '/api/login',
            'part_api_url_export_prods'  => '/api/export',
            'part_api_url_update_prods'  => '/api/update',
            'part_api_url_restore_prods' => '/api/restore',
            'is_active'                  => true,
            'options'                    => [],
        ]);

        Schema::getConnection()->table('shop_languages')->insert([
            'id'         => 101,
            'shop_id'    => (int) $shop->id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_default' => true,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'product_import_item_id' => null,
            'family_ulid'            => '01KHSCOPEPROD0000000000001',
            'model'                  => 'MODEL-SCOPE-1',
            'sku'                    => 'SKU-SCOPE-1',
            'ean'                    => '5900000001234',
            'quantity'               => 10,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 50.00,
            'is_active'              => true,
            'date_available'         => now(),
            'date_added'             => now(),
        ]);

        Schema::getConnection()->table('product_descriptions')->insert([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 101,
            'name'             => 'Scoped Product',
            'description'      => 'Scoped product description',
            'meta_title'       => 'Scoped Product',
            'meta_description' => null,
            'meta_keywords'    => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $category_for_shop = Schema::getConnection()->table('categories')->insertGetId([
            'family_ulid' => '01KHSCOPECAT000000000000001',
            'shop_id'     => (int) $shop->id,
            'parent_id'   => null,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $category_for_other_shop = Schema::getConnection()->table('categories')->insertGetId([
            'family_ulid' => '01KHSCOPECAT000000000000002',
            'shop_id'     => (int) $shop->id + 1,
            'parent_id'   => null,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Schema::getConnection()->table('category_descriptions')->insert([
            [
                'category_id'      => $category_for_shop,
                'shop_language_id' => 101,
                'name'             => 'Scoped Category',
                'description'      => null,
                'h1_title'         => 'Scoped Category',
                'meta_title'       => 'Scoped Category',
                'meta_description' => null,
                'meta_keywords'    => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'category_id'      => $category_for_other_shop,
                'shop_language_id' => 101,
                'name'             => 'Other Category',
                'description'      => null,
                'h1_title'         => 'Other Category',
                'meta_title'       => 'Other Category',
                'meta_description' => null,
                'meta_keywords'    => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);

        Schema::getConnection()->table('category_product')->insert([
            ['product_id' => (int) $product->id, 'category_id' => $category_for_shop, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => (int) $product->id, 'category_id' => $category_for_other_shop, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $attribute_for_shop = Schema::getConnection()->table('attributes')->insertGetId([
            'family_ulid' => '01KHSCOPEATTR00000000000001',
            'shop_id'     => (int) $shop->id,
            'sort_order'  => 1,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $attribute_for_other_shop = Schema::getConnection()->table('attributes')->insertGetId([
            'family_ulid' => '01KHSCOPEATTR00000000000002',
            'shop_id'     => (int) $shop->id + 1,
            'sort_order'  => 1,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Schema::getConnection()->table('attribute_descriptions')->insert([
            ['attribute_id' => $attribute_for_shop, 'shop_language_id' => 101, 'name' => 'Color', 'created_at' => now(), 'updated_at' => now()],
            ['attribute_id' => $attribute_for_other_shop, 'shop_language_id' => 101, 'name' => 'Material', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::getConnection()->table('product_to_attributes')->insert([
            ['product_id' => (int) $product->id, 'attribute_id' => $attribute_for_shop, 'shop_language_id' => 101, 'text' => 'Blue', 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => (int) $product->id, 'attribute_id' => $attribute_for_other_shop, 'shop_language_id' => 101, 'text' => 'Cotton', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $job     = new ProcessProductExportItemJob(0);
        $payload = $this->invokePrivateMethod($job, 'buildRequestPayload', [$product->fresh(), (int) $shop->id]);

        $category_ids  = collect($payload['categories'] ?? [])->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $attribute_ids = collect($payload['attributes'] ?? [])->pluck('attribute_id')->map(static fn ($id): int => (int) $id)->values()->all();

        self::assertSame([$category_for_shop], $category_ids);
        self::assertSame([$attribute_for_shop], $attribute_ids);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);

        return $method->invokeArgs($target, $arguments);
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('products');
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

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
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

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
        });

        Schema::create('attributes', static function (Blueprint $table): void {
            $table->id();
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
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

        Schema::create('product_images', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
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
