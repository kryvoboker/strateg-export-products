<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates;

use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Filament\Resources\ProductUpdates\RelationManagers\ProductUpdateItemsRelationManager;
use App\Filament\Resources\ProductUpdates\Pages\CreateProductUpdateBatch;
use App\Filament\Resources\ProductUpdates\Pages\ListProductUpdateBatches;
use App\Filament\Resources\ProductUpdates\Pages\ViewProductUpdateBatch;
use App\Filament\Resources\ProductUpdates\Schemas\ProductUpdateBatchForm;
use App\Filament\Resources\ProductUpdates\Tables\ProductUpdateBatchesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Products\Updates\ProductUpdateBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductUpdateBatchResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = ProductUpdateBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static ?string $recordTitleAttribute = 'source_name';

    public static function form(Schema $schema): Schema
    {
        return ProductUpdateBatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductUpdateBatchesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductImportBatchResource::infolist($schema);
    }

    public static function getRelations(): array
    {
        return [
            ProductUpdateItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductUpdateBatches::route('/'),
            'create' => CreateProductUpdateBatch::route('/create'),
            'view' => ViewProductUpdateBatch::route('/{record}'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/product_updates/batches.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/product_updates/batches.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/product_updates/batches.labels.plural_model');
    }
}
