<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes\Tables;

use App\Enums\Product\Delete\ProductDeleteBatchesSourceTypeEnum;
use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Filament\Resources\ProductDeletes\ProductDeleteResource;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductDeletesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label(__('admin/product_imports/batches.columns.source_type'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => self::resolveSourceTypeLabel($state))
                    ->sortable(),
                TextColumn::make('source_name')
                    ->label(__('admin/product_imports/batches.columns.source_name'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('admin/product_deletes/batches.statuses.'.$state))
                    ->sortable(),
                TextColumn::make('total_items')->label(__('admin/product_imports/batches.columns.total_items'))->sortable(),
                TextColumn::make('processed_items')->label(__('admin/product_imports/batches.columns.processed_items'))->sortable(),
                TextColumn::make('failed_items')->label(__('admin/product_imports/batches.columns.failed_items'))->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->options([
                        ProductDeleteBatchesStatusEnum::NEW->value            => __('admin/product_deletes/batches.statuses.new'),
                        ProductDeleteBatchesStatusEnum::PROCESSING->value     => __('admin/product_deletes/batches.statuses.processing'),
                        ProductDeleteBatchesStatusEnum::COMPLETED->value      => __('admin/product_deletes/batches.statuses.completed'),
                        ProductDeleteBatchesStatusEnum::FAILED->value         => __('admin/product_deletes/batches.statuses.failed'),
                        ProductDeleteBatchesStatusEnum::PARTIAL_FAILED->value => __('admin/product_deletes/batches.statuses.partial_failed'),
                        ProductDeleteBatchesStatusEnum::CANCELED->value       => __('admin/product_deletes/batches.statuses.canceled'),
                    ]),
            ])
            ->recordActions([
                Action::make('openResult')
                    ->label(__('admin/product_deletes/batches.actions.open_result'))
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->disabled(fn (ProductDeleteBatch $record): bool => $record->isProcessing())
                    ->url(fn (ProductDeleteBatch $record): ?string => $record->isProcessing()
                        ? null
                        : ProductDeleteResource::getUrl('view', ['record' => $record])),
            ])
            ->recordUrl(fn (ProductDeleteBatch $record): ?string => ProductDeleteResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deleteProductsFromShops')
                        ->label(__('admin/product_deletes/batches.actions.delete_products_from_shops'))
                        ->icon(Heroicon::Trash)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_deletes/batches.actions.delete_products_from_shops'))
                        ->action(function (Collection $records): void {
                            $summary = app(ProductDeleteQueueService::class)->queueForDeleteBatches($records);

                            Notification::make()
                                ->title(__('admin/product_deletes/batches.messages.bulk_delete_queued'))
                                ->body(__('admin/product_deletes/batches.messages.bulk_delete_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retryFailedDeletes')
                        ->label(__('admin/product_deletes/batches.actions.retry_failed_deletes'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_deletes/batches.actions.retry_failed_deletes'))
                        ->action(function (Collection $records): void {
                            $summary = app(ProductDeleteQueueService::class)->retryFailedForDeleteBatches($records);

                            Notification::make()
                                ->title(__('admin/product_deletes/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_deletes/batches.messages.bulk_retry_result', $summary))
                                ->success()
                                ->send();
                        }),
                ])->dropdownWidth(Width::Large),
            ])
            ->defaultSort('id', 'desc');
    }

    private static function resolveSourceTypeLabel(string $state): string
    {
        return match ($state) {
            ProductDeleteBatchesSourceTypeEnum::EXCEL_FILE->value     => __('admin/product_deletes/batches.source_types.excel_file'),
            ProductDeleteBatchesSourceTypeEnum::GOOGLE_SHEET->value   => __('admin/product_deletes/batches.source_types.google_sheet'),
            ProductDeleteBatchesSourceTypeEnum::ADMIN_PANEL->value    => __('admin/product_deletes/batches.source_types.admin_panel'),
            ProductDeleteBatchesSourceTypeEnum::LOCAL_PRODUCTS->value => __('admin/product_deletes/batches.source_types.local_products'),
            default                                                   => $state,
        };
    }

}
