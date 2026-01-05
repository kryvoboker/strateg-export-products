<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Shops\Pages\CreateShop;
use App\Filament\Resources\Catalog\Shops\Pages\EditShop;
use App\Filament\Resources\Catalog\Shops\Pages\ListShops;
use App\Filament\Resources\Catalog\Shops\RelationManagers\ShopLanguagesRelationManager;
use App\Filament\Resources\Catalog\Shops\Schemas\ShopForm;
use App\Filament\Resources\Catalog\Shops\Tables\ShopsTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Shops\Shop;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ShopResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string                $model                = Shop::class;
    protected static string|BackedEnum|null $navigationIcon       = Heroicon::BuildingStorefront;
    protected static ?string                $recordTitleAttribute = 'name';
    protected static string|null|UnitEnum   $navigationGroup      = AdminNavigationGroupEnum::Catalog;

    /**
     * @param Schema $schema
     *
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return ShopForm::configure($schema);
    }

    /**
     * @param Table $table
     *
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    /**
     * @return class-string[]
     */
    public static function getRelations(): array
    {
        return [
            ShopLanguagesRelationManager::class,
        ];
    }

    /**
     * @return array|PageRegistration[]
     */
    public static function getPages(): array
    {
        return [
            'index'  => ListShops::route('/'),
            'create' => CreateShop::route('/create'),
            'edit'   => EditShop::route('/{record}/edit'),
        ];
    }

    /**
     * @return string
     */
    public static function getNavigationLabel(): string
    {
        return __('admin/shops/shops.navigation_label');
    }

    /**
     * @return string
     */
    public static function getModelLabel(): string
    {
        return __('admin/shops/shops.labels.model');
    }

    /**
     * @return string
     */
    public static function getPluralModelLabel(): string
    {
        return __('admin/shops/shops.labels.plural_model');
    }
}
