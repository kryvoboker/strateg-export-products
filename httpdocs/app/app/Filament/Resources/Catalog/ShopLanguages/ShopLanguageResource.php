<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages;

use App\Filament\Resources\Catalog\ShopLanguages\Pages\CreateShopLanguage;
use App\Filament\Resources\Catalog\ShopLanguages\Pages\EditShopLanguage;
use App\Filament\Resources\Catalog\ShopLanguages\Pages\ListShopLanguages;
use App\Filament\Resources\Catalog\ShopLanguages\Schemas\ShopLanguageForm;
use App\Filament\Resources\Catalog\ShopLanguages\Tables\ShopLanguagesTable;
use App\Models\Shops\ShopLanguage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShopLanguageResource extends Resource
{
    protected static ?string                $model                = ShopLanguage::class;
    protected static string|BackedEnum|null $navigationIcon       = Heroicon::Language;
    protected static ?string                $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ShopLanguageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShopLanguagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListShopLanguages::route('/'),
            'create' => CreateShopLanguage::route('/create'),
            'edit'   => EditShopLanguage::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/shops/languages.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/shops/languages.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/shops/languages.labels.plural_model');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin/default.menu.item_catalog');
    }
}

