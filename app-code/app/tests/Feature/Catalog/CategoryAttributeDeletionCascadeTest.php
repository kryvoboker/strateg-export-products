<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Attributes\Attribute;
use App\Models\Categories\Category;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategoryAttributeDeletionCascadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        $this->recreateSchema();
    }

    public function test_deleting_attribute_removes_product_to_attribute_rows(): void
    {
        $attribute_id = (int) Attribute::query()->create([
            'sort_order' => 1,
            'is_active'  => true,
        ])->id;

        DB::table('product_to_attributes')->insert([
            'product_id'       => 100,
            'attribute_id'     => $attribute_id,
            'shop_language_id' => null,
            'text'             => 'Some value',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        self::assertSame(1, DB::table('product_to_attributes')->where('attribute_id', $attribute_id)->count());

        Attribute::query()->findOrFail($attribute_id)->delete();

        self::assertSame(0, DB::table('product_to_attributes')->where('attribute_id', $attribute_id)->count());
    }

    public function test_deleting_parent_category_deletes_children_and_related_rows(): void
    {
        $parent_category = Category::query()->create([
            'parent_id'  => null,
            'sort_order' => 0,
            'is_active'  => true,
        ]);

        $child_category = Category::query()->create([
            'parent_id'  => (int) $parent_category->id,
            'sort_order' => 0,
            'is_active'  => true,
        ]);

        DB::table('category_descriptions')->insert([
            [
                'category_id'      => (int) $parent_category->id,
                'shop_language_id' => null,
                'name'             => 'Parent',
                'description'      => null,
                'h1_title'         => null,
                'meta_title'       => null,
                'meta_description' => null,
                'meta_keywords'    => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'category_id'      => (int) $child_category->id,
                'shop_language_id' => null,
                'name'             => 'Child',
                'description'      => null,
                'h1_title'         => null,
                'meta_title'       => null,
                'meta_description' => null,
                'meta_keywords'    => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);

        DB::table('category_product')->insert([
            'product_id'  => 101,
            'category_id' => (int) $child_category->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('category_shop')->insert([
            'category_id'          => (int) $child_category->id,
            'shop_id'              => 10,
            'external_category_id' => null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $parent_category->delete();

        self::assertSame(0, Category::query()->count());
        self::assertSame(0, DB::table('category_descriptions')->count());
        self::assertSame(0, DB::table('category_product')->count());
        self::assertSame(0, DB::table('category_shop')->count());
    }

    private function recreateSchema(): void
    {
        Schema::dropIfExists('category_shop');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('category_descriptions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('ai_translation_caches');

        Schema::create('attributes', static function (Blueprint $table): void {
            $table->id();
            $table->char('family_ulid', 26)->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreignId('attribute_id')->nullable()->constrained('attributes')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('text', 3000)->nullable();
            $table->timestamps();
        });

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
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('h1_title')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('category_product', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
        });

        Schema::create('category_shop', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('external_category_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_translation_caches', static function (Blueprint $table): void {
            $table->id();
            $table->string('translatable_type');
            $table->unsignedBigInteger('translatable_id');
            $table->string('hash', 64);
            $table->longText('prompt');
            $table->longText('answer');
            $table->timestamps();
        });
    }
}
