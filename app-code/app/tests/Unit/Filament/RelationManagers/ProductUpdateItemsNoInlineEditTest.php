<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\RelationManagers;

use App\Filament\Resources\ProductUpdates\RelationManagers\ProductUpdateItemsRelationManager;
use PHPUnit\Framework\TestCase;

class ProductUpdateItemsNoInlineEditTest extends TestCase
{
    public function test_it_does_not_have_inline_edit_persist_methods_for_attributes(): void
    {
        self::assertFalse(
            method_exists(ProductUpdateItemsRelationManager::class, 'saveEditedProduct'),
            'ProductUpdateItemsRelationManager should not perform inline product edit persistence.'
        );

        self::assertFalse(
            method_exists(ProductUpdateItemsRelationManager::class, 'persistEditedProductData'),
            'ProductUpdateItemsRelationManager should not contain duplicate-sensitive product edit persistence path.'
        );

        self::assertFalse(
            method_exists(ProductUpdateItemsRelationManager::class, 'syncProductAttributesFromFormData'),
            'ProductUpdateItemsRelationManager should not contain duplicate-sensitive attribute sync path.'
        );
    }
}
