<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\RelationManagers;

use App\Filament\Resources\ProductImports\RelationManagers\ProductImportItemsRelationManager;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\Updates\ProductBackups;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductImportItemsRestoreFromBackupTest extends TestCase
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
        $container->instance('log', new class()
        {
            public function channel(string $channel_name): self
            {
                return $this;
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function info(string $message, array $context = []): void {}

            /**
             * @param  array<string, mixed>  $context
             */
            public function warning(string $message, array $context = []): void {}

            /**
             * @param  array<string, mixed>  $context
             */
            public function error(string $message, array $context = []): void {}
        });

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

        $schema->create('product_backups', static function ($table): void {
            $table->increments('id');
            $table->string('backupable_type')->nullable();
            $table->unsignedInteger('backupable_id')->nullable();
            $table->string('backup_source', 100)->nullable();
            $table->string('backup_kind', 150)->nullable();
            $table->unsignedInteger('shop_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->text('payload')->nullable();
            $table->boolean('is_used')->default(false);
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

        $schema->create('product_images', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $schema->create('categories', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
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
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
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

        $schema->create('seo_urls', static function ($table): void {
            $table->increments('id');
            $table->string('seoable_type')->nullable();
            $table->unsignedInteger('seoable_id')->nullable();
            $table->unsignedInteger('shop_language_id')->nullable();
            $table->string('query_key')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $schema->create('product_specials', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('user_group_id')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('date_start')->nullable();
            $table->timestamp('date_end')->nullable();
            $table->timestamps();
        });

        $schema->create('product_discounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('user_group_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('date_start')->nullable();
            $table->timestamp('date_end')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        ProductDescription::query()->delete();
        ProductImportItem::query()->delete();
        ProductBackups::query()->delete();
        Product::query()->delete();
    }

    public function test_restore_action_visibility_depends_on_local_backup_presence(): void
    {
        $product = Product::query()->create([
            'model' => 'M-OLD',
        ]);

        self::assertFalse(ProductImportItemsRelationManager::canShowRestoreFromBackupAction((int) $product->id));

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'product' => [
                    'model' => 'M-NEW',
                ],
            ],
            'is_used' => false,
        ]);

        self::assertTrue(ProductImportItemsRelationManager::canShowRestoreFromBackupAction((int) $product->id));
    }

    public function test_restore_action_visibility_is_false_for_invalid_backup_payload(): void
    {
        $product = Product::query()->create([
            'model' => 'M-OLD',
        ]);

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'invalid' => 'payload',
            ],
            'is_used' => false,
        ]);

        self::assertFalse(ProductImportItemsRelationManager::canShowRestoreFromBackupAction((int) $product->id));
    }

    public function test_restore_action_method_restores_product_and_marks_backup_as_used(): void
    {
        $product = Product::query()->create([
            'model'     => 'M-OLD',
            'sku'       => 'SKU-OLD',
            'quantity'  => 1,
            'minimum'   => 1,
            'price'     => 10,
            'is_active' => false,
        ]);

        ProductDescription::query()->create([
            'product_id'       => (int) $product->id,
            'shop_language_id' => 1,
            'name'             => 'Old Name',
        ]);

        $backup = ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'product' => [
                    'product_import_item_id' => null,
                    'marked_to_shop'         => '1',
                    'model'                  => 'M-RESTORED',
                    'sku'                    => 'SKU-RESTORED',
                    'ean'                    => 'EAN-RESTORED',
                    'quantity'               => 25,
                    'minimum'                => 2,
                    'image'                  => 'restored.jpg',
                    'price'                  => '120.55',
                    'is_active'              => true,
                    'date_available'         => null,
                    'date_added'             => null,
                ],
                'descriptions' => [
                    [
                        'shop_language_id' => 1,
                        'name'             => 'Restored Name',
                        'description'      => 'Restored Description',
                        'meta_title'       => 'Meta title',
                        'meta_description' => 'Meta description',
                        'meta_keywords'    => 'Meta keywords',
                    ],
                ],
            ],
            'is_used' => false,
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => 1,
            'product_id'              => (int) $product->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        app()->bind(ProductBackupRestoreService::class, ProductBackupRestoreService::class);

        $restored_backup = ProductImportItemsRelationManager::restoreProductFromLatestBackup($import_item);

        $product->refresh();

        self::assertSame('M-RESTORED', (string) $product->model);
        self::assertSame('SKU-RESTORED', (string) $product->sku);
        self::assertSame('EAN-RESTORED', (string) $product->ean);
        self::assertSame(25, (int) $product->quantity);
        self::assertSame(2, (int) $product->minimum);
        self::assertSame('restored.jpg', (string) $product->image);
        self::assertTrue((bool) $product->is_active);

        $name = ProductDescription::query()
            ->where('product_id', (int) $product->id)
            ->value('name');

        self::assertSame('Restored Name', (string) $name);
        self::assertTrue((bool) $restored_backup->is_used);
        self::assertSame((int) $backup->id, (int) $restored_backup->id);
    }

    public function test_restore_action_method_throws_when_backup_is_missing(): void
    {
        $product = Product::query()->create([
            'model' => 'M-OLD',
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => 1,
            'product_id'              => (int) $product->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Valid local product backup not found');

        ProductImportItemsRelationManager::restoreProductFromLatestBackup($import_item);
    }

    public function test_restore_action_method_throws_when_backup_payload_is_invalid(): void
    {
        $product = Product::query()->create([
            'model' => 'M-OLD',
        ]);

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'invalid' => 'payload',
            ],
            'is_used' => false,
        ]);

        $import_item = ProductImportItem::query()->create([
            'product_import_batch_id' => 1,
            'product_id'              => (int) $product->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Valid local product backup not found');

        ProductImportItemsRelationManager::restoreProductFromLatestBackup($import_item);
    }
}
