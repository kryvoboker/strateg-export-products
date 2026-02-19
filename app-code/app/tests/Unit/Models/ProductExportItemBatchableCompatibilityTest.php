<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ProductExportItemBatchableCompatibilityTest extends TestCase
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

        $schema->create('product_import_batches', static function ($table): void {
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

        $schema->create('product_export_items', static function ($table): void {
            $table->increments('id');
            $table->string('batchable_type');
            $table->unsignedInteger('batchable_id');
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

        ProductExportItem::query()->delete();
        ProductImportBatch::query()->delete();
    }

    public function test_it_counts_statuses_by_batchable_scope(): void
    {
        $batch = ProductImportBatch::query()->create([
            'source_type' => 'excel_file',
            'source_name' => 'Test Batch',
            'status'      => 'processing',
            'options'     => [],
        ]);

        ProductExportItem::query()->create([
            'batchable_type' => ProductImportBatch::class,
            'batchable_id'   => (int) $batch->id,
            'product_id'     => 10,
            'payload'        => ['shop_id' => 1, 'operation' => 'export'],
            'status'         => ProductExportItemsStatusEnum::PROCESSING->value,
        ]);

        ProductExportItem::query()->create([
            'batchable_type' => ProductImportBatch::class,
            'batchable_id'   => (int) $batch->id,
            'product_id'     => 11,
            'payload'        => ['shop_id' => 1, 'operation' => 'export'],
            'status'         => ProductExportItemsStatusEnum::FAILED->value,
        ]);

        ProductExportItem::query()->create([
            'batchable_type' => ProductImportBatch::class,
            'batchable_id'   => (int) $batch->id,
            'product_id'     => 12,
            'payload'        => ['shop_id' => 1, 'operation' => 'export'],
            'status'         => ProductExportItemsStatusEnum::EXPORTED->value,
        ]);

        $counters = ProductExportItem::getBatchStatusCounters((int) $batch->id);

        self::assertSame(3, (int) ($counters['total'] ?? 0));
        self::assertSame(1, (int) ($counters['processing'] ?? 0));
        self::assertSame(1, (int) ($counters['failed'] ?? 0));
        self::assertSame(1, (int) ($counters['exported'] ?? 0));
    }
}
