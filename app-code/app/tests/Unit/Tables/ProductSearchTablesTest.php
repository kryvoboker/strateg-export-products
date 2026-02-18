<?php

declare(strict_types=1);

namespace Tests\Unit\Tables;

use App\Filament\Resources\Catalog\Products\Tables\ProductsTable;
use App\Filament\Resources\ProductImports\RelationManagers\ProductImportItemsRelationManager;
use App\Filament\Resources\ProductUpdates\RelationManagers\ProductUpdateItemsRelationManager;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\Updates\ProductUpdateItem;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ProductSearchTablesTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $container = new Container();
        Container::setInstance($container);
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

        $schema->create('products', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_item_id')->nullable();
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
        });

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('category_descriptions', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
        });

        $schema->create('category_product', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('category_id');
            $table->timestamps();
        });

        $schema->create('attributes', static function ($table): void {
            $table->increments('id');
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
        });

        $schema->create('product_to_attributes', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('attribute_id')->nullable();
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('text', 3000)->nullable();
            $table->timestamps();
        });

        $schema->create('product_import_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_batch_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->text('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        $schema->create('product_update_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_update_batch_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->text('payload')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        ProductUpdateItem::query()->delete();
        ProductImportItem::query()->delete();
        ProductToAttribute::query()->delete();
        AttributeDescription::query()->delete();
        Attribute::query()->delete();
        CategoryProduct::query()->delete();
        CategoryDescription::query()->delete();
        Category::query()->delete();
        ProductShop::query()->delete();
        ProductDescription::query()->delete();
        Product::query()->delete();
    }

    public function test_products_table_search_fields_support_all_requested_fields(): void
    {
        [$product_id] = $this->seedSearchData();

        $cases = [
            ['name' => 'alpha'],
            ['model' => 'mdl-1'],
            ['sku' => 'sku-1'],
            ['ean' => 'ean-1'],
            ['external_product_id' => '1001'],
            ['quantity' => '15'],
            ['price' => '99.99'],
            ['attribute_name' => 'color'],
            ['attribute_value' => 'red'],
            ['category_name' => 'electronics'],
        ];

        foreach ($cases as $case) {
            $matched_ids = ProductsTable::applySearchFieldsQuery(Product::query(), $case)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            self::assertSame([$product_id], $matched_ids);
        }
    }

    public function test_product_import_items_relation_manager_search_is_limited_to_batch_and_requested_fields(): void
    {
        [$product_id, $import_item_id] = $this->seedSearchData();

        $matched_ids = ProductImportItemsRelationManager::applyBatchSearchFieldsQuery(
            ProductImportItem::query(),
            [
                'name'                => 'alpha',
                'external_product_id' => '1001',
                'attribute_name'      => 'color',
                'category_name'       => 'electronics',
            ],
            10
        )
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertSame([$import_item_id], $matched_ids);

        $wrong_batch_ids = ProductImportItemsRelationManager::applyBatchSearchFieldsQuery(
            ProductImportItem::query(),
            ['name' => 'alpha'],
            99
        )->pluck('id')->all();

        self::assertSame([], $wrong_batch_ids);
        self::assertTrue(Product::query()->whereKey($product_id)->exists());
    }

    public function test_product_update_items_relation_manager_search_is_limited_to_batch_and_requested_fields(): void
    {
        [, , $update_item_id] = $this->seedSearchData();

        $matched_ids = ProductUpdateItemsRelationManager::applyBatchSearchFieldsQuery(
            ProductUpdateItem::query(),
            [
                'model'           => 'mdl-1',
                'attribute_value' => 'red',
                'category_name'   => 'electronics',
                'quantity'        => '15',
            ],
            30
        )
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        self::assertSame([$update_item_id], $matched_ids);

        $wrong_batch_ids = ProductUpdateItemsRelationManager::applyBatchSearchFieldsQuery(
            ProductUpdateItem::query(),
            ['model' => 'mdl-1'],
            77
        )->pluck('id')->all();

        self::assertSame([], $wrong_batch_ids);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function seedSearchData(): array
    {
        $product = Product::query()->create([
            'model'     => 'MDL-1',
            'sku'       => 'SKU-1',
            'ean'       => 'EAN-1',
            'quantity'  => 15,
            'minimum'   => 1,
            'price'     => 99.99,
            'is_active' => true,
        ]);

        $other_product = Product::query()->create([
            'model'     => 'MDL-2',
            'sku'       => 'SKU-2',
            'ean'       => 'EAN-2',
            'quantity'  => 7,
            'minimum'   => 1,
            'price'     => 49.99,
            'is_active' => true,
        ]);

        ProductDescription::query()->create([
            'product_id'  => (int) $product->id,
            'name'        => 'Alpha phone',
            'description' => 'Alpha description',
        ]);

        ProductDescription::query()->create([
            'product_id'  => (int) $other_product->id,
            'name'        => 'Beta phone',
            'description' => 'Beta description',
        ]);

        $category = Category::query()->create([
            'is_active' => true,
        ]);

        CategoryDescription::query()->create([
            'category_id' => (int) $category->id,
            'name'        => 'Electronics',
        ]);

        CategoryProduct::query()->create([
            'product_id'  => (int) $product->id,
            'category_id' => (int) $category->id,
        ]);

        $attribute = Attribute::query()->create([
            'is_active' => true,
        ]);

        AttributeDescription::query()->create([
            'attribute_id' => (int) $attribute->id,
            'name'         => 'Color',
        ]);

        ProductToAttribute::query()->create([
            'product_id'   => (int) $product->id,
            'attribute_id' => (int) $attribute->id,
            'text'         => 'Red',
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => 10,
            'product_id'              => (int) $product->id,
            'shop_id'                 => 1,
            'external_product_id'     => 1001,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => 11,
            'product_id'              => (int) $other_product->id,
            'shop_id'                 => 2,
            'external_product_id'     => 2002,
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => 10,
            'product_id'              => (int) $product->id,
            'payload'                 => [],
            'status'                  => 'successed',
        ]);

        ProductImportItem::query()->create([
            'product_import_batch_id' => 11,
            'product_id'              => (int) $other_product->id,
            'payload'                 => [],
            'status'                  => 'successed',
        ]);

        $product->update([
            'product_import_item_id' => (int) $import_item->id,
        ]);

        $other_product->update([
            'product_import_item_id' => (int) ProductImportItem::query()
                ->where('product_id', (int) $other_product->id)
                ->value('id'),
        ]);

        $update_item = ProductUpdateItem::query()->create([
            'product_update_batch_id' => 30,
            'product_id'              => (int) $product->id,
            'payload'                 => ['shop_id' => 1],
            'status'                  => 'successed',
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => 31,
            'product_id'              => (int) $other_product->id,
            'payload'                 => ['shop_id' => 2],
            'status'                  => 'successed',
        ]);

        return [(int) $product->id, (int) $import_item->id, (int) $update_item->id];
    }
}

