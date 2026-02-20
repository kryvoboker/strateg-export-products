<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Shops\Shop;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class CatalogEntityShopScopedDuplicationTest extends TestCase
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

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        $schema = self::$capsule->schema();

        $schema->create('shops', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('attribute_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('attribute_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_language_id']);
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
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

        $schema->create('manufacturers', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
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

        $schema->create('brands', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
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
    }

    protected function setUp(): void
    {
        parent::setUp();

        BrandDescription::query()->delete();
        Brand::query()->delete();
        ManufacturerDescription::query()->delete();
        Manufacturer::query()->delete();
        CategoryDescription::query()->delete();
        Category::query()->delete();
        AttributeDescription::query()->delete();
        Attribute::query()->delete();
        Shop::query()->delete();
    }

    public function test_entities_are_duplicated_per_shop_and_reused_for_same_shop(): void
    {
        $shop_a = Shop::query()->create(['name' => 'Shop A', 'is_active' => true]);
        $shop_b = Shop::query()->create(['name' => 'Shop B', 'is_active' => true]);

        $attribute = Attribute::query()->create([
            'shop_id'    => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        AttributeDescription::query()->create([
            'attribute_id'     => (int) $attribute->id,
            'shop_language_id' => null,
            'name'             => 'Color',
        ]);

        $attribute_shop_a = $attribute->duplicateForShop((int) $shop_a->id);
        $attribute_shop_b = $attribute->duplicateForShop((int) $shop_b->id);
        $attribute_shop_a_again = $attribute->duplicateForShop((int) $shop_a->id);

        self::assertSame((int) $attribute->id, (int) $attribute_shop_a->id);
        self::assertNotSame((int) $attribute_shop_a->id, (int) $attribute_shop_b->id);
        self::assertSame((int) $attribute_shop_a->id, (int) $attribute_shop_a_again->id);
        self::assertSame((int) $shop_a->id, (int) $attribute_shop_a->shop_id);
        self::assertSame((int) $shop_b->id, (int) $attribute_shop_b->shop_id);
        self::assertSame((string) $attribute->getAttribute('family_ulid'), (string) $attribute_shop_a->getAttribute('family_ulid'));
        self::assertSame((string) $attribute->getAttribute('family_ulid'), (string) $attribute_shop_b->getAttribute('family_ulid'));

        $parent_category = Category::query()->create([
            'shop_id'    => null,
            'parent_id'  => null,
            'sort_order' => 0,
            'is_active'  => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $parent_category->id,
            'shop_language_id' => null,
            'name'             => 'Toys',
            'description'      => null,
            'h1_title'         => 'Toys',
            'meta_title'       => 'Toys',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $child_category = Category::query()->create([
            'shop_id'    => null,
            'parent_id'  => (int) $parent_category->id,
            'sort_order' => 0,
            'is_active'  => true,
        ]);
        CategoryDescription::query()->create([
            'category_id'      => (int) $child_category->id,
            'shop_language_id' => null,
            'name'             => 'Board Games',
            'description'      => null,
            'h1_title'         => 'Board Games',
            'meta_title'       => 'Board Games',
            'meta_description' => null,
            'meta_keywords'    => null,
        ]);

        $parent_category_shop_a = $parent_category->duplicateForShop((int) $shop_a->id);
        $child_category_shop_a = $child_category->duplicateForShop((int) $shop_a->id, (int) $parent_category_shop_a->id);
        $child_category_shop_a_again = $child_category->duplicateForShop((int) $shop_a->id, (int) $parent_category_shop_a->id);

        self::assertSame((int) $parent_category->id, (int) $parent_category_shop_a->id);
        self::assertSame((int) $child_category->id, (int) $child_category_shop_a->id);
        self::assertSame((int) $child_category_shop_a->id, (int) $child_category_shop_a_again->id);
        self::assertSame((int) $parent_category_shop_a->id, (int) $child_category_shop_a->parent_id);
        self::assertSame((int) $shop_a->id, (int) $child_category_shop_a->shop_id);

        $manufacturer = Manufacturer::query()->create([
            'shop_id'    => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        ManufacturerDescription::query()->create([
            'manufacturer_id'  => (int) $manufacturer->id,
            'shop_language_id' => null,
            'name'             => 'Acme',
        ]);

        $manufacturer_shop_a = $manufacturer->duplicateForShop((int) $shop_a->id);
        $manufacturer_shop_b = $manufacturer->duplicateForShop((int) $shop_b->id);
        self::assertSame((int) $manufacturer->id, (int) $manufacturer_shop_a->id);
        self::assertNotSame((int) $manufacturer_shop_a->id, (int) $manufacturer_shop_b->id);
        self::assertSame((int) $shop_a->id, (int) $manufacturer_shop_a->shop_id);
        self::assertSame((int) $shop_b->id, (int) $manufacturer_shop_b->shop_id);

        $brand = Brand::query()->create([
            'shop_id'    => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);
        BrandDescription::query()->create([
            'brand_id'         => (int) $brand->id,
            'shop_language_id' => null,
            'name'             => 'Prime',
        ]);

        $brand_shop_a = $brand->duplicateForShop((int) $shop_a->id);
        $brand_shop_b = $brand->duplicateForShop((int) $shop_b->id);
        self::assertSame((int) $brand->id, (int) $brand_shop_a->id);
        self::assertNotSame((int) $brand_shop_a->id, (int) $brand_shop_b->id);
        self::assertSame((int) $shop_a->id, (int) $brand_shop_a->shop_id);
        self::assertSame((int) $shop_b->id, (int) $brand_shop_b->shop_id);
        self::assertSame((string) $brand->getAttribute('family_ulid'), (string) $brand_shop_a->getAttribute('family_ulid'));
    }
}
