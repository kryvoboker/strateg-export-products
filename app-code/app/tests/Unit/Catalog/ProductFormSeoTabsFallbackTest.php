<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Schemas\ProductForm;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ProductFormSeoTabsFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shop_languages');
        Schema::dropIfExists('shops');

        Schema::create('shops', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 50)->nullable();
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shop_languages', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function test_it_builds_seo_tabs_from_existing_language_state_when_shop_is_not_selected(): void
    {
        DB::table('shops')->updateOrInsert(
            ['id' => 901],
            [
                'name'       => 'SEO Test Shop',
                'type'       => 'opencart',
                'base_url'   => 'https://example.test',
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('shop_languages')->updateOrInsert(
            ['id' => 1901],
            [
                'shop_id'    => 901,
                'code'       => 'uk',
                'name'       => 'Ukrainian',
                'is_active'  => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('shop_languages')->updateOrInsert(
            ['id' => 1902],
            [
                'shop_id'    => 901,
                'code'       => 'en',
                'name'       => 'English',
                'is_active'  => true,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $method = new ReflectionMethod(ProductForm::class, 'getSeoUrlLanguageTabs');

        /** @var array<int, mixed> $tabs */
        $tabs = $method->invoke(null, 0, [1901, 1902]);

        self::assertCount(2, $tabs);
    }
}
