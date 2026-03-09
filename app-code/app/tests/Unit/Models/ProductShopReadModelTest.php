<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductShopReadModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_shop');
        Schema::dropIfExists('shops');

        Schema::create('shops', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_import_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_product_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_resolves_bound_shop_ids_names_and_external_ids(): void
    {
        Shop::query()->create(['id' => 1, 'name' => 'Strateg', 'is_active' => true]);
        Shop::query()->create(['id' => 2, 'name' => 'AvStore', 'is_active' => true]);

        ProductShop::query()->create([
            'product_import_batch_id' => 48,
            'product_id'              => 501,
            'shop_id'                 => 1,
            'external_product_id'     => 9001,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => 48,
            'product_id'              => 501,
            'shop_id'                 => 2,
            'external_product_id'     => null,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => 49,
            'product_id'              => 501,
            'shop_id'                 => 1,
            'external_product_id'     => 9002,
        ]);

        self::assertSame([1, 2], ProductShop::resolveBoundShopIds(501, 48));
        self::assertSame(['AvStore', 'Strateg'], ProductShop::resolveBoundShopNames(501, 48));
        self::assertSame(['9001'], ProductShop::resolveExternalProductIds(501, 48));
        self::assertSame(['9001', '9002'], ProductShop::resolveExternalProductIds(501));
    }

    public function test_it_resolves_external_binding_state_and_deletable_shop_options(): void
    {
        Shop::query()->create(['id' => 1, 'name' => 'Strateg', 'is_active' => true]);
        Shop::query()->create(['id' => 2, 'name' => 'AvStore', 'is_active' => true]);

        ProductShop::query()->create([
            'product_import_batch_id' => 48,
            'product_id'              => 700,
            'shop_id'                 => 1,
            'external_product_id'     => 9100,
        ]);

        ProductShop::query()->create([
            'product_import_batch_id' => 48,
            'product_id'              => 700,
            'shop_id'                 => 2,
            'external_product_id'     => null,
        ]);

        self::assertTrue(ProductShop::hasAnyExternalBinding(700));
        self::assertSame([1 => 'Strateg'], ProductShop::resolveDeletableShopOptions(700));
    }

    public function test_it_resolves_active_shop_options_and_shop_name(): void
    {
        Shop::query()->create(['id' => 1, 'name' => ' Strateg ', 'is_active' => true]);
        Shop::query()->create(['id' => 2, 'name' => 'AvStore', 'is_active' => false]);
        Shop::query()->create(['id' => 3, 'name' => ' Leo ', 'is_active' => true]);

        self::assertSame([
            3 => 'Leo',
            1 => 'Strateg',
        ], Shop::resolveActiveOptions());

        self::assertSame([
            3 => 'Leo',
            1 => 'Strateg',
        ], Shop::resolveOptionsByIds([3, 2, 1]));

        self::assertSame([
            3 => 'Leo',
            1 => 'Strateg',
            2 => 'AvStore',
        ], Shop::resolveOptionsByIds([3, 2, 1], false));

        self::assertSame('Strateg', Shop::resolveNameById(1));
        self::assertSame('', Shop::resolveNameById(999));
    }
}
