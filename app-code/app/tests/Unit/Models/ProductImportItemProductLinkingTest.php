<?php

declare(strict_types=1);

namespace {
    use Carbon\Carbon;
    use Carbon\CarbonInterface;

    if (! function_exists('get_now_date')) {
        function get_now_date(?string $time_zone = null): Carbon|CarbonInterface
        {
            return Carbon::now($time_zone);
        }
    }
}

namespace Tests\Unit\Models {

    use App\Models\Products\Imports\ProductImportItem;
    use App\Models\Products\Product;
    use App\Supports\Services\Catalog\ProductShopBindingService;
    use Illuminate\Container\Container;
    use Illuminate\Database\Capsule\Manager as Capsule;
    use Illuminate\Support\Facades\Facade;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    class ProductImportItemProductLinkingTest extends TestCase
    {
        private static ?Capsule $capsule = null;

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

            $container = new Container();
            Container::setInstance($container);
            Facade::setFacadeApplication($container);
            $container->instance('db', self::$capsule->getDatabaseManager());

            $schema = self::$capsule->schema();

            $schema->create('product_import_batches', static function ($table): void {
                $table->increments('id');
                $table->timestamps();
            });

            $schema->create('product_import_items', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('product_import_batch_id');
                $table->unsignedBigInteger('product_id')->nullable()->unique();
                $table->text('payload')->nullable();
                $table->string('status', 100)->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });

            $schema->create('products', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('product_import_item_id');
                $table->string('marked_to_shop')->nullable();
                $table->string('model')->nullable();
                $table->string('sku')->nullable();
                $table->string('ean')->nullable();
                $table->integer('quantity')->default(0);
                $table->integer('minimum')->default(1);
                $table->string('image')->nullable();
                $table->decimal('price', 15, 4)->default(0);
                $table->boolean('is_active')->default(false);
                $table->timestamp('date_available')->nullable();
                $table->timestamp('date_added')->nullable();
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
                $table->string('external_product_id')->nullable();
                $table->timestamps();
            });

            $schema->create('category_product', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('category_id');
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

            $schema->create('category_shop', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('shop_id');
                $table->string('external_category_id')->nullable();
                $table->timestamps();
            });

            $schema->create('attribute_shop', static function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('attribute_id');
                $table->unsignedInteger('shop_id');
                $table->string('external_attribute_id')->nullable();
                $table->timestamps();
            });
        }

        protected function setUp(): void
        {
            parent::setUp();

            Capsule::table('attribute_shop')->delete();
            Capsule::table('category_shop')->delete();
            Capsule::table('product_to_attributes')->delete();
            Capsule::table('category_product')->delete();
            Capsule::table('product_shop')->delete();
            Capsule::table('shops')->delete();
            Product::query()->delete();
            ProductImportItem::query()->delete();
            Capsule::table('product_import_batches')->delete();
        }

        public function test_create_from_import_payload_links_product_and_import_item_bidirectionally(): void
        {
            $batch_id = (int) Capsule::table('product_import_batches')->insertGetId([
                'created_at' => get_now_date(),
                'updated_at' => get_now_date(),
            ]);

            $product_import_item = ProductImportItem::query()->create([
                'product_import_batch_id' => $batch_id,
                'product_id'              => null,
                'payload'                 => [],
                'status'                  => 'new',
                'error_message'           => null,
                'processed_at'            => null,
            ]);

            $created_product_id = Product::createFromImportPayload([
                'marked_to_shop' => null,
                'model'          => 'M-100',
                'sku'            => 'SKU-100',
                'ean'            => 'EAN-100',
                'quantity'       => 3,
                'minimum'        => 1,
                'image'          => null,
                'price'          => 100.00,
                'is_active'      => true,
                'date_available' => null,
                'date_added'     => null,
            ], (int) $product_import_item->id);

            $created_product     = Product::query()->findOrFail($created_product_id);
            $updated_import_item = ProductImportItem::query()->findOrFail((int) $product_import_item->id);

            self::assertSame((int) $product_import_item->id, (int) $created_product->product_import_item_id);
            self::assertSame($created_product_id, (int) $updated_import_item->product_id);
        }

        public function test_ensure_batch_product_item_reuses_item_by_product_and_syncs_product_link(): void
        {
            $first_batch_id = (int) Capsule::table('product_import_batches')->insertGetId([
                'created_at' => get_now_date(),
                'updated_at' => get_now_date(),
            ]);
            $second_batch_id = (int) Capsule::table('product_import_batches')->insertGetId([
                'created_at' => get_now_date(),
                'updated_at' => get_now_date(),
            ]);

            $existing_import_item = ProductImportItem::query()->create([
                'product_import_batch_id' => $first_batch_id,
                'product_id'              => null,
                'payload'                 => [],
                'status'                  => 'new',
                'error_message'           => null,
                'processed_at'            => null,
            ]);

            $product = Product::query()->create([
                'product_import_item_id' => (int) $existing_import_item->id,
                'marked_to_shop'         => null,
                'model'                  => 'M-200',
                'sku'                    => 'SKU-200',
                'ean'                    => null,
                'quantity'               => 1,
                'minimum'                => 1,
                'image'                  => null,
                'price'                  => 0,
                'is_active'              => true,
                'date_available'         => null,
                'date_added'             => null,
            ]);

            $existing_import_item->update(['product_id' => (int) $product->id]);

            $resolved_import_item = ProductImportItem::ensureBatchProductItem(
                $second_batch_id,
                (int) $product->id,
                ['source' => 'rebind'],
                'successed'
            );

            $product->refresh();
            $resolved_import_item->refresh();

            self::assertSame((int) $existing_import_item->id, (int) $resolved_import_item->id);
            self::assertSame($second_batch_id, (int) $resolved_import_item->product_import_batch_id);
            self::assertSame((int) $resolved_import_item->id, (int) $product->product_import_item_id);
        }

        public function test_bind_to_first_shop_does_not_duplicate_product(): void
        {
            $batch_id = (int) Capsule::table('product_import_batches')->insertGetId([
                'created_at' => get_now_date(),
                'updated_at' => get_now_date(),
            ]);

            $product_import_item = ProductImportItem::query()->create([
                'product_import_batch_id' => $batch_id,
                'product_id'              => null,
                'payload'                 => [],
                'status'                  => 'successed',
                'error_message'           => null,
                'processed_at'            => get_now_date(),
            ]);

            $product = Product::query()->create([
                'product_import_item_id' => (int) $product_import_item->id,
                'marked_to_shop'         => null,
                'model'                  => 'M-300',
                'sku'                    => 'SKU-300',
                'ean'                    => null,
                'quantity'               => 1,
                'minimum'                => 1,
                'image'                  => null,
                'price'                  => 0,
                'is_active'              => true,
                'date_available'         => null,
                'date_added'             => null,
            ]);

            $product_import_item->update(['product_id' => (int) $product->id]);

            Capsule::table('shops')->insert([
                'id'         => 10,
                'name'       => 'Strateg',
                'created_at' => get_now_date(),
                'updated_at' => get_now_date(),
            ]);

            $service = new ProductShopBindingService();

            /** @var array{product_id:int,bound:int,duplicated:int} $bind_result */
            $bind_result = $this->invokePrivateMethod($service, 'bindProductToShop', [
                $product,
                10,
                $batch_id,
            ]);

            self::assertSame((int) $product->id, (int) $bind_result['product_id']);
            self::assertSame(1, (int) $bind_result['bound']);
            self::assertSame(0, (int) $bind_result['duplicated']);
            self::assertSame(1, Product::query()->count());
            self::assertSame(1, (int) Capsule::table('product_shop')->count());
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
}
