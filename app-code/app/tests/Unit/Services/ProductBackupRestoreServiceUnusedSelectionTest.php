<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Products\Product;
use App\Models\Products\Updates\ProductBackups;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ProductBackupRestoreServiceUnusedSelectionTest extends TestCase
{
    private static ?Capsule $capsule = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        $container = new Application(__DIR__);
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository([
            'database' => [
                'db_prefix' => '',
            ],
            'app' => [
                'product_backups_max_per_scope' => 15,
            ],
        ]));
        $container->instance('log', new class
        {
            public function channel(string $name): self
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
        $container->instance('db', self::$capsule->getDatabaseManager());

        $schema = self::$capsule->schema();

        $schema->create('products', static function ($table): void {
            $table->increments('id');
            $table->string('model')->nullable();
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
            $table->json('payload')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.product_backups_max_per_scope', 15);
        $config_repository = Facade::getFacadeApplication()->make('config');
        $config_repository->set('app.product_backups_max_per_scope', 15);

        ProductBackups::query()->delete();
        Product::query()->delete();
    }

    public function test_it_resolves_latest_valid_unused_local_backup_with_fallback(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-LOCAL',
        ]);

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => ['product' => ['model' => 'valid-old']],
            'is_used'         => false,
        ]);

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => ['broken' => 'invalid'],
            'is_used'         => false,
        ]);

        ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => ['product' => ['model' => 'used-new']],
            'is_used'         => true,
        ]);

        $service = new ProductBackupRestoreService();
        $backup  = $service->resolveLatestValidLocalSnapshotForProduct((int) $product->id);

        self::assertInstanceOf(ProductBackups::class, $backup);
        self::assertSame('valid-old', (string) data_get($backup?->payload, 'product.model'));
        self::assertTrue($service->hasValidLatestLocalSnapshotForProduct((int) $product->id));
    }

    public function test_it_resolves_latest_valid_unused_external_backup_and_skips_used(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-EXT',
        ]);

        ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => 5,
            'external_product_id' => '99',
            'payload'             => ['id' => 99, 'snapshot' => ['name' => 'valid-old']],
            'is_used'             => false,
        ]);

        ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => 5,
            'external_product_id' => '99',
            'payload'             => [],
            'is_used'             => false,
        ]);

        ProductBackups::query()->create([
            'backupable_type'     => Product::class,
            'backupable_id'       => (int) $product->id,
            'backup_source'       => 'external_api',
            'backup_kind'         => 'external_product_snapshot',
            'shop_id'             => 5,
            'external_product_id' => '99',
            'payload'             => ['id' => 99, 'snapshot' => ['name' => 'used-new']],
            'is_used'             => true,
        ]);

        $service = new ProductBackupRestoreService();
        $backup  = $service->resolveLatestValidExternalSnapshotForProductShop((int) $product->id, 5, 99);

        self::assertInstanceOf(ProductBackups::class, $backup);
        self::assertSame('valid-old', (string) data_get($backup?->payload, 'snapshot.name'));
        self::assertTrue($service->hasValidLatestExternalSnapshotForProductShop((int) $product->id, 5, 99));
    }
}
