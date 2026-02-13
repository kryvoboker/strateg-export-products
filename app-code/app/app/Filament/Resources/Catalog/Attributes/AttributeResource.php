<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Attributes;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Catalog\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Catalog\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Catalog\Attributes\Schemas\AttributeForm;
use App\Filament\Resources\Catalog\Attributes\Tables\AttributesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Attributes\Attribute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AttributeResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = Attribute::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;
    protected static ?string $recordTitleAttribute = 'attribute_name';
    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return AttributeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttributesTable::configure($table);
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
            'index'  => ListAttributes::route('/'),
            'create' => CreateAttribute::route('/create'),
            'edit'   => EditAttribute::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/attributes/attributes.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/attributes/attributes.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/attributes/attributes.labels.plural_model');
    }
}
