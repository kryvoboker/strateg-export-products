<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Models\Products\ProductShop;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EditProductExternalProductIdTest extends TestCase
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
            public function channel(string $name = 'stack'): self
            {
                return $this;
            }

            public function warning(string $message, array $context = []): void {}

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

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        ProductShop::query()->delete();
    }

    public function test_it_updates_external_product_id_only_for_selected_shop_binding(): void
    {
        ProductShop::query()->create([
            'product_id'          => 100,
            'shop_id'             => 10,
            'external_product_id' => 555,
        ]);

        ProductShop::query()->create([
            'product_id'          => 100,
            'shop_id'             => 20,
            'external_product_id' => 777,
        ]);

        $page = new EditProduct();

        $this->invokeSyncExternalProductIdForSelectedShop($page, 100, [
            'bind_shop_id'        => 10,
            'external_product_id' => 9999,
        ]);

        self::assertSame(
            9999,
            (int) ProductShop::query()->where('product_id', 100)->where('shop_id', 10)->value('external_product_id')
        );
        self::assertSame(
            777,
            (int) ProductShop::query()->where('product_id', 100)->where('shop_id', 20)->value('external_product_id')
        );
    }

    public function test_it_sets_external_product_id_to_null_when_value_is_empty(): void
    {
        ProductShop::query()->create([
            'product_id'          => 200,
            'shop_id'             => 11,
            'external_product_id' => 333,
        ]);

        $page = new EditProduct();

        $this->invokeSyncExternalProductIdForSelectedShop($page, 200, [
            'bind_shop_id'        => 11,
            'external_product_id' => '',
        ]);

        self::assertNull(
            ProductShop::query()->where('product_id', 200)->where('shop_id', 11)->value('external_product_id')
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function invokeSyncExternalProductIdForSelectedShop(EditProduct $page, int $product_id, array $data): void
    {
        $method = new ReflectionMethod(EditProduct::class, 'syncExternalProductIdForSelectedShop');
        $method->invoke($page, $product_id, $data);
    }
}
