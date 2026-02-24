<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Attributes\Attribute;
use App\Models\Brands\Brand;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryProduct;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Supports\Services\Products\ProductShopBindingService;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductShopBindingServiceExternalMappingsTest extends TestCase
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
        $container->instance('db', self::$capsule->getDatabaseManager());

        $schema = self::$capsule->schema();

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
            $table->unsignedInteger('external_category_id')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_id']);
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('product_to_attributes', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('attribute_id')->nullable();
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
        });

        $schema->create('attribute_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('attribute_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('external_attribute_id')->nullable();
            $table->timestamps();
            $table->unique(['attribute_id', 'shop_id']);
        });

        $schema->create('manufacturers', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('manufacturer_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedBigInteger('external_manufacturer_id')->nullable();
            $table->timestamps();
            $table->unique(['manufacturer_id', 'shop_id']);
        });
        $schema->create('manufacturer_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('manufacturer_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->text('name')->nullable();
            $table->timestamps();
        });

        $schema->create('brands', static function ($table): void {
            $table->increments('id');
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('brand_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedBigInteger('external_brand_id')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'shop_id']);
        });
        $schema->create('brand_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('brand_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->text('name')->nullable();
            $table->timestamps();
        });

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('external_product_id')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'shop_id']);
        });

        $schema->create('product_to_manufacturer_brand', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('manufacturer_id')->nullable();
            $table->unsignedInteger('brand_id')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$capsule?->table('product_shop')->delete();
        ProductToManufacturerBrand::query()->delete();
        self::$capsule?->table('manufacturer_shop')->delete();
        self::$capsule?->table('brand_shop')->delete();
        self::$capsule?->table('manufacturer_descriptions')->delete();
        self::$capsule?->table('brand_descriptions')->delete();
        Manufacturer::query()->delete();
        Brand::query()->delete();
        ProductToAttribute::query()->delete();
        self::$capsule?->table('attribute_shop')->delete();
        Attribute::query()->delete();
        CategoryProduct::query()->delete();
        self::$capsule?->table('category_shop')->delete();
        Category::query()->delete();
    }

    public function test_it_creates_external_mapping_rows_only_for_entities_in_target_shop_scope(): void
    {
        $product_id = 1001;
        $shop_id    = 10;

        self::$capsule?->table('product_shop')->insert([
            'product_id'              => $product_id,
            'shop_id'                 => $shop_id,
            'product_import_batch_id' => null,
            'external_product_id'     => null,
            'created_at'              => now()->toDateTimeString(),
            'updated_at'              => now()->toDateTimeString(),
        ]);

        $category_ok = Category::query()->create([
            'family_ulid' => '01KHSCOPECATEGORY0000000001',
            'shop_id'     => $shop_id,
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ]);
        $category_other = Category::query()->create([
            'family_ulid' => '01KHSCOPECATEGORY0000000002',
            'shop_id'     => 20,
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ]);

        CategoryProduct::query()->create(['product_id' => $product_id, 'category_id' => (int) $category_ok->id]);
        CategoryProduct::query()->create(['product_id' => $product_id, 'category_id' => (int) $category_other->id]);

        $attribute_ok = Attribute::query()->create([
            'family_ulid' => '01KHSCOPEATTRIBUTE000000001',
            'shop_id'     => $shop_id,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
        $attribute_other = Attribute::query()->create([
            'family_ulid' => '01KHSCOPEATTRIBUTE000000002',
            'shop_id'     => 20,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);

        ProductToAttribute::query()->create(['product_id' => $product_id, 'attribute_id' => (int) $attribute_ok->id, 'shop_language_id' => 1, 'text' => 'ok']);
        ProductToAttribute::query()->create(['product_id' => $product_id, 'attribute_id' => (int) $attribute_other->id, 'shop_language_id' => 1, 'text' => 'skip']);

        $manufacturer_ok = Manufacturer::query()->create([
            'family_ulid' => '01KHSCOPEMANUF000000000001',
            'shop_id'     => $shop_id,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
        $brand_ok = Brand::query()->create([
            'family_ulid' => '01KHSCOPEBRAND000000000001',
            'shop_id'     => $shop_id,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer_ok->id,
            'brand_id'        => (int) $brand_ok->id,
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod($service, 'ensureShopLinksForProduct', [$product_id, $shop_id]);

        self::assertSame(1, self::$capsule?->table('category_shop')->where('shop_id', $shop_id)->count());
        self::assertSame((int) $category_ok->id, (int) (self::$capsule?->table('category_shop')->where('shop_id', $shop_id)->value('category_id') ?? 0));

        self::assertSame(1, self::$capsule?->table('attribute_shop')->where('shop_id', $shop_id)->count());
        self::assertSame((int) $attribute_ok->id, (int) (self::$capsule?->table('attribute_shop')->where('shop_id', $shop_id)->value('attribute_id') ?? 0));

        self::assertSame(1, self::$capsule?->table('manufacturer_shop')->where('shop_id', $shop_id)->count());
        self::assertSame((int) $manufacturer_ok->id, (int) (self::$capsule?->table('manufacturer_shop')->where('shop_id', $shop_id)->value('manufacturer_id') ?? 0));

        self::assertSame(1, self::$capsule?->table('brand_shop')->where('shop_id', $shop_id)->count());
        self::assertSame((int) $brand_ok->id, (int) (self::$capsule?->table('brand_shop')->where('shop_id', $shop_id)->value('brand_id') ?? 0));
    }

    public function test_it_skips_external_mapping_when_product_is_not_bound_to_shop(): void
    {
        $product_id = 2001;
        $shop_id    = 15;

        $category = Category::query()->create([
            'family_ulid' => '01KHSCOPECATEGORYSKIP0000001',
            'shop_id'     => $shop_id,
            'parent_id'   => null,
            'sort_order'  => 0,
            'is_active'   => true,
        ]);
        CategoryProduct::query()->create([
            'product_id'  => $product_id,
            'category_id' => (int) $category->id,
        ]);

        $service = new ProductShopBindingService();
        $this->invokePrivateMethod($service, 'ensureShopLinksForProduct', [$product_id, $shop_id]);

        self::assertSame(0, self::$capsule?->table('category_shop')->count());
        self::assertSame(0, self::$capsule?->table('attribute_shop')->count());
        self::assertSame(0, self::$capsule?->table('manufacturer_shop')->count());
        self::assertSame(0, self::$capsule?->table('brand_shop')->count());
    }

    public function test_it_assigns_unbound_manufacturer_and_brand_to_shop_without_duplication(): void
    {
        $product_id = 3001;
        $shop_id    = 10;

        self::$capsule?->table('product_shop')->insert([
            'product_id'              => $product_id,
            'shop_id'                 => $shop_id,
            'product_import_batch_id' => null,
            'external_product_id'     => null,
            'created_at'              => now()->toDateTimeString(),
            'updated_at'              => now()->toDateTimeString(),
        ]);

        $manufacturer = Manufacturer::query()->create([
            'family_ulid' => '01KHTESTMANUFASSIGN000000001',
            'shop_id'     => null,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
        $brand = Brand::query()->create([
            'family_ulid' => '01KHTESTBRANDASSIGN000000001',
            'shop_id'     => null,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);

        $service = new ProductShopBindingService();
        $summary = $this->invokePrivateMethod($service, 'ensureShopScopedCatalogEntitiesForProduct', [$product_id, $shop_id]);

        self::assertIsArray($summary);
        self::assertSame(1, (int) ($summary['manufacturers_assigned'] ?? 0));
        self::assertSame(0, (int) ($summary['manufacturers_created'] ?? 0));
        self::assertSame(1, (int) ($summary['brands_assigned'] ?? 0));
        self::assertSame(0, (int) ($summary['brands_created'] ?? 0));

        self::assertSame(1, Manufacturer::query()->count());
        self::assertSame(1, Brand::query()->count());
        self::assertSame($shop_id, (int) (Manufacturer::query()->whereKey((int) $manufacturer->id)->value('shop_id') ?? 0));
        self::assertSame($shop_id, (int) (Brand::query()->whereKey((int) $brand->id)->value('shop_id') ?? 0));

        $binding = ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->first();

        self::assertNotNull($binding);
        self::assertSame((int) $manufacturer->id, (int) ($binding?->manufacturer_id ?? 0));
        self::assertSame((int) $brand->id, (int) ($binding?->brand_id ?? 0));
    }

    public function test_it_duplicates_manufacturer_and_brand_when_they_are_bound_to_another_shop(): void
    {
        $product_id = 3002;
        $shop_id    = 10;

        self::$capsule?->table('product_shop')->insert([
            'product_id'              => $product_id,
            'shop_id'                 => $shop_id,
            'product_import_batch_id' => null,
            'external_product_id'     => null,
            'created_at'              => now()->toDateTimeString(),
            'updated_at'              => now()->toDateTimeString(),
        ]);

        $manufacturer = Manufacturer::query()->create([
            'family_ulid' => '01KHTESTMANUFDUPLICATE000001',
            'shop_id'     => 20,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);
        $brand = Brand::query()->create([
            'family_ulid' => '01KHTESTBRANDDUPLICATE000001',
            'shop_id'     => 20,
            'sort_order'  => 1,
            'is_active'   => true,
        ]);

        ProductToManufacturerBrand::query()->create([
            'product_id'      => $product_id,
            'manufacturer_id' => (int) $manufacturer->id,
            'brand_id'        => (int) $brand->id,
        ]);

        $service = new ProductShopBindingService();
        $summary = $this->invokePrivateMethod($service, 'ensureShopScopedCatalogEntitiesForProduct', [$product_id, $shop_id]);

        self::assertIsArray($summary);
        self::assertSame(1, (int) ($summary['manufacturer_relinked'] ?? 0));
        self::assertSame(1, (int) ($summary['manufacturers_created'] ?? 0));
        self::assertSame(1, (int) ($summary['brand_relinked'] ?? 0));
        self::assertSame(1, (int) ($summary['brands_created'] ?? 0));

        self::assertSame(2, Manufacturer::query()->count());
        self::assertSame(2, Brand::query()->count());

        $target_manufacturer_id = (int) (Manufacturer::query()
            ->where('family_ulid', '01KHTESTMANUFDUPLICATE000001')
            ->where('shop_id', $shop_id)
            ->value('id') ?? 0);
        $target_brand_id = (int) (Brand::query()
            ->where('family_ulid', '01KHTESTBRANDDUPLICATE000001')
            ->where('shop_id', $shop_id)
            ->value('id') ?? 0);

        self::assertGreaterThan(0, $target_manufacturer_id);
        self::assertGreaterThan(0, $target_brand_id);
        self::assertNotSame((int) $manufacturer->id, $target_manufacturer_id);
        self::assertNotSame((int) $brand->id, $target_brand_id);

        $binding = ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->first();

        self::assertNotNull($binding);
        self::assertSame($target_manufacturer_id, (int) ($binding?->manufacturer_id ?? 0));
        self::assertSame($target_brand_id, (int) ($binding?->brand_id ?? 0));
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
