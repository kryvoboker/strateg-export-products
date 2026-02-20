<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProductImportBatchJob;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProcessProductImportBatchJobAutoBindShopIdTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private static ?Container $container = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$capsule !== null) {
            return;
        }

        self::$capsule = new Capsule();
        self::$capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        self::$container = new Container();
        Container::setInstance(self::$container);
        Facade::setFacadeApplication(self::$container);

        self::$container->instance('config', new Repository([
            'database.default'   => 'sqlite',
            'database.db_prefix' => '',
        ]));

        self::$container->instance('log', new class
        {
            public function channel(string $name): self
            {
                return $this;
            }

            public function info(string $message, array $context = []): void {}

            public function warning(string $message, array $context = []): void {}

            public function error(string $message, array $context = []): void {}
        });

        $schema = self::$capsule->schema();

        $schema->create('shops', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        Container::setInstance(self::$container);
        Facade::setFacadeApplication(self::$container);

        self::$capsule?->table('shops')->delete();
    }

    public function test_it_resolves_valid_shop_id_from_payload(): void
    {
        self::$capsule?->table('shops')->insert([
            'id'         => 10,
            'name'       => 'Test Shop',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $job = new ProcessProductImportBatchJob(1);

        $resolved_shop_id = $this->invokePrivateMethod($job, 'resolveAutoBindShopIdFromPayload', [[
            'product' => ['shop_id' => '10'],
        ]]);

        self::assertSame(10, $resolved_shop_id);
    }

    public function test_it_returns_zero_for_invalid_or_missing_shop_id(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        $invalid_shop_id = $this->invokePrivateMethod($job, 'resolveAutoBindShopIdFromPayload', [[
            'product' => ['shop_id' => 'abc'],
        ]]);
        $missing_shop_id = $this->invokePrivateMethod($job, 'resolveAutoBindShopIdFromPayload', [[
            'product' => ['shop_id' => '25'],
        ]]);
        $empty_shop_id = $this->invokePrivateMethod($job, 'resolveAutoBindShopIdFromPayload', [[
            'product' => ['shop_id' => ''],
        ]]);

        self::assertSame(0, $invalid_shop_id);
        self::assertSame(0, $missing_shop_id);
        self::assertSame(0, $empty_shop_id);
    }

    public function test_it_maps_shop_id_from_product_sheet_row_to_payload(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        $sheets_rows = [
            'Product' => [
                ['Shop Id', 'Model', 'SKU', 'EAN', 'Quantity', 'Minimum', 'Image', 'Price', 'Manufacturer', 'Brand', 'Is Active', 'Date Available', 'Date Added'],
                ['7', 'MODEL-1', 'SKU-1', 'EAN-1', '2', '1', 'catalog/a.jpg', '12.50', 'Man', 'Brand', '1', '2026-02-20 10:00:00', '2026-02-20 10:00:00'],
            ],
            'Description' => [['Name'], ['Product name']],
            'Image' => [['Image'], ['catalog/a.jpg']],
            'Product Category' => [['Category Name'], ['Root']],
            'Product Attribute' => [['Attribute Name', 'Attribute Text'], ['Attr', 'Val']],
            'Seo Url' => [['Keyword'], ['product-name']],
            'Special' => [['Price'], ['10']],
            'Discount' => [['Price'], ['9']],
        ];

        [$payloads] = $this->invokePrivateMethod($job, 'buildProductsPayloadFromSheetsRowBased', [
            $sheets_rows,
            'test.xlsx',
        ]);

        self::assertIsArray($payloads);
        self::assertCount(1, $payloads);
        self::assertSame('7', $payloads[0]['product']['shop_id'] ?? null);
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
