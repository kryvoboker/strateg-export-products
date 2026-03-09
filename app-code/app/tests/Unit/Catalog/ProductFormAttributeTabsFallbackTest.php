<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Filament\Resources\Catalog\Products\Schemas\ProductForm;
use Filament\Schemas\Components\Tabs\Tab;
use ReflectionMethod;
use Tests\TestCase;

class ProductFormAttributeTabsFallbackTest extends TestCase
{
    public function test_it_builds_attribute_tabs_for_unbound_products_with_null_language_rows(): void
    {
        $method = new ReflectionMethod(ProductForm::class, 'getAttributeLanguageTabs');

        /** @var array<int, mixed> $tabs */
        $tabs = $method->invoke(null, 0, 'all', [0]);

        self::assertCount(1, $tabs);
        self::assertInstanceOf(Tab::class, $tabs[0]);
    }
}
