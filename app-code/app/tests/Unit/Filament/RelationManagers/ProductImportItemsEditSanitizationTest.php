<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\RelationManagers;

use App\Filament\Resources\ProductImports\RelationManagers\ProductImportItemsRelationManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductImportItemsEditSanitizationTest extends TestCase
{
    public function test_it_clears_selected_attributes_when_custom_attribute_names_exist(): void
    {
        $manager = new ProductImportItemsRelationManager();

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

        $result = $this->invokeSanitizer($manager, $input);

        self::assertSame([], $result['attributes_selected_by_language'][1]);
    }

    public function test_it_keeps_selected_attributes_when_custom_names_are_empty(): void
    {
        $manager = new ProductImportItemsRelationManager();

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

        $result = $this->invokeSanitizer($manager, $input);

        self::assertSame([10, 20], $result['attributes_selected_by_language'][1]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function invokeSanitizer(ProductImportItemsRelationManager $manager, array $input): array
    {
        $method = new ReflectionMethod(ProductImportItemsRelationManager::class, 'sanitizeAttributesSelectionBeforeSave');

        /** @var array<string, mixed> $result */
        $result = $method->invoke($manager, $input, 123);

        return $result;
    }
}
