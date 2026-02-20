<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Products\ProductToAttribute;
use App\Models\Shops\ShopLanguage;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Catalog\ProductShopBindingService;
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
        ]));

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

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
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

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
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

        $schema->create('manufacturers', static function ($table): void {
            $table->increments('id');
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

        $schema->create('brands', static function ($table): void {
            $table->increments('id');
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

        $schema->create('product_to_manufacturer_brand', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('manufacturer_id')->nullable();
            $table->unsignedInteger('brand_id')->nullable();
            $table->timestamps();
            $table->unique('product_id');
        });

        $schema->create('seo_urls', static function ($table): void {
            $table->increments('id');
            $table->string('seoable_type');
            $table->unsignedInteger('seoable_id');
            $table->unsignedInteger('shop_language_id')->nullable();
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
            'category_id'       => (int) $category->id,
            'shop_language_id'  => null,
            'name'              => 'Board games',
            'description'       => null,
            'h1_title'          => 'Board games',
            'meta_title'        => 'Board games',
            'meta_description'  => null,
            'meta_keywords'     => null,
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

    public function test_it_uses_fake_translation_prefix_when_ai_translation_is_disabled(): void
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
        self::assertSame('translated-to-uk-Phone', (string) $uk_product_description->name);
        self::assertSame('translated-to-uk-Smart phone', (string) $uk_product_description->description);

        $uk_attribute_name = AttributeDescription::query()
            ->where('attribute_id', (int) $source_attribute->id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->value('name');

        self::assertSame('translated-to-uk-Color', (string) $uk_attribute_name);

        $uk_attribute_text = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('attribute_id', (int) $source_attribute->id)
            ->where('shop_language_id', (int) $uk_language->id)
            ->value('text');

        self::assertSame('translated-to-uk-Red', (string) $uk_attribute_text);
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

    public function productName(int $product_id, string $prompt): string
    {
        return $this->translateFromPrompt($prompt);
    }

    public function productDescription(int $product_id, string $prompt): string
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
