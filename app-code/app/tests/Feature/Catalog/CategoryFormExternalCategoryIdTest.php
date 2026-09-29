<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Models\Categories\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryFormExternalCategoryIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('category_shop');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');

        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('category_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('category_shop', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedInteger('external_category_id')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'shop_id']);
        });
    }

    public function test_it_saves_optional_external_category_ids_per_shop_binding(): void
    {
        $category = Category::query()->create([
            'parent_id'  => null,
            'sort_order' => 0,
            'is_active'  => true,
        ]);

        $form_data = [
            'category_name' => 'Garden',
            'shop_bindings' => [
                ['shop_id' => 11, 'external_category_id' => '1101'],
                ['shop_id' => 12, 'external_category_id' => null],
            ],
        ];

        CategoryForm::syncCategoryAdditionalData($category, $form_data);

        self::assertSame(1101, (int) DB::table('category_shop')
            ->where('category_id', $category->id)
            ->where('shop_id', 11)
            ->value('external_category_id'));
        self::assertNull(DB::table('category_shop')
            ->where('category_id', $category->id)
            ->where('shop_id', 12)
            ->value('external_category_id'));
        self::assertSame([11, 12], CategoryForm::getSelectedShopIds($form_data));

        $form_data['shop_bindings'][0]['external_category_id'] = '1102';
        $form_data['shop_bindings']                            = [$form_data['shop_bindings'][0]];

        CategoryForm::syncCategoryAdditionalData($category, $form_data);

        self::assertSame(1102, (int) DB::table('category_shop')
            ->where('category_id', $category->id)
            ->where('shop_id', 11)
            ->value('external_category_id'));
        self::assertSame(0, DB::table('category_shop')->where('category_id', $category->id)->where('shop_id', 12)->count());
    }
}
