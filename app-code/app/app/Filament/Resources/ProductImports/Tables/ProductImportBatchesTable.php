<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Tables;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use App\Services\Products\ProductExportQueueService;
use App\Services\Products\ProductShopBindingQueueService;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProductImportBatchesTable
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
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'excel_file'  => __('admin/product_imports/batches.sources.excel'),
                            'api'         => __('admin/product_imports/batches.sources.google_sheets'),
                            'admin_panel' => __('admin/product_imports/batches.sources.admin_panel'),
                            default       => $state,
                        };
                    })
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_name')
                    ->label(__('admin/product_imports/batches.columns.source_name'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->badge()
                    ->formatStateUsing(function (string $state, ProductImportBatch $record): string {
                        if (
                            $state === ProductImportBatchesStatusEnum::PROCESSING->value
                            && (string) $record->getOption('export_state', '') === 'processing'
                        ) {
                            return __('admin/product_imports/batches.statuses.exporting_api');
                        }

                        return __('admin/product_imports/batches.statuses.'.$state);
                    })
                    ->colors([
                        'gray'    => fn ($state) => $state === ProductImportBatchesStatusEnum::NEW->value,
                        'warning' => fn ($state) => $state === ProductImportBatchesStatusEnum::PROCESSING->value,
                        'success' => fn ($state) => $state === 'completed',
                        'danger'  => fn ($state) => in_array($state, ['failed', 'partial_failed', 'canceled'], true),
                    ])
                    ->icon(fn (string $state): Heroicon => $state === ProductImportBatchesStatusEnum::PROCESSING->value
                        ? Heroicon::ArrowPath
                        : Heroicon::InformationCircle)
                    ->extraAttributes(fn (ProductImportBatch $record): array => $record->isProcessing()
                        ? ['class' => 'animate-pulse']
                        : [])
                    ->sortable(),
                TextColumn::make('total_items')->label(__('admin/product_imports/batches.columns.total_items'))->sortable(),
                TextColumn::make('processed_items')->label(__('admin/product_imports/batches.columns.processed_items'))->sortable(),
                TextColumn::make('failed_items')->label(__('admin/product_imports/batches.columns.failed_items'))->sortable(),
                TextColumn::make('options.last_error')
                    ->label(__('admin/product_imports/batches.columns.last_error'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (mixed $state): string => Str::limit(Str::trim((string) $state), 120)),
                TextColumn::make('options.error_log_path')
                    ->label(__('admin/product_imports/batches.columns.error_log'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (ProductImportBatch $record): ?string => $record->getErrorLogUrl())
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (mixed $state, ProductImportBatch $record): string => $record->getErrorLogFileName() ?? __('admin/product_imports/batches.columns.empty_value')),
                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label(__('admin/product_imports/batches.columns.started_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label(__('admin/product_imports/batches.columns.finished_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->options([
                        'new'            => __('admin/product_imports/batches.statuses.new'),
                        'processing'     => __('admin/product_imports/batches.statuses.processing'),
                        'completed'      => __('admin/product_imports/batches.statuses.completed'),
                        'failed'         => __('admin/product_imports/batches.statuses.failed'),
                        'partial_failed' => __('admin/product_imports/batches.statuses.partial_failed'),
                        'canceled'       => __('admin/product_imports/batches.statuses.canceled'),
                    ]),
            ])
            ->recordActions([
                Action::make('openResult')
                    ->label(__('admin/product_imports/batches.actions.open_result'))
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->disabled(fn (ProductImportBatch $record): bool => $record->isProcessing())
                    ->tooltip(fn (ProductImportBatch $record): ?string => $record->isProcessing()
                        ? __('admin/product_imports/batches.messages.processing_row_locked')
                        : null)
                    ->url(fn (ProductImportBatch $record): ?string => $record->isProcessing()
                        ? null
                        : ProductImportBatchResource::getUrl('view', ['record' => $record])),
                Action::make('downloadErrorLog')
                    ->label(__('admin/product_imports/batches.actions.download_error_log'))
                    ->icon(Heroicon::DocumentArrowDown)
                    ->visible(fn (ProductImportBatch $record): bool => $record->hasErrorLog())
                    ->url(fn (ProductImportBatch $record): ?string => $record->getErrorLogUrl())
                    ->openUrlInNewTab(),
                Action::make('deleteErrorLog')
                    ->label(__('admin/product_imports/batches.actions.delete_error_log'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin/product_imports/batches.messages.delete_error_log_confirmation'))
                    ->visible(fn (ProductImportBatch $record): bool => $record->hasErrorLog())
                    ->disabled(fn (ProductImportBatch $record): bool => (int) $record->user_id !== (int) auth()->id())
                    ->action(function (ProductImportBatch $record): void {
                        if ((int) $record->user_id !== (int) auth()->id()) {
                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.only_owner_can_delete_log'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $error_log_path = $record->getErrorLogPath();
                        if ($error_log_path !== null && Storage::disk('public')->exists($error_log_path)) {
                            Storage::disk('public')->delete($error_log_path);
                        }

                        $record->mergeOptions([
                            'error_log_path' => null,
                        ]);

                        Notification::make()
                            ->title(__('admin/product_imports/batches.messages.error_log_deleted'))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordUrl(function (ProductImportBatch $record): ?string {
                return ProductImportBatchResource::getUrl('view', ['record' => $record]);
            })
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bindProductsToShops')
                        ->label(__('admin/product_imports/batches.actions.bind_products_to_shops'))
                        ->icon(Heroicon::Link)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_imports/batches.actions.bind_products_to_shops'))
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
                                ->helperText(__('admin/product_imports/batches.messages.bulk_bind_select_shops')),
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
                                    ->title(__('admin/product_imports/batches.messages.bulk_bind_no_shops'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = app(ProductShopBindingQueueService::class)->queueForImportBatches(
                                $records,
                                $shop_ids,
                                is_numeric(auth()->id()) ? (int) auth()->id() : null,
                            );

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_bind_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_bind_queued_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('exportProductsToShops')
                        ->label(__('admin/product_imports/batches.actions.export_products_to_shops'))
                        ->icon(Heroicon::CloudArrowUp)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_imports/batches.actions.export_products_to_shops'))
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
                                ->helperText(__('admin/product_imports/batches.messages.bulk_export_select_shops')),
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
                                    ->title(__('admin/product_imports/batches.messages.bulk_bind_no_shops'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = app(ProductExportQueueService::class)->queueForImportBatches(
                                $records,
                                $shop_ids,
                                is_numeric(auth()->id()) ? (int) auth()->id() : null,
                            );

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_export_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_export_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('retryFailedExports')
                        ->label(__('admin/product_imports/batches.actions.retry_failed_exports'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_imports/batches.actions.retry_failed_exports'))
                        ->action(function (Collection $records): void {
                            $summary = app(ProductExportQueueService::class)->retryFailedForImportBatches($records);

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_retry_result', $summary))
                                ->success()
                                ->send();
                        }),
                ])
                    ->dropdownWidth(Width::Large),
            ])
            ->defaultSort('id', 'desc');
    }

}
