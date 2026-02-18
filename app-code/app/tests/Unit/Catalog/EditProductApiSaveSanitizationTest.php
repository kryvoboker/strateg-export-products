<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EditProductApiSaveSanitizationTest extends TestCase
{
    public function test_it_clears_selected_attributes_when_custom_attribute_names_exist(): void
    {
        $page = new EditProduct();

        $input = [
            'attributes_selected_by_language' => [
                1 => [10, 20],
            ],
            'attributes_custom_by_language' => [
                1 => [
                    ['attribute_name' => 'Width', 'text' => '10 cm'],
                ],
            ],
        ];

        $result = $this->invokeSanitizer($page, $input);

        self::assertSame([], $result['attributes_selected_by_language'][1]);
    }

    public function test_it_keeps_selected_attributes_when_custom_attribute_names_are_empty(): void
    {
        $page = new EditProduct();

        $input = [
            'attributes_selected_by_language' => [
                1 => [10, 20],
            ],
            'attributes_custom_by_language' => [
                1 => [
                    ['attribute_name' => ' ', 'text' => '10 cm'],
                ],
            ],
        ];

        $result = $this->invokeSanitizer($page, $input);

        self::assertSame([10, 20], $result['attributes_selected_by_language'][1]);
    }

    public function test_it_sanitizes_attributes_for_regular_update_flow_payload(): void
    {
        $page = new EditProduct();

        $input = [
            'model' => 'MODEL-1',
            'attributes_selected_by_language' => [
                2 => [55],
            ],
            'attributes_custom_by_language' => [
                2 => [
                    ['attribute_name' => 'Length', 'text' => '120 cm'],
                ],
            ],
        ];

        $result = $this->invokeSanitizer($page, $input);

        self::assertSame([], $result['attributes_selected_by_language'][2]);
        self::assertSame('MODEL-1', $result['model']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function invokeSanitizer(EditProduct $page, array $input): array
    {
        $method = new ReflectionMethod(EditProduct::class, 'sanitizeAttributesSelectionBeforeSave');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($page, $input);

        return $result;
    }
}
