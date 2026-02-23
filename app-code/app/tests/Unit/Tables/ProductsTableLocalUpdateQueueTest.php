<?php

declare(strict_types=1);

namespace Tests\Unit\Tables;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Filament\Resources\Catalog\Products\Tables\ProductsTable;
use App\Models\Products\Product;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductsTableLocalUpdateQueueTest extends TestCase
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

        $schema->create('product_shop', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_import_batch_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('shop_id');
            $table->unsignedInteger('external_product_id')->nullable();
            $table->timestamps();
        });

        $schema->create('product_update_batches', static function ($table): void {
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

        $schema->create('product_update_items', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('product_update_batch_id');
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

        ProductUpdateItem::query()->delete();
        ProductUpdateBatch::query()->delete();
        Product::query()->delete();
    }

    public function test_it_creates_failed_update_item_when_product_is_not_bound_to_shop(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-A',
        ]);

        $batch = ProductUpdateBatch::query()->create([
            'source_type' => ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
            'source_name' => 'Test Batch',
            'status'      => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'options'     => [],
        ]);

        $summary = $this->invokeCreateLocalUpdateItemAndDispatch(
            (int) $batch->id,
            (int) $product->id,
            99,
            1
        );

        self::assertSame(1, (int) ($summary['skipped_not_bound'] ?? 0));
        self::assertSame(1, (int) ($summary['failed_created'] ?? 0));
        self::assertSame(0, (int) ($summary['updates_queued'] ?? 0));

        self::assertSame(1, ProductUpdateItem::query()->count());

        $item = ProductUpdateItem::query()->first();
        self::assertInstanceOf(ProductUpdateItem::class, $item);
        self::assertSame(ProductUpdateItemsStatusEnum::FAILED->value, (string) $item->status);
        self::assertSame('Product is not bound to selected shop', (string) $item->error_message);
    }

    public function test_it_skips_duplicate_local_update_item_in_same_batch_and_shop_scope(): void
    {
        $product = Product::query()->create([
            'model' => 'MODEL-B',
        ]);

        $batch = ProductUpdateBatch::query()->create([
            'source_type' => ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
            'source_name' => 'Test Batch 2',
            'status'      => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'options'     => [],
        ]);

        $this->invokeCreateLocalUpdateItemAndDispatch(
            (int) $batch->id,
            (int) $product->id,
            77,
            1
        );

        $summary = $this->invokeCreateLocalUpdateItemAndDispatch(
            (int) $batch->id,
            (int) $product->id,
            77,
            1
        );

        self::assertSame(1, (int) ($summary['already_failed'] ?? 0));
        self::assertSame(0, (int) ($summary['failed_created'] ?? 0));
        self::assertSame(1, ProductUpdateItem::query()->count());
    }

    /**
     * @return array<string, int>
     */
    private function invokeCreateLocalUpdateItemAndDispatch(
        int $batch_id,
        int $product_id,
        int $shop_id,
        ?int $requested_by_user_id = null
    ): array {
        $method = new ReflectionMethod(ProductsTable::class, 'createLocalUpdateItemAndDispatch');

        /** @var array<string, int> $result */
        $result = $method->invoke(
            null,
            $batch_id,
            $product_id,
            $shop_id,
            $requested_by_user_id
        );

        return $result;
    }
}
