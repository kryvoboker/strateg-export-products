<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Categories\CategoryShop;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Services\Products\ProductResourceOptionsService;
use App\Supports\Services\Products\ProductShopBindingService;
use App\Models\Attributes\AttributeShop;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductShopBindingServiceAttributeTranslationTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $container = new Application(__DIR__);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'database.db_prefix' => '',
            'cache.default'      => 'array',
            'cache.stores.array' => [
                'driver' => 'array',
            ],
        ]));
        $container->instance('cache', new CacheManager($container));

        $container->instance(AiTranslationPromptBuilderService::class, new AiTranslationPromptBuilderService());
        $container->instance(\App\Supports\Services\Ai\AiTranslationService::class, new FakeAiTranslationService());

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
        $container->instance('db', self::$capsule->getDatabaseManager());

        $schema = self::$capsule->schema();

        $schema->create('shops', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('shop_languages', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('shop_id')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $schema->create('product_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_language_id']);
        });

        $schema->create('product_import_items', static function ($table): void {
            $table->increments('id');
            $table->string('ulid')->nullable();
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->text('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('products', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_item_id')->nullable();
            $table->string('family_ulid', 26)->nullable();
            $table->string('marked_to_shop')->nullable();
            $table->string('model')->nullable();
            $table->string('sku')->nullable();
            $table->string('ean')->nullable();
            $table->integer('quantity')->default(0);
            $table->integer('minimum')->default(1);
            $table->string('image')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('date_available')->nullable();
            $table->timestamp('date_added')->nullable();
            $table->timestamps();
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('attribute_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('attribute_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_language_id']);
        });

        $schema->create('product_to_attributes', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('attribute_id')->nullable();
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('text', 3000)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'attribute_id', 'shop_language_id']);
        });

        $schema->create('attribute_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('attribute_id');
            $table->unsignedInteger('shop_id');
            $table->string('external_attribute_id')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_id']);
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        $schema->create('category_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_language_id']);
        });

        $schema->create('category_product', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('category_id');
            $table->timestamps();
        });

        $schema->create('category_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('shop_id');
            $table->string('external_category_id')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_id']);
        });

        $schema->create('manufacturers', static function ($table): void {
            $table->increments('id');
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('manufacturer_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->unique(['manufacturer_id', 'shop_language_id']);
        });

        $schema->create('manufacturer_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id');
            $table->unsignedInteger('shop_id');
            $table->string('external_manufacturer_id')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'shop_id']);
        });

        $schema->create('brands', static function ($table): void {
            $table->increments('id');
            $table->string('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('brand_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->unique(['brand_id', 'shop_language_id']);
        });

        $schema->create('brand_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('shop_id');
            $table->string('external_brand_id')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'shop_id']);
        });

        $schema->create('product_to_manufacturer_brand', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('manufacturer_id')->nullable();
            $table->unsignedInteger('brand_id')->nullable();
            $table->timestamps();
            $table->unique('product_id');
        });

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_id']);
        });

        $schema->create('seo_urls', static function ($table): void {
            $table->increments('id');
            $table->string('seoable_type');
            $table->unsignedInteger('seoable_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('query_key')->nullable();
            $table->string('query_value');
            $table->string('keyword')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        app('config')->set('app.ai_translation_enabled', true);

        ProductToAttribute::query()->delete();
        ProductShop::query()->delete();
        Product::query()->delete();
        AttributeDescription::query()->delete();
        Attribute::query()->delete();
        ProductToManufacturerBrand::query()->delete();
        ManufacturerDescription::query()->delete();
        Manufacturer::query()->delete();
        BrandDescription::query()->delete();
        Brand::query()->delete();
        CategoryProduct::query()->delete();
        CategoryDescription::query()->delete();
        Category::query()->delete();
        ProductDescription::query()->delete();
        ShopLanguage::query()->delete();
        Shop::query()->delete();
        app('cache')->store()->clear();
    }

    public function test_it_applies_default_shop_language_to_category_descriptions_after_binding(): void
    {
        $shop_id    = 13;
        $product_id = 404;

        $default_shop_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $category = Category::query()->create([
            'parent_id'  => null,
            'sort_order' => 0,
            'is_active'  => true,
        ]);

        CategoryDescription::query()->create([
            'category_id'      => (int) $category->id,
            'shop_language_id' => null,
            'name'             => 'Board games',
            'description'      => null,
            'h1_title'         => 'Board games',
            'meta_title'       => 'Board games',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        CategoryProduct::query()->create([
            'product_id'  => $product_id,
            'category_id' => (int) $category->id,
        ]);

        $service = new ProductShopBindingService();
        $service->applyDefaultLanguageToProductTranslations($product_id, $shop_id);

        $category_name_for_default_language = CategoryDescription::query()
            ->where('category_id', (int) $category->id)
            ->where('shop_language_id', (int) $default_shop_language->id)
            ->value('name');

        self::assertSame('Board games', (string) $category_name_for_default_language);
        self::assertSame(0, CategoryDescription::query()->whereNull('shop_language_id')->count());
    }

    public function test_it_applies_default_shop_language_to_manufacturer_and_brand_descriptions_after_binding(): void
    {
        $shop_id    = 12;
        $product_id = 303;

        $default_shop_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $manufacturer = Manufacturer::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer->id,
            'shop_language_id' => null,
            'name'             => 'Acme',
        ]);

        $brand = Brand::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        BrandDescription::query()->create([
            'brand_id'         => (int) $brand->id,
            'shop_language_id' => null,
            'name'             => 'Prime',
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);

        $service = new ProductShopBindingService();
        $service->applyDefaultLanguageToProductTranslations($product_id, $shop_id);

        $manufacturer_name_for_default_language = ManufacturerDescription::query()
            ->where('manufacturer_id', (int) $manufacturer->id)
            ->where('shop_language_id', (int) $default_shop_language->id)
            ->value('name');

        $brand_name_for_default_language = BrandDescription::query()
            ->where('brand_id', (int) $brand->id)
            ->where('shop_language_id', (int) $default_shop_language->id)
            ->value('name');

        self::assertSame('Acme', (string) $manufacturer_name_for_default_language);
        self::assertSame('Prime', (string) $brand_name_for_default_language);
        self::assertSame(0, ManufacturerDescription::query()->whereNull('shop_language_id')->count());
        self::assertSame(0, BrandDescription::query()->whereNull('shop_language_id')->count());
    }

    public function test_it_splits_pipe_attributes_and_translates_to_shop_languages_using_default_source_language(): void
    {
        $shop_id    = 10;
        $product_id = 101;

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Phone',
            'description'      => 'Smart phone',
            'meta_title'       => 'Phone',
            'meta_description' => 'Smart phone',
            'meta_keywords'    => 'phone',
        ]);

        $source_attribute = Attribute::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Color|Size',
        ]);

        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'text'             => 'Red|XL',
        ]);

        $service = new ProductShopBindingService();

        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $en_attributes = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $en_language->id)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $en_attributes);
        self::assertSame(['Red', 'XL'], $en_attributes->pluck('text')->all());

        $attribute_ids = $en_attributes
            ->pluck('attribute_id')
            ->map(static fn ($attribute_id): int => (int) $attribute_id)
            ->all();

        $en_names = AttributeDescription::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->where('shop_language_id', (int) $en_language->id)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame(['Color', 'Size'], $en_names);

        $uk_names = AttributeDescription::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->where('shop_language_id', (int) $uk_language->id)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        self::assertSame(['Color [uk]', 'Size [uk]'], $uk_names);

        $uk_attribute_texts = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->orderBy('id')
            ->pluck('text')
            ->all();

        self::assertSame(['Red [uk]', 'XL [uk]'], $uk_attribute_texts);
    }

    public function test_it_routes_translation_via_ai_translation_service_when_ai_translation_is_disabled(): void
    {
        app('config')->set('app.ai_translation_enabled', false);

        $shop_id    = 11;
        $product_id = 202;

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Phone',
            'description'      => 'Smart phone',
            'meta_title'       => 'Phone',
            'meta_description' => 'Smart phone',
            'meta_keywords'    => 'phone',
        ]);

        $source_attribute = Attribute::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Color',
        ]);

        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $source_attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'text'             => 'Red',
        ]);

        $service = new ProductShopBindingService();

        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $uk_product_description = ProductDescription::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->first();

        self::assertInstanceOf(ProductDescription::class, $uk_product_description);
        self::assertSame('Phone [uk]', (string) $uk_product_description->name);
        self::assertSame('Smart phone [uk]', (string) $uk_product_description->description);

        $uk_attribute_name = AttributeDescription::query()
            ->where('attribute_id', (int) $source_attribute->id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->value('name');

        self::assertSame('Color [uk]', (string) $uk_attribute_name);

        $uk_attribute_text = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('attribute_id', (int) $source_attribute->id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->value('text');

        self::assertSame('Red [uk]', (string) $uk_attribute_text);
    }

    public function test_it_retranslates_copied_attribute_values_for_non_default_languages_on_duplicate_like_rows(): void
    {
        $shop_id    = 21;
        $product_id = 1201;

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => false,
        ]);

        $ru_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'ru',
            'name'       => 'Русский',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $uk_language->id,
            'name'             => 'Ноутбук',
            'description'      => 'Опис',
            'meta_title'       => 'Ноутбук',
            'meta_description' => 'Опис',
            'meta_keywords'    => 'ноутбук',
        ]);

        $attribute = Attribute::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $uk_language->id,
            'name'             => 'Країна виробництва',
        ]);

        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $uk_language->id,
            'text'             => 'Німеччина',
        ]);

        // Simulate duplicated-product bug: non-default languages already contain source text.
        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'text'             => 'Німеччина',
        ]);
        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $ru_language->id,
            'text'             => 'Німеччина',
        ]);

        $service = new ProductShopBindingService();

        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $en_attribute_text = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $en_language->id)
            ->value('text');

        $ru_attribute_text = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $ru_language->id)
            ->value('text');

        self::assertSame('Німеччина [en]', (string) $en_attribute_text);
        self::assertSame('Німеччина [ru]', (string) $ru_attribute_text);
        self::assertSame(1, ProductToAttribute::query()->where('product_id', $product_id)->where('shop_language_id', (int) $en_language->id)->count());
        self::assertSame(1, ProductToAttribute::query()->where('product_id', $product_id)->where('shop_language_id', (int) $ru_language->id)->count());
    }

    public function test_it_retranslates_copied_attribute_values_when_existing_text_is_html_encoded_variant(): void
    {
        $shop_id    = 22;
        $product_id = 1202;

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $uk_language->id,
            'name'             => 'Ноутбук',
            'description'      => 'Опис',
            'meta_title'       => 'Ноутбук',
            'meta_description' => 'Опис',
            'meta_keywords'    => 'ноутбук',
        ]);

        $attribute = Attribute::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $uk_language->id,
            'name'             => 'Країна виробництва',
        ]);

        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $uk_language->id,
            'text'             => 'Німеччина',
        ]);

        // Simulate copied value with HTML entity variant from import/source normalization edge case.
        ProductToAttribute::query()->create([
            'product_id'       => $product_id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => (int) $en_language->id,
            'text'             => 'Німеччина&nbsp;',
        ]);

        $service = new ProductShopBindingService();

        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $en_attribute_text = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', (int) $en_language->id)
            ->value('text');

        self::assertSame('Німеччина [en]', (string) $en_attribute_text);
    }

    public function test_it_keeps_language_ids_when_loading_active_languages_from_cache(): void
    {
        $shop_id = 31;

        $default_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $secondary_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => false,
        ]);

        $cached_languages = ShopLanguage::getActiveByShopIdCached($shop_id);

        self::assertSame(
            [(int) $default_language->id, (int) $secondary_language->id],
            $cached_languages->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );
    }

    public function test_it_uses_category_translation_method_for_category_name(): void
    {
        $shop_id    = 18;
        $product_id = 818;

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Phone',
            'description'      => 'Smart phone',
            'meta_title'       => 'Phone',
            'meta_description' => 'Smart phone',
            'meta_keywords'    => 'phone',
        ]);

        $category = Category::query()->create([
            'parent_id'  => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        CategoryDescription::query()->create([
            'category_id'      => (int) $category->id,
            'shop_language_id' => (int) $en_language->id,
            'name'             => 'Phones',
            'description'      => 'Phones category',
            'h1_title'         => 'Phones',
            'meta_title'       => 'Phones',
            'meta_description' => 'Phones category',
            'meta_keywords'    => 'phones',
        ]);

        CategoryProduct::query()->create([
            'product_id'  => $product_id,
            'category_id' => (int) $category->id,
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod(
            $service,
            'translateProductTextsForShopLanguages',
            [$product_id, $shop_id]
        );

        $uk_category_description = CategoryDescription::query()
            ->where('category_id', (int) $category->id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->first();

        self::assertInstanceOf(CategoryDescription::class, $uk_category_description);
        self::assertSame('category-Phones [uk]', (string) $uk_category_description->name);
    }

    public function test_it_creates_product_seo_urls_for_all_active_shop_languages(): void
    {
        $shop_id    = 80;
        $product_id = 1800;

        $uk_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $en_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => false,
        ]);

        $de_language = ShopLanguage::query()->create([
            'shop_id'    => $shop_id,
            'code'       => 'de',
            'name'       => 'Deutsch',
            'is_active'  => true,
            'is_default' => false,
        ]);

        $product = Product::query()->create([
            'model'     => 'SEO-MODEL-1',
            'sku'       => 'SEO-SKU-1',
            'quantity'  => 1,
            'minimum'   => 1,
            'price'     => 100,
            'is_active' => true,
        ]);
        $product_id = (int) ($product->id ?? 0);

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => (int) $uk_language->id,
            'name'             => 'Назва товару',
            'description'      => 'Опис',
            'meta_title'       => 'Назва товару',
            'meta_description' => 'Опис',
            'meta_keywords'    => 'ключ',
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod($service, 'synchronizeSeoUrlsForShopLanguages', [$product_id, $shop_id]);

        $seo_rows = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->orderBy('shop_language_id')
            ->get();

        self::assertCount(3, $seo_rows);
        self::assertSame(
            [(int) $uk_language->id, (int) $en_language->id, (int) $de_language->id],
            $seo_rows->pluck('shop_language_id')->map(static fn ($id): int => (int) $id)->all()
        );
        self::assertSame('nazva-tovaru', (string) $seo_rows->firstWhere('shop_language_id', (int) $uk_language->id)?->keyword);
        self::assertStringEndsWith('-en', (string) $seo_rows->firstWhere('shop_language_id', (int) $en_language->id)?->keyword);
        self::assertStringEndsWith('-de', (string) $seo_rows->firstWhere('shop_language_id', (int) $de_language->id)?->keyword);
    }

    public function test_it_creates_manufacturer_and_brand_descriptions_for_all_active_shop_languages(): void
    {
        app('config')->set('app.ai_translation_enabled', false);

        $product_id = 999;

        $shop_one_uk = ShopLanguage::query()->create([
            'shop_id'    => 50,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);
        $shop_one_en = ShopLanguage::query()->create([
            'shop_id'    => 50,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => false,
        ]);
        $shop_two_uk = ShopLanguage::query()->create([
            'shop_id'    => 60,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);
        $shop_two_de = ShopLanguage::query()->create([
            'shop_id'    => 60,
            'code'       => 'de',
            'name'       => 'Deutsch',
            'is_active'  => true,
            'is_default' => false,
        ]);

        $manufacturer = Manufacturer::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        $brand = Brand::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer->id,
            'shop_language_id' => (int) $shop_one_uk->id,
            'name'             => 'Acme',
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand->id,
            'shop_language_id' => (int) $shop_one_uk->id,
            'name'             => 'Prime',
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);
        ProductShop::query()->create([
            'product_id' => $product_id,
            'shop_id'    => 50,
        ]);
        ProductShop::query()->create([
            'product_id' => $product_id,
            'shop_id'    => 60,
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod($service, 'synchronizeManufacturerDescriptionsForShopLanguages', [$product_id, 50]);
        $this->invokePrivateMethod($service, 'synchronizeBrandDescriptionsForShopLanguages', [$product_id, 50]);
        $this->invokePrivateMethod($service, 'synchronizeManufacturerDescriptionsForShopLanguages', [$product_id, 60]);
        $this->invokePrivateMethod($service, 'synchronizeBrandDescriptionsForShopLanguages', [$product_id, 60]);

        $manufacturer_rows = ManufacturerDescription::query()
            ->where('manufacturer_id', (int) $manufacturer->id)
            ->orderBy('shop_language_id')
            ->get();

        $brand_rows = BrandDescription::query()
            ->where('brand_id', (int) $brand->id)
            ->orderBy('shop_language_id')
            ->get();

        $expected_language_ids = [
            (int) $shop_one_uk->id,
            (int) $shop_one_en->id,
            (int) $shop_two_uk->id,
            (int) $shop_two_de->id,
        ];

        self::assertSame($expected_language_ids, $manufacturer_rows->pluck('shop_language_id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame($expected_language_ids, $brand_rows->pluck('shop_language_id')->map(static fn ($id): int => (int) $id)->all());

        self::assertSame('Acme', (string) $manufacturer_rows->firstWhere('shop_language_id', (int) $shop_one_uk->id)?->name);
        self::assertSame('Acme [en]', (string) $manufacturer_rows->firstWhere('shop_language_id', (int) $shop_one_en->id)?->name);
        self::assertSame('Acme', (string) $manufacturer_rows->firstWhere('shop_language_id', (int) $shop_two_uk->id)?->name);
        self::assertSame('Acme [de]', (string) $manufacturer_rows->firstWhere('shop_language_id', (int) $shop_two_de->id)?->name);

        self::assertSame('Prime', (string) $brand_rows->firstWhere('shop_language_id', (int) $shop_one_uk->id)?->name);
        self::assertSame('Prime [en]', (string) $brand_rows->firstWhere('shop_language_id', (int) $shop_one_en->id)?->name);
        self::assertSame('Prime', (string) $brand_rows->firstWhere('shop_language_id', (int) $shop_two_uk->id)?->name);
        self::assertSame('Prime [de]', (string) $brand_rows->firstWhere('shop_language_id', (int) $shop_two_de->id)?->name);

        self::assertSame(
            (int) $manufacturer_rows->count(),
            (int) $manufacturer_rows->pluck('shop_language_id')->unique()->count()
        );
        self::assertSame(
            (int) $brand_rows->count(),
            (int) $brand_rows->pluck('shop_language_id')->unique()->count()
        );
    }

    public function test_it_skips_manufacturer_and_brand_language_sync_when_no_active_languages(): void
    {
        $product_id = 1001;

        $manufacturer = Manufacturer::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        $brand = Brand::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer->id,
            'shop_language_id' => null,
            'name'             => 'Acme',
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand->id,
            'shop_language_id' => null,
            'name'             => 'Prime',
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod($service, 'synchronizeManufacturerDescriptionsForShopLanguages', [$product_id, 77]);
        $this->invokePrivateMethod($service, 'synchronizeBrandDescriptionsForShopLanguages', [$product_id, 77]);

        self::assertSame(1, ManufacturerDescription::query()->where('manufacturer_id', (int) $manufacturer->id)->count());
        self::assertSame(1, BrandDescription::query()->where('brand_id', (int) $brand->id)->count());
        self::assertNotNull(ManufacturerDescription::query()->where('manufacturer_id', (int) $manufacturer->id)->whereNull('shop_language_id')->first());
        self::assertNotNull(BrandDescription::query()->where('brand_id', (int) $brand->id)->whereNull('shop_language_id')->first());
    }

    public function test_it_invalidates_shop_scoped_option_cache_after_binding(): void
    {
        $target_shop_id = 22;
        $product_id     = 2002;

        Shop::query()->create([
            'id'        => $target_shop_id,
            'name'      => 'AvStore',
            'is_active' => true,
        ]);

        $default_shop_language = ShopLanguage::query()->create([
            'id'         => 501,
            'shop_id'    => $target_shop_id,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $product = Product::query()->create([
            'id'     => $product_id,
            'model'  => 'CACHE-TEST',
            'sku'    => 'CACHE-TEST',
            'price'  => 199.99,
            'ean'    => '1234567890',
            'image'  => 'catalog/cache-test.jpg',
            'is_active' => true,
        ]);

        ProductDescription::query()->create([
            'product_id'       => (int) $product->id,
            'shop_language_id' => null,
            'name'             => 'Cache test product',
            'description'      => 'Cache test product',
            'meta_title'       => 'Cache test product',
            'meta_description' => 'Cache test product',
            'meta_keywords'    => 'cache,test',
        ]);

        $category = Category::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $category->id,
            'shop_language_id' => null,
            'name'             => 'New category',
            'description'      => null,
            'h1_title'         => 'New category',
            'meta_title'       => 'New category',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);
        CategoryProduct::query()->create([
            'product_id'  => (int) $product->id,
            'category_id' => (int) $category->id,
        ]);

        $attribute = Attribute::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        AttributeDescription::query()->create([
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => null,
            'name'             => 'New attribute',
        ]);
        ProductToAttribute::query()->create([
            'product_id'       => (int) $product->id,
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => null,
            'text'             => 'Value',
        ]);

        $manufacturer = Manufacturer::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer->id,
            'shop_language_id' => null,
            'name'             => 'New manufacturer',
        ]);

        $brand = Brand::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand->id,
            'shop_language_id' => null,
            'name'             => 'New brand',
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => (int) $product->id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);

        $options_service = new ProductResourceOptionsService();

        self::assertSame([], $options_service->getManufacturerOptionsByScope($target_shop_id, (int) $default_shop_language->id));
        self::assertSame([], $options_service->getBrandOptionsByScope($target_shop_id, (int) $default_shop_language->id));
        self::assertSame([], $options_service->getCategoryOptionsByScope('shop', $target_shop_id, (int) $default_shop_language->id));
        self::assertSame([], $options_service->getAttributeOptionsByScope('shop', $target_shop_id, (int) $default_shop_language->id));

        $service = new ProductShopBindingService();
        $result = $service->bindProductToShopAndReturnTargetProduct(
            (int) $product->id,
            $target_shop_id,
            99,
        );

        self::assertSame(1, (int) $result['bound']);
        self::assertSame(0, (int) $result['duplicated']);
        self::assertSame(1, (int) $result['catalog_sync_summary']['categories_assigned']);
        self::assertSame(1, (int) $result['catalog_sync_summary']['attributes_assigned']);
        self::assertSame(1, (int) $result['catalog_sync_summary']['manufacturers_assigned']);
        self::assertSame(1, (int) $result['catalog_sync_summary']['brands_assigned']);

        self::assertSame($target_shop_id, (int) (Category::query()->whereKey((int) $category->id)->value('shop_id') ?? 0));
        self::assertSame($target_shop_id, (int) (Attribute::query()->whereKey((int) $attribute->id)->value('shop_id') ?? 0));
        self::assertSame($target_shop_id, (int) (Manufacturer::query()->whereKey((int) $manufacturer->id)->value('shop_id') ?? 0));
        self::assertSame($target_shop_id, (int) (Brand::query()->whereKey((int) $brand->id)->value('shop_id') ?? 0));

        self::assertTrue(CategoryShop::query()->where('category_id', (int) $category->id)->where('shop_id', $target_shop_id)->exists());
        self::assertTrue(AttributeShop::query()->where('attribute_id', (int) $attribute->id)->where('shop_id', $target_shop_id)->exists());
        self::assertTrue(ManufacturerShop::query()->where('manufacturer_id', (int) $manufacturer->id)->where('shop_id', $target_shop_id)->exists());
        self::assertTrue(BrandShop::query()->where('brand_id', (int) $brand->id)->where('shop_id', $target_shop_id)->exists());

        self::assertSame(
            'New category',
            CategoryDescription::query()->where('category_id', (int) $category->id)->where('shop_language_id', (int) $default_shop_language->id)->value('name')
        );
        self::assertSame(
            'New attribute',
            AttributeDescription::query()->where('attribute_id', (int) $attribute->id)->where('shop_language_id', (int) $default_shop_language->id)->value('name')
        );
        self::assertSame(
            'New manufacturer',
            ManufacturerDescription::query()->where('manufacturer_id', (int) $manufacturer->id)->where('shop_language_id', (int) $default_shop_language->id)->value('name')
        );
        self::assertSame(
            'New brand',
            BrandDescription::query()->where('brand_id', (int) $brand->id)->where('shop_language_id', (int) $default_shop_language->id)->value('name')
        );

        self::assertSame(
            [(int) $manufacturer->id => 'New manufacturer'],
            $options_service->getManufacturerOptionsByScope($target_shop_id, (int) $default_shop_language->id)
        );
        self::assertSame(
            [(int) $brand->id => 'New brand'],
            $options_service->getBrandOptionsByScope($target_shop_id, (int) $default_shop_language->id)
        );
        self::assertSame(
            [(int) $category->id => 'New category'],
            $options_service->getCategoryOptionsByScope('shop', $target_shop_id, (int) $default_shop_language->id)
        );
        self::assertSame(
            [(int) $attribute->id => 'New attribute'],
            $options_service->getAttributeOptionsByScope('shop', $target_shop_id, (int) $default_shop_language->id)
        );
    }

    public function test_it_uses_readable_fallback_labels_instead_of_raw_ids_for_pre_bind_options(): void
    {
        ShopLanguage::query()->create([
            'id'         => 11,
            'shop_id'    => 1,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);
        ShopLanguage::query()->create([
            'id'         => 12,
            'shop_id'    => 2,
            'code'       => 'en',
            'name'       => 'English',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $manufacturer_generic = Manufacturer::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer_generic->id,
            'shop_language_id' => null,
            'name'             => 'Strateg',
        ]);

        $manufacturer_shop_duplicate = Manufacturer::query()->create([
            'shop_id'    => 2,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer_shop_duplicate->id,
            'shop_language_id' => 12,
            'name'             => 'Strateg',
        ]);

        $brand_generic = Brand::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand_generic->id,
            'shop_language_id' => null,
            'name'             => 'Boobon',
        ]);

        $brand_shop_duplicate = Brand::query()->create([
            'shop_id'    => 2,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand_shop_duplicate->id,
            'shop_language_id' => 12,
            'name'             => 'Boobon',
        ]);

        $category = Category::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $category->id,
            'shop_language_id' => null,
            'name'             => 'Board games',
            'description'      => null,
            'h1_title'         => 'Board games',
            'meta_title'       => 'Board games',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $attribute = Attribute::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        AttributeDescription::query()->create([
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => 12,
            'name'             => 'Cable length',
        ]);

        $manufacturer_without_readable_name = Manufacturer::query()->create([
            'sort_order' => 3,
            'is_active'  => true,
        ]);

        $options_service = new ProductResourceOptionsService();

        self::assertSame(
            [
                (int) $manufacturer_generic->id => 'Strateg',
                (int) $manufacturer_without_readable_name->id => '#'.(int) $manufacturer_without_readable_name->id,
            ],
            $options_service->getManufacturerOptionsByScope(0, 11)
        );
        self::assertSame(
            [(int) $brand_generic->id => 'Boobon'],
            $options_service->getBrandOptionsByScope(0, 11)
        );
        self::assertSame(
            [(int) $category->id => 'Board games'],
            $options_service->getCategoryOptionsByScope('all', 0, 11)
        );
        self::assertSame(
            [(int) $attribute->id => 'Cable length'],
            $options_service->getAttributeOptionsByScope('all', 0, 11)
        );
        self::assertSame(
            [(int) $manufacturer_without_readable_name->id => '#'.(int) $manufacturer_without_readable_name->id],
            $options_service->getManufacturerLabelsByIds([(int) $manufacturer_without_readable_name->id], 11)
        );
    }

    public function test_it_keeps_selected_entity_ids_visible_even_when_they_fall_outside_scope_filters(): void
    {
        ShopLanguage::query()->create([
            'id'         => 21,
            'shop_id'    => 1,
            'code'       => 'uk',
            'name'       => 'Українська',
            'is_active'  => true,
            'is_default' => true,
        ]);

        $shop_scoped_manufacturer = Manufacturer::query()->create([
            'shop_id'    => 1,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $shop_scoped_manufacturer->id,
            'shop_language_id' => 21,
            'name'             => 'Strateg',
        ]);
        ManufacturerShop::query()->create([
            'manufacturer_id' => (int) $shop_scoped_manufacturer->id,
            'shop_id'         => 1,
        ]);

        $selected_unbound_manufacturer = Manufacturer::query()->create([
            'sort_order' => 2,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $selected_unbound_manufacturer->id,
            'shop_language_id' => null,
            'name'             => 'Boobon',
        ]);

        $shop_scoped_category = Category::query()->create([
            'shop_id'     => 1,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $shop_scoped_category->id,
            'shop_language_id' => 21,
            'name'             => 'Toys',
            'description'      => null,
            'h1_title'         => 'Toys',
            'meta_title'       => 'Toys',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);
        CategoryShop::query()->create([
            'category_id' => (int) $shop_scoped_category->id,
            'shop_id'     => 1,
        ]);

        $selected_unbound_category = Category::query()->create([
            'sort_order' => 2,
            'is_active'  => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $selected_unbound_category->id,
            'shop_language_id' => null,
            'name'             => 'Books',
            'description'      => null,
            'h1_title'         => 'Books',
            'meta_title'       => 'Books',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $options_service = new ProductResourceOptionsService();

        self::assertSame(
            [
                (int) $shop_scoped_manufacturer->id => 'Strateg',
                (int) $selected_unbound_manufacturer->id => 'Boobon',
            ],
            $options_service->getManufacturerOptionsByScope(1, 21, [(int) $selected_unbound_manufacturer->id])
        );

        self::assertSame(
            [
                (int) $shop_scoped_category->id => 'Toys',
                (int) $selected_unbound_category->id => 'Books',
            ],
            $options_service->getCategoryOptionsByScope('shop', 1, 21, [(int) $selected_unbound_category->id])
        );
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);

        return $method->invokeArgs($target, $arguments);
    }
}

final class FakeAiTranslationService
{
    public function attributeName(int $attribute_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function attributeDescription(int $attribute_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productName(int $product_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productDescription(int $product_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function categoryName(int $category_id, string $prompt): string
    {
        return 'category-'.$this->translateFromPrompt($prompt);
    }

    public function categoryDescription(int $category_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function brandName(int $brand_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function brandDescription(int $brand_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function manufacturerName(int $manufacturer_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function manufacturerDescription(int $manufacturer_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productAttributeText(int $product_id, int $attribute_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    private function translateFromPrompt(string $prompt): string
    {
        preg_match('/ to ([a-z]{2})\\./i', $prompt, $target_matches);
        $target_language_code = strtolower((string) ($target_matches[1] ?? 'uk'));

        $text = (string) preg_replace('/^.*\\n\\n/s', '', $prompt);

        return $text.' ['.$target_language_code.']';
    }
}
