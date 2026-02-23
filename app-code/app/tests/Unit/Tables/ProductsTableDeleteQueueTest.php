<?php

declare(strict_types=1);

namespace Tests\Unit\Tables;

use App\Filament\Resources\Catalog\Products\Tables\ProductsTable;
use App\Models\Products\Product;
use Illuminate\Contracts\Auth\Factory as AuthFactoryContract;
use Illuminate\Contracts\Auth\Guard as GuardContract;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductsTableDeleteQueueTest extends TestCase
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
        $auth_factory = new class() implements AuthFactoryContract, GuardContract
        {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name): void {}

            public function check(): bool
            {
                return false;
            }

            public function guest(): bool
            {
                return true;
            }

            public function hasUser(): bool
            {
                return false;
            }

            public function user()
            {
                return null;
            }

            public function id(): null
            {
                return null;
            }

            public function validate(array $credentials = []): bool
            {
                return false;
            }

            public function setUser($user): self
            {
                return $this;
            }
        };
        $container->instance('auth', $auth_factory);
        $container->instance(AuthFactoryContract::class, $auth_factory);
        $container->instance('log', new class()
        {
            public function channel(string $name = 'stack'): self
            {
                return $this;
            }

            public function info(string $message, array $context = []): void {}

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

        $schema->create('products', static function ($table): void {
            $table->increments('id');
            $table->string('model')->nullable();
            $table->timestamps();
        });

        $schema->create('shops', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
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

        $schema->create('product_delete_batches', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_name', 2000)->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 100)->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('processed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->text('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $schema->create('product_delete_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_delete_batch_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->text('payload')->nullable();
            $table->string('status', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$capsule?->table('product_delete_items')->delete();
        self::$capsule?->table('product_delete_batches')->delete();
        self::$capsule?->table('product_shop')->delete();
        self::$capsule?->table('shops')->delete();
        Product::query()->delete();
    }

    public function test_it_skips_non_deletable_bindings_during_bulk_delete_queue_from_products_table(): void
    {
        self::$capsule?->table('shops')->insert([
            'id'         => 10,
            'name'       => 'Shop #10',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $not_bound_product = Product::query()->create([
            'model' => 'NOT-BOUND',
        ]);

        $bound_without_external_id_product = Product::query()->create([
            'model' => 'WITHOUT-EXTERNAL',
        ]);

        self::$capsule?->table('product_shop')->insert([
            'product_id'          => (int) $bound_without_external_id_product->id,
            'shop_id'             => 10,
            'external_product_id' => null,
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        $summary = $this->invokeQueueDeleteForSelectedProducts(
            new EloquentCollection([$not_bound_product, $bound_without_external_id_product]),
            [10]
        );

        self::assertSame(2, (int) ($summary['products_total'] ?? 0));
        self::assertSame(1, (int) ($summary['shops_total'] ?? 0));
        self::assertSame(0, (int) ($summary['deletes_queued'] ?? 0));
        self::assertSame(1, (int) ($summary['skipped_not_bound'] ?? 0));
        self::assertSame(1, (int) ($summary['skipped_without_external_id'] ?? 0));

        self::assertSame(1, (int) self::$capsule?->table('product_delete_batches')->count());
        self::assertSame(0, (int) self::$capsule?->table('product_delete_items')->count());
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function invokeQueueDeleteForSelectedProducts(EloquentCollection $records, array $shop_ids): array
    {
        $method = new ReflectionMethod(ProductsTable::class, 'queueDeleteForSelectedProducts');

        /** @var array<string, int> $result */
        $result = $method->invoke(
            null,
            $records,
            $shop_ids
        );

        return $result;
    }
}
