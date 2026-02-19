<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Models\Products\Product;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EditProductImmutableFieldsTest extends TestCase
{
    public function test_it_enforces_immutable_product_id_model_and_ean_before_persist(): void
    {
        $page = new EditProduct();

        $record        = new Product();
        $record->id    = 123;
        $record->model = 'LOCKED-MODEL';
        $record->ean   = 'LOCKED-EAN';

        $input = [
            'product_id' => 999,
            'model'      => 'HACKED-MODEL',
            'ean'        => 'HACKED-EAN',
            'sku'        => 'NEW-SKU',
        ];

        $result = $this->invokeEnforceImmutableProductFields($page, $record, $input);

        self::assertSame(123, $result['product_id']);
        self::assertSame('LOCKED-MODEL', $result['model']);
        self::assertSame('LOCKED-EAN', $result['ean']);
        self::assertSame('NEW-SKU', $result['sku']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function invokeEnforceImmutableProductFields(EditProduct $page, Product $record, array $data): array
    {
        $method = new ReflectionMethod(EditProduct::class, 'enforceImmutableProductFields');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($page, $record, $data);

        return $result;
    }
}
