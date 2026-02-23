<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProductRestoreBatchJob;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\Updates\ProductBackups;
use App\Supports\Services\Products\ProductBackupRestoreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessProductRestoreBatchJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.db_prefix', 'spme_');
        $this->recreateSchema();
    }

    public function test_it_restores_only_products_with_valid_backups_and_skips_others(): void
    {
        $fake_logger = new class
        {
            /**
             * @var array<int, array{channel:string,level:string,message:string,context:array<string,mixed>}>
             */
            public array $records = [];

            private string $current_channel = 'stack';

            public function channel(string $name): self
            {
                $this->current_channel = $name;

                return $this;
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'warning',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function info(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'info',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function error(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'error',
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };
        $this->app->instance('log', $fake_logger);

        $product_with_valid_backup = Product::query()->create([
            'product_import_item_id' => 1,
            'family_ulid'            => '01HFAMILYULID00000000000111',
            'model'                  => 'MODEL-OLD-1',
            'sku'                    => 'SKU-1',
            'ean'                    => 'EAN-1',
            'quantity'               => 3,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 10,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        $product_without_backup = Product::query()->create([
            'product_import_item_id' => 2,
            'family_ulid'            => '01HFAMILYULID00000000000112',
            'model'                  => 'MODEL-OLD-2',
            'sku'                    => 'SKU-2',
            'ean'                    => 'EAN-2',
            'quantity'               => 4,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 20,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        $product_with_invalid_backup = Product::query()->create([
            'product_import_item_id' => 3,
            'family_ulid'            => '01HFAMILYULID00000000000113',
            'model'                  => 'MODEL-OLD-3',
            'sku'                    => 'SKU-3',
            'ean'                    => 'EAN-3',
            'quantity'               => 5,
            'minimum'                => 1,
            'image'                  => null,
            'price'                  => 30,
            'is_active'              => true,
            'date_available'         => null,
            'date_added'             => null,
        ]);

        $import_item_with_valid_backup = ProductImportItem::query()->create([
            'product_import_batch_id' => 100,
            'product_id'              => (int) $product_with_valid_backup->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        $import_item_without_backup = ProductImportItem::query()->create([
            'product_import_batch_id' => 100,
            'product_id'              => (int) $product_without_backup->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        $import_item_with_invalid_backup = ProductImportItem::query()->create([
            'product_import_batch_id' => 100,
            'product_id'              => (int) $product_with_invalid_backup->id,
            'payload'                 => [],
            'status'                  => 'new',
        ]);

        $valid_backup = ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product_with_valid_backup->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'product' => [
                    'product_import_item_id' => 1,
                    'marked_to_shop'         => null,
                    'model'                  => 'MODEL-RESTORED-1',
                    'sku'                    => 'SKU-RESTORED-1',
                    'ean'                    => 'EAN-RESTORED-1',
                    'quantity'               => 9,
                    'minimum'                => 2,
                    'image'                  => 'restored-1.jpg',
                    'price'                  => '111.22',
                    'is_active'              => true,
                    'date_available'         => null,
                    'date_added'             => null,
                ],
            ],
            'is_used' => false,
        ]);

        $invalid_backup = ProductBackups::query()->create([
            'backupable_type' => Product::class,
            'backupable_id'   => (int) $product_with_invalid_backup->id,
            'backup_source'   => 'internal',
            'backup_kind'     => 'local_product_snapshot',
            'payload'         => [
                'broken' => 'payload',
            ],
            'is_used' => false,
        ]);

        $job = new ProcessProductRestoreBatchJob([
            (int) $import_item_with_valid_backup->id,
            (int) $import_item_without_backup->id,
            (int) $import_item_with_invalid_backup->id,
        ], 1);

        $job->handle(app(ProductBackupRestoreService::class));

        $product_with_valid_backup->refresh();
        $product_without_backup->refresh();
        $product_with_invalid_backup->refresh();
        $valid_backup->refresh();
        $invalid_backup->refresh();

        self::assertSame('MODEL-RESTORED-1', (string) $product_with_valid_backup->model);
        self::assertSame('SKU-RESTORED-1', (string) $product_with_valid_backup->sku);
        self::assertTrue((bool) $valid_backup->is_used);

        self::assertSame('MODEL-OLD-2', (string) $product_without_backup->model);

        self::assertSame('MODEL-OLD-3', (string) $product_with_invalid_backup->model);
        self::assertFalse((bool) $invalid_backup->is_used);

        $has_daily_skip_warning = collect($fake_logger->records)->contains(static function (array $record): bool {
            return $record['channel'] === 'daily'
                && $record['level'] === 'warning'
                && str_contains($record['message'], 'Bulk product restore skipped: no available unused local backup found');
        });
        self::assertTrue($has_daily_skip_warning);
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_discounts');
        Schema::dropIfExists('product_specials');
        Schema::dropIfExists('seo_urls');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_descriptions');
        Schema::dropIfExists('product_backups');
        Schema::dropIfExists('product_import_items');
        Schema::dropIfExists('products');

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_item_id')->nullable();
            $table->string('family_ulid', 26)->nullable();
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

        Schema::create('product_import_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->text('payload')->nullable();
            $table->string('status', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_backups', static function (Blueprint $table): void {
            $table->id();
            $table->string('backupable_type')->nullable();
            $table->unsignedBigInteger('backupable_id')->nullable();
            $table->string('backup_source', 100)->nullable();
            $table->string('backup_kind', 150)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('external_product_id')->nullable();
            $table->text('payload')->nullable();
            $table->boolean('is_used')->default(false);
            $table->timestamps();
        });

        Schema::create('product_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
        });

        Schema::create('attributes', static function (Blueprint $table): void {
            $table->id();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_urls', static function (Blueprint $table): void {
            $table->id();
            $table->string('seoable_type');
            $table->unsignedBigInteger('seoable_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('query_key')->nullable();
            $table->string('query_value')->nullable();
            $table->string('keyword')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_specials', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });

        Schema::create('product_discounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('priority')->default(1);
            $table->string('date_start')->nullable();
            $table->string('date_end')->nullable();
            $table->timestamps();
        });
    }
}
