<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ProductImports;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Catalog\ProductImports\Pages\CreateProductImportBatch;
use App\Filament\Resources\Catalog\ProductImports\Pages\ListProductImportBatches;
use App\Filament\Resources\Catalog\ProductImports\Schemas\ProductImportBatchForm;
use App\Filament\Resources\Catalog\ProductImports\Tables\ProductImportBatchesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Products\Imports\ProductImportBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductImportBatchResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string                $model                = ProductImportBatch::class;
    protected static string|BackedEnum|null $navigationIcon       = Heroicon::RectangleStack;
    protected static ?string                $recordTitleAttribute = 'source_name';
    protected static string|null|UnitEnum   $navigationGroup      = AdminNavigationGroupEnum::Catalog;

    public static function form(Schema $schema): Schema
    {
        return ProductImportBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductImportBatchesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProductImportBatches::route('/'),
            'create' => CreateProductImportBatch::route('/create'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/product_imports/batches.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/product_imports/batches.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/product_imports/batches.labels.plural_model');
    }
}
