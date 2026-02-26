<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Products\Pages\CreateProduct;
use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Filament\Resources\Catalog\Products\Pages\ListProducts;
use App\Filament\Resources\Catalog\Products\Schemas\ProductForm;
use App\Filament\Resources\Catalog\Products\Tables\ProductsTable;
use App\Filament\Resources\Trait\Support\TotalModelItemsResourceTrait;
use App\Models\Products\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'product_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShoppingBag;

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit'   => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/products/products.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/products/products.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/products/products.labels.plural_model');
    }
}
