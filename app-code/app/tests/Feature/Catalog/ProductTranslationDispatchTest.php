<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\Catalog\Products\Pages\CreateProduct;
use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Jobs\ProcessAttributeNameTranslationJob;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Jobs\ProcessProductTranslationJob;
use App\Models\Products\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class ProductTranslationDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateSchema();
    }

    public function test_create_product_page_dispatches_translation_jobs_after_create(): void
    {
        Queue::fake();

        $product = Product::query()->create([
            'model' => 'TEST-MODEL',
            'sku' => 'TEST-SKU',
            'is_active' => true,
        ]);

        DB::table('product_to_attributes')->insert([
            'product_id' => (int) $product->id,
            'attribute_id' => 501,
            'shop_language_id' => 1,
            'text' => 'Value',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_product')->insert([
            'product_id' => (int) $product->id,
            'category_id' => 301,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_shop')->insert([
            'product_id' => (int) $product->id,
            'shop_id' => 11,
            'product_import_batch_id' => null,
            'external_product_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = new CreateProduct();
        $this->setProperty($page, 'record', $product);
        $this->setProperty($page, 'data', [
            'bind_shop_id' => 11,
        ]);

        $this->invokeMethod($page, 'afterCreate');

        Queue::assertPushed(ProcessAttributeNameTranslationJob::class);
        Queue::assertPushed(ProcessCategoryNameTranslationJob::class);
        Queue::assertPushed(ProcessProductTranslationJob::class, 1);
    }

    public function test_edit_product_page_dispatch_methods_push_translation_jobs(): void
    {
        Queue::fake();

        $product = Product::query()->create([
            'model' => 'EDIT-MODEL',
            'sku' => 'EDIT-SKU',
            'is_active' => true,
        ]);

        DB::table('product_to_attributes')->insert([
            'product_id' => (int) $product->id,
            'attribute_id' => 777,
            'shop_language_id' => 2,
            'text' => 'Text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_product')->insert([
            'product_id' => (int) $product->id,
            'category_id' => 888,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_shop')->insert([
            'product_id' => (int) $product->id,
            'shop_id' => 22,
            'product_import_batch_id' => null,
            'external_product_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $page = new EditProduct();

        $this->invokeMethod($page, 'dispatchAttributeNameTranslationJobs', [(int) $product->id, ['bind_shop_id' => 22]]);
        $this->invokeMethod($page, 'dispatchCategoryNameTranslationJobs', [(int) $product->id, ['bind_shop_id' => 22]]);
        $this->invokeMethod($page, 'dispatchProductTranslationJob', [(int) $product->id]);

        Queue::assertPushed(ProcessAttributeNameTranslationJob::class);
        Queue::assertPushed(ProcessCategoryNameTranslationJob::class);
        Queue::assertPushed(ProcessProductTranslationJob::class, 1);
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('products');

        Schema::create('products', static function (Blueprint $table): void {
            $table->id();
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

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('text', 3000)->nullable();
            $table->timestamps();
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->string('external_product_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokeMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);
        $method->setAccessible(true);

        return $method->invokeArgs($target, $arguments);
    }

    private function setProperty(object $target, string $property_name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $property_name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}

