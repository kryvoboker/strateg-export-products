<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\Categories\Pages\CreateCategory;
use App\Filament\Resources\Catalog\Categories\Pages\EditCategory;
use App\Filament\Resources\Catalog\Categories\Pages\ListCategories;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Catalog\Categories\Tables\CategoriesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Categories\Category;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CategoryResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = Category::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?string $recordTitleAttribute = 'category_name';
    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
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
            'index'  => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit'   => EditCategory::route('/{record}/edit'),
        ];
    }

    /**
     * @return string
     */
    public static function getNavigationLabel(): string
    {
        return __('admin/categories/categories.navigation_label');
    }

    /**
     * @return string
     */
    public static function getModelLabel(): string
    {
        return __('admin/categories/categories.labels.model');
    }

    /**
     * @return string
     */
    public static function getPluralModelLabel(): string
    {
        return __('admin/categories/categories.labels.plural_model');
    }
}
