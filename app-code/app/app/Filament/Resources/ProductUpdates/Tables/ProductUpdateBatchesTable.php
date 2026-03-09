<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Tables;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Shops\Shop;
use App\Services\Products\ProductUpdateQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductUpdateBatchesTable
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
                    ->formatStateUsing(function (string $state, ProductUpdateBatch $record): string {
                        if (
                            $state === ProductUpdateBatchesStatusEnum::PROCESSING->value
                            && (string) $record->getOption('update_state', '') === 'processing'
                        ) {
                            return __('admin/product_updates/batches.statuses.updating_api');
                        }

                        return __('admin/product_updates/batches.statuses.'.$state);
                    })
                    ->sortable(),
                TextColumn::make('total_items')->label(__('admin/product_imports/batches.columns.total_items'))->sortable(),
                TextColumn::make('processed_items')->label(__('admin/product_imports/batches.columns.processed_items'))->sortable(),
                TextColumn::make('failed_items')->label(__('admin/product_imports/batches.columns.failed_items'))->sortable(),
                TextColumn::make('options.error_log_path')
                    ->label(__('admin/product_imports/batches.columns.error_log'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (ProductUpdateBatch $record): ?string => $record->hasErrorLog()
                        ? Storage::url((string) $record->getErrorLogPath())
                        : null)
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (mixed $state): string => Str::trim((string) $state) !== ''
                        ? __('admin/product_updates/batches.actions.download_error_log')
                        : __('admin/product_imports/batches.columns.empty_value')),
                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->options([
                        ProductUpdateBatchesStatusEnum::NEW->value            => __('admin/product_updates/batches.statuses.new'),
                        ProductUpdateBatchesStatusEnum::PROCESSING->value     => __('admin/product_updates/batches.statuses.processing'),
                        ProductUpdateBatchesStatusEnum::COMPLETED->value      => __('admin/product_updates/batches.statuses.completed'),
                        ProductUpdateBatchesStatusEnum::FAILED->value         => __('admin/product_updates/batches.statuses.failed'),
                        ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value => __('admin/product_updates/batches.statuses.partial_failed'),
                        ProductUpdateBatchesStatusEnum::CANCELED->value       => __('admin/product_updates/batches.statuses.canceled'),
                    ]),
            ])
            ->recordActions([
                Action::make('openResult')
                    ->label(__('admin/product_updates/batches.actions.open_result'))
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->disabled(fn (ProductUpdateBatch $record): bool => $record->isProcessing())
                    ->tooltip(fn (ProductUpdateBatch $record): ?string => $record->isProcessing()
                        ? __('admin/product_updates/batches.messages.processing_row_locked')
                        : null)
                    ->url(fn (ProductUpdateBatch $record): ?string => $record->isProcessing()
                        ? null
                        : ProductUpdateBatchResource::getUrl('view', ['record' => $record])),
            ])
            ->recordUrl(fn (ProductUpdateBatch $record): ?string => ProductUpdateBatchResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('updateProductsToShops')
                        ->label(__('admin/product_updates/batches.actions.update_products_to_shops'))
                        ->icon(Heroicon::ArrowPathRoundedSquare)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_updates/batches.actions.update_products_to_shops'))
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/product_imports/batches.product_edit.fields.bind_shop_id'))
                                ->options(fn (): array => Shop::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload()
                                ->helperText(__('admin/product_updates/batches.messages.bulk_update_select_shops')),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = collect($data['shop_ids'] ?? [])
                                ->map(static fn ($shop_id): int => (int) $shop_id)
                                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            if ($shop_ids === []) {
                                Notification::make()
                                    ->title(__('admin/product_updates/batches.messages.bulk_update_no_shops'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = app(ProductUpdateQueueService::class)->queueForUpdateBatches(
                                $records,
                                $shop_ids,
                                is_numeric(auth()->id()) ? (int) auth()->id() : null,
                            );

                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_update_queued'))
                                ->body(__('admin/product_updates/batches.messages.bulk_update_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retryFailedUpdates')
                        ->label(__('admin/product_updates/batches.actions.retry_failed_updates'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_updates/batches.actions.retry_failed_updates'))
                        ->action(function (Collection $records): void {
                            $summary = app(ProductUpdateQueueService::class)->retryFailedForUpdateBatches($records);

                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_updates/batches.messages.bulk_retry_result', $summary))
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
            ProductUpdateBatchesSourceTypeEnum::CSV_FILE->value         => __('admin/product_updates/batches.source_types.csv_file'),
            ProductUpdateBatchesSourceTypeEnum::EXCEL_FILE->value       => __('admin/product_updates/batches.source_types.excel_file'),
            ProductUpdateBatchesSourceTypeEnum::GOOGLE_SHEET->value     => __('admin/product_updates/batches.source_types.google_sheet'),
            ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value   => __('admin/product_updates/batches.source_types.local_products'),
            ProductUpdateBatchesSourceTypeEnum::EDIT_PRODUCT_API->value => __('admin/product_updates/batches.source_types.edit_product_api'),
            default                                                     => $state,
        };
    }

}
