<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\ProductUpdates\Pages\CreateProductUpdateBatch;
use App\Filament\Resources\ProductUpdates\Pages\ListProductUpdateBatches;
use App\Filament\Resources\ProductUpdates\Pages\ViewProductUpdateBatch;
use App\Filament\Resources\ProductUpdates\RelationManagers\ProductUpdateItemsRelationManager;
use App\Filament\Resources\ProductUpdates\Schemas\ProductUpdateBatchForm;
use App\Filament\Resources\ProductUpdates\Tables\ProductUpdateBatchesTable;
use App\Filament\Resources\Trait\TotalModelItemsResourceTrait;
use App\Models\Products\Updates\ProductUpdateBatch;
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

class ProductUpdateBatchResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = ProductUpdateBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static ?string $recordTitleAttribute = 'source_name';

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::ProductManagement;
    protected static ?int                 $navigationSort  = 1;

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
                                    ->formatStateUsing(fn (string $state): string => __('admin/product_updates/batches.statuses.'.$state))
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
                                    ->url(fn (ProductUpdateBatch $record): ?string => $record->hasErrorLog()
                                        ? Storage::url((string) $record->getErrorLogPath())
                                        : null)
                                    ->openUrlInNewTab()
                                    ->formatStateUsing(fn (mixed $state): string => Str::trim((string) $state) !== ''
                                        ? __('admin/product_updates/batches.actions.download_error_log')
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
            ProductUpdateItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProductUpdateBatches::route('/'),
            'create' => CreateProductUpdateBatch::route('/create'),
            'view'   => ViewProductUpdateBatch::route('/{record}'),
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
