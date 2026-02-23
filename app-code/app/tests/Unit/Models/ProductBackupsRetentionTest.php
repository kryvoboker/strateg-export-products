<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ProductBackupsRetentionTest extends TestCase
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

    public function test_it_keeps_only_last_fifteen_local_backups_for_same_scope(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-1',
        ]);

        for ($index = 1; $index <= 16; $index++) {
            ProductBackups::createOrUpdateInternalProductBackup((int) $product->id, [
                'product' => [
                    'model' => 'MODEL-'.$index,
                ],
            ]);
        }

        $backups = ProductBackups::query()
            ->where('backupable_type', Product::class)
            ->where('backupable_id', (int) $product->id)
            ->where('backup_source', 'internal')
            ->where('backup_kind', 'local_product_snapshot')
            ->orderBy('id')
            ->get();

        self::assertCount(15, $backups);
        self::assertSame('MODEL-2', (string) data_get($backups->first()->payload, 'product.model'));
        self::assertSame('MODEL-16', (string) data_get($backups->last()->payload, 'product.model'));
    }

    public function test_it_keeps_only_last_fifteen_external_backups_per_external_scope(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-EXT',
        ]);

        for ($index = 1; $index <= 16; $index++) {
            ProductBackups::createOrUpdateProductBackup(
                product_id: (int) $product->id,
                payload: ['id' => $index, 'snapshot' => ['name' => 'external-'.$index]],
                backup_source: 'external_api',
                backup_kind: 'external_product_snapshot',
                shop_id: 10,
                external_product_id: '500',
            );
        }

        $backups = ProductBackups::query()
            ->where('backupable_type', Product::class)
            ->where('backupable_id', (int) $product->id)
            ->where('backup_source', 'external_api')
            ->where('backup_kind', 'external_product_snapshot')
            ->where('shop_id', 10)
            ->where('external_product_id', '500')
            ->orderBy('id')
            ->get();

        self::assertCount(15, $backups);
        self::assertSame(2, (int) data_get($backups->first()->payload, 'id'));
        self::assertSame(16, (int) data_get($backups->last()->payload, 'id'));
    }

    public function test_it_applies_non_default_retention_limit_from_config(): void
    {
        config()->set('app.product_backups_max_per_scope', 3);

        $config_repository = Facade::getFacadeApplication()->make('config');
        $config_repository->set('app.product_backups_max_per_scope', 3);

        $product = Product::query()->create([
            'model' => 'MODEL-NON-DEFAULT',
        ]);

        for ($index = 1; $index <= 5; $index++) {
            ProductBackups::createOrUpdateInternalProductBackup((int) $product->id, [
                'product' => [
                    'model' => 'MODEL-'.$index,
                ],
            ]);
        }

        $backups = ProductBackups::query()
            ->where('backupable_type', Product::class)
            ->where('backupable_id', (int) $product->id)
            ->where('backup_source', 'internal')
            ->where('backup_kind', 'local_product_snapshot')
            ->orderBy('id')
            ->get();

        self::assertCount(3, $backups);
        self::assertSame('MODEL-3', (string) data_get($backups->first()->payload, 'product.model'));
        self::assertSame('MODEL-5', (string) data_get($backups->last()->payload, 'product.model'));
    }
}
