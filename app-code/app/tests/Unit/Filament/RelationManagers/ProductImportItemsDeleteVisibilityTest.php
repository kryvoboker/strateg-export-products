<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\RelationManagers;

use App\Filament\Resources\ProductImports\RelationManagers\ProductImportItemsRelationManager;
use App\Models\Products\Imports\ProductImportItem;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductImportItemsDeleteVisibilityTest extends TestCase
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

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });

        $schema->create('shops', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$capsule?->table('product_shop')->delete();
        self::$capsule?->table('shops')->delete();
    }

    public function test_delete_action_visibility_and_shop_options_depend_on_external_product_id(): void
    {
        self::$capsule?->table('shops')->insert([
            [
                'id'         => 10,
                'name'       => 'Shop #10',
                'is_active'  => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id'         => 20,
                'name'       => 'Shop #20',
                'is_active'  => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        self::$capsule?->table('product_shop')->insert([
            [
                'product_id'          => 1,
                'shop_id'             => 10,
                'external_product_id' => 111,
                'created_at'          => date('Y-m-d H:i:s'),
                'updated_at'          => date('Y-m-d H:i:s'),
            ],
            [
                'product_id'          => 1,
                'shop_id'             => 20,
                'external_product_id' => null,
                'created_at'          => date('Y-m-d H:i:s'),
                'updated_at'          => date('Y-m-d H:i:s'),
            ],
        ]);

        $manager = new ProductImportItemsRelationManager();
        $record_with_external_id = new ProductImportItem(['product_id' => 1]);
        $record_without_external_id = new ProductImportItem(['product_id' => 2]);

        self::assertTrue($this->invokeHasExternalProductIdForRecord($manager, $record_with_external_id));
        self::assertFalse($this->invokeHasExternalProductIdForRecord($manager, $record_without_external_id));

        $shop_options = $this->invokeResolveDeleteShopOptionsForRecord($manager, $record_with_external_id);
        self::assertSame([10 => 'Shop #10'], $shop_options);
    }

    private function invokeHasExternalProductIdForRecord(ProductImportItemsRelationManager $manager, ProductImportItem $record): bool
    {
        $method = new ReflectionMethod(ProductImportItemsRelationManager::class, 'hasExternalProductIdForRecord');

        return (bool) $method->invoke($manager, $record);
    }

    /**
     * @return array<int, string>
     */
    private function invokeResolveDeleteShopOptionsForRecord(ProductImportItemsRelationManager $manager, ProductImportItem $record): array
    {
        $method = new ReflectionMethod(ProductImportItemsRelationManager::class, 'resolveDeleteShopOptionsForRecord');

        /** @var array<int, string> $result */
        $result = $method->invoke($manager, $record);

        return $result;
    }
}
