<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\ProductDeletes\Pages\CreateProductDelete;
use App\Filament\Resources\ProductDeletes\Pages\ListProductDeletes;
use App\Filament\Resources\ProductDeletes\Pages\ViewProductDeleteBatch;
use App\Filament\Resources\ProductDeletes\RelationManagers\ProductDeleteItemsRelationManager;
use App\Filament\Resources\ProductDeletes\Schemas\ProductDeleteForm;
use App\Filament\Resources\ProductDeletes\Tables\ProductDeletesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Products\Deletes\ProductDeleteBatch;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UnitEnum;

class ProductDeleteResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = ProductDeleteBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Trash;

    protected static ?string $recordTitleAttribute = 'source_name';

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::ProductManagement;
    protected static ?int                 $navigationSort  = 2;

    public static function form(Schema $schema): Schema
    {
        return ProductDeleteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductDeletesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin/product_imports/batches.titles.batch_details'))
                    ->columnSpanFull()
                    ->schema([
                        Grid::make()
                            ->columns(6)
                            ->schema([
                                TextEntry::make('id'),
                                TextEntry::make('source_type')
                                    ->label(__('admin/product_imports/batches.columns.source_type')),
                                TextEntry::make('source_name')
                                    ->label(__('admin/product_imports/batches.columns.source_name')),
                                TextEntry::make('status')
                                    ->label(__('admin/product_imports/batches.columns.status'))
                                    ->formatStateUsing(fn (string $state): string => __('admin/product_deletes/batches.statuses.'.$state))
                                    ->badge(),
                                TextEntry::make('total_items')
                                    ->label(__('admin/product_imports/batches.columns.total_items')),
                                TextEntry::make('processed_items')
                                    ->label(__('admin/product_imports/batches.columns.processed_items')),
                                TextEntry::make('failed_items')
                                    ->label(__('admin/product_imports/batches.columns.failed_items')),
                                TextEntry::make('options.last_error')
                                    ->label(__('admin/product_imports/batches.columns.last_error'))
                                    ->placeholder(__('admin/product_imports/batches.columns.empty_value')),
                                TextEntry::make('options.error_log_path')
                                    ->label(__('admin/product_imports/batches.columns.error_log'))
                                    ->url(fn (ProductDeleteBatch $record): ?string => $record->hasErrorLog()
                                        ? Storage::url((string) $record->getErrorLogPath())
                                        : null)
                                    ->openUrlInNewTab()
                                    ->formatStateUsing(fn (mixed $state): string => Str::trim((string) $state) !== ''
                                        ? __('admin/product_deletes/batches.actions.download_error_log')
                                        : __('admin/product_imports/batches.columns.empty_value')),
                                TextEntry::make('created_at')
                                    ->label(__('admin/default.columns.created_at'))
                                    ->dateTime(config('app.datetime_format'), config('app.timezone')),
                                TextEntry::make('started_at')
                                    ->label(__('admin/product_imports/batches.columns.started_at'))
                                    ->dateTime(config('app.datetime_format'), config('app.timezone')),
                                TextEntry::make('finished_at')
                                    ->label(__('admin/product_imports/batches.columns.finished_at'))
                                    ->dateTime(config('app.datetime_format'), config('app.timezone')),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ProductDeleteItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProductDeletes::route('/'),
            'create' => CreateProductDelete::route('/create'),
            'view'   => ViewProductDeleteBatch::route('/{record}'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin/product_deletes/batches.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('admin/product_deletes/batches.labels.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin/product_deletes/batches.labels.plural_model');
    }
}
