<?php

declare(strict_types=1);

namespace Tests\Unit\ProductUpdates;

use App\Filament\Resources\ProductUpdates\Pages\ListProductUpdateBatches;
use PHPUnit\Framework\TestCase;

class UpdateInstructionsImmutableFieldsTest extends TestCase
{
    public function test_it_removes_model_ean_and_product_id_from_update_instructions(): void
    {
        $input = [
            'Product' => [
                'fields' => [
                    'product_id' => ['action' => 'set', 'value' => 10],
                    'model'      => ['action' => 'set', 'value' => 'NEW-MODEL'],
                    'ean'        => ['action' => 'set', 'value' => 'NEW-EAN'],
                    'sku'        => ['action' => 'set', 'value' => 'SKU-1'],
                ],
            ],
            'Description' => [
                'fields' => [
                    'name' => ['action' => 'set', 'value' => 'Name'],
                ],
            ],
        ];

        $result = ListProductUpdateBatches::sanitizeImmutableProductFieldsFromUpdateInstructions($input);

        self::assertArrayHasKey('Product', $result);
        self::assertArrayHasKey('Description', $result);

        self::assertArrayNotHasKey('product_id', $result['Product']['fields']);
        self::assertArrayNotHasKey('model', $result['Product']['fields']);
        self::assertArrayNotHasKey('ean', $result['Product']['fields']);
        self::assertArrayHasKey('sku', $result['Product']['fields']);
    }

    public function test_it_drops_sheet_when_only_immutable_fields_exist(): void
    {
        $input = [
            'Product' => [
                'fields' => [
                    'model' => ['action' => 'set', 'value' => 'NEW-MODEL'],
                    'ean'   => ['action' => 'set', 'value' => 'NEW-EAN'],
                ],
            ],
        ];

        $result = ListProductUpdateBatches::sanitizeImmutableProductFieldsFromUpdateInstructions($input);

        self::assertSame([], $result);
    }
}
