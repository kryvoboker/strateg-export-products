<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Manufacturers\Pages\CreateManufacturer;
use App\Filament\Resources\Catalog\Manufacturers\Pages\EditManufacturer;
use App\Filament\Resources\Catalog\Manufacturers\Pages\ListManufacturers;
use App\Filament\Resources\Catalog\Manufacturers\Schemas\ManufacturerForm;
use App\Filament\Resources\Catalog\Manufacturers\Tables\ManufacturersTable;
use App\Filament\Resources\Trait\Support\TotalModelItemsResourceTrait;
use App\Models\Manufacturers\Manufacturer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ManufacturerResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = Manufacturer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'manufacturer_name';

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return ManufacturerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ManufacturersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListManufacturers::route('/'),
            'create' => CreateManufacturer::route('/create'),
            'edit'   => EditManufacturer::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/manufacturers/manufacturers.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/manufacturers/manufacturers.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/manufacturers/manufacturers.labels.plural_model');
    }
}
