<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class EditProductAttributeNullLanguagePersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_to_attributes');
        Schema::dropIfExists('attribute_descriptions');
        Schema::dropIfExists('attributes');

        Schema::create('attributes', static function (Blueprint $table): void {
            $table->id();
            $table->integer('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attribute_descriptions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_to_attributes', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('shop_language_id')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_preserves_null_language_attribute_rows_for_unbound_products(): void
    {
        Schema::getConnection()->table('attributes')->insert([
            'id'         => 10,
            'sort_order' => 1,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::getConnection()->table('attribute_descriptions')->insert([
            'attribute_id'     => 10,
            'shop_language_id' => null,
            'name'             => 'Cable length',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $page = new EditProduct();

        $method = new ReflectionMethod(EditProduct::class, 'syncProductAttributesFromFormData');
        $method->invoke($page, 501, [
            'attributes_selected_by_language' => [
                0 => [],
            ],
            'attributes_custom_by_language' => [
                0 => [
                    [
                        'attribute_name' => 'Cable length',
                        'text'           => '3 m',
                    ],
                ],
            ],
        ], 0);

        $created_rows = Schema::getConnection()->table('product_to_attributes')
            ->where('product_id', 501)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();

        self::assertCount(1, $created_rows);
        self::assertSame(10, (int) $created_rows[0]['attribute_id']);
        self::assertNull($created_rows[0]['shop_language_id']);
        self::assertSame('3 m', (string) $created_rows[0]['text']);
    }
}
