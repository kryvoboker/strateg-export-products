<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\RelationManagers;

use Tests\TestCase;

class RelationManagerQueryContextTest extends TestCase
{
    public function test_product_import_items_relation_manager_does_not_use_static_closure_with_this_in_modify_query_using(): void
    {
        $contents = (string) file_get_contents(
            base_path('app/Filament/Resources/ProductImports/RelationManagers/ProductImportItemsRelationManager.php')
        );

        self::assertMatchesRegularExpression(
            '/->modifyQueryUsing\(\s*fn\s*\(Builder \$query\): Builder => .*\\$this->getOwnerRecord\(\)->id/s',
            $contents
        );

        self::assertDoesNotMatchRegularExpression(
            '/->modifyQueryUsing\(\s*static\s+fn\s*\(Builder \$query\): Builder => .*\\$this->getOwnerRecord\(\)->id/s',
            $contents
        );
    }

    public function test_product_update_items_relation_manager_does_not_use_static_closure_with_this_in_modify_query_using(): void
    {
        $contents = (string) file_get_contents(
            base_path('app/Filament/Resources/ProductUpdates/RelationManagers/ProductUpdateItemsRelationManager.php')
        );

        self::assertMatchesRegularExpression(
            '/->modifyQueryUsing\(\s*fn\s*\(Builder \$query\): Builder => .*\\$this->getOwnerRecord\(\)->id/s',
            $contents
        );

        self::assertDoesNotMatchRegularExpression(
            '/->modifyQueryUsing\(\s*static\s+fn\s*\(Builder \$query\): Builder => .*\\$this->getOwnerRecord\(\)->id/s',
            $contents
        );
    }
}
