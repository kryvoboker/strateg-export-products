<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Brands\Pages\CreateBrand;
use App\Filament\Resources\Catalog\Brands\Pages\EditBrand;
use App\Filament\Resources\Catalog\Brands\Pages\ListBrands;
use App\Filament\Resources\Catalog\Brands\Schemas\BrandForm;
use App\Filament\Resources\Catalog\Brands\Tables\BrandsTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Brands\Brand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BrandResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'brand_name';

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit'   => EditBrand::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/brands/brands.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/brands/brands.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/brands/brands.labels.plural_model');
    }
}
