<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Tables;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
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
                    ->url(fn (ProductImportBatch $record): ?string => $record->hasErrorLog()
                        ? Storage::url((string) $record->getErrorLogPath())
                        : null)
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (mixed $state): string => Str::trim((string) $state) !== ''
                        ? __('admin/product_imports/batches.actions.download_error_log')
                        : __('admin/product_imports/batches.columns.empty_value')),
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
                    ->url(fn (ProductImportBatch $record): ?string => $record->hasErrorLog()
                        ? Storage::url((string) $record->getErrorLogPath())
                        : null)
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
                        if ($error_log_path !== null && Storage::exists($error_log_path)) {
                            Storage::delete($error_log_path);
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

                            $summary = self::bindSelectedBatchesToShops($records, $shop_ids);

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

                            $summary = self::queueExportForSelectedBatches($records, $shop_ids);

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
                            $summary = self::retryFailedExportsForBatches($records);

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

    /**
     * @param  Collection<int, ProductImportBatch>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function bindSelectedBatchesToShops(Collection $records, array $shop_ids): array
    {
        $summary = [
            'batches_selected'           => $records->count(),
            'batches_skipped_processing' => 0,
            'products_total'             => 0,
            'jobs_queued'                => 0,
            'skipped_already_bound'      => 0,
        ];

        foreach ($records as $batch) {
            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $batch_product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            $summary['products_total'] += count($batch_product_ids);

            foreach ($batch_product_ids as $product_id) {
                $source_item = $batch->items()
                    ->where('product_id', $product_id)
                    ->orderBy('id')
                    ->first();

                $source_payload = $source_item !== null && is_array($source_item->payload)
                    ? $source_item->payload
                    : [];

                foreach ($shop_ids as $shop_id) {
                    $already_bound = ProductShop::query()
                        ->where('product_id', (int) $product_id)
                        ->where('shop_id', (int) $shop_id)
                        ->exists();

                    if ($already_bound) {
                        $summary['skipped_already_bound']++;

                        continue;
                    }

                    ProcessProductShopBindingJob::dispatch(
                        (int) $product_id,
                        $shop_id,
                        (int) $batch->id,
                        $source_payload,
                        auth()->id()
                    );

                    $summary['jobs_queued']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductImportBatch>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function queueExportForSelectedBatches(Collection $records, array $shop_ids): array
    {
        $summary = [
            'batches_selected'           => $records->count(),
            'batches_skipped_processing' => 0,
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $batch) {
            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductImportItemsStatusEnum::SUCCESSED->value,
                    ProductImportItemsStatusEnum::NORMALIZED->value,
                ])
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            $summary['products_total'] += count($product_ids);

            foreach ($product_ids as $product_id) {
                foreach ($shop_ids as $shop_id) {
                    try {
                        $product_shop = ProductShop::query()
                            ->where('product_id', (int) $product_id)
                            ->where('shop_id', $shop_id)
                            ->orderByDesc('id')
                            ->first();

                        if (! $product_shop instanceof ProductShop) {
                            self::markExportAsFailedForNotBoundShop(
                                (int) $batch->id,
                                (int) $product_id,
                                $shop_id
                            );
                            $summary['skipped_not_bound']++;

                            continue;
                        }

                        $target_product_id = (int) ($product_shop->product_id ?? 0);
                        if ($target_product_id <= 0) {
                            self::markExportAsFailedForNotBoundShop(
                                (int) $batch->id,
                                (int) $product_id,
                                $shop_id
                            );
                            $summary['skipped_not_bound']++;

                            continue;
                        }

                        $resolved_batch_id = (int) ($product_shop->product_import_batch_id ?? 0);
                        if ($resolved_batch_id <= 0) {
                            $resolved_batch_id = (int) $batch->id;
                        }

                        $existing_export_item = ProductExportItem::query()
                            ->forBatchProductShop($resolved_batch_id, $target_product_id, $shop_id)
                            ->orderByDesc('id')
                            ->first();

                        if ($existing_export_item !== null) {
                            if ($existing_export_item->status === ProductExportItemsStatusEnum::FAILED->value) {
                                $summary['already_failed']++;
                            } else {
                                $summary['already_queued_or_exported']++;
                            }

                            continue;
                        }

                        $export_item = ProductExportItem::query()->create([
                            'batchable_type' => ProductImportBatch::class,
                            'batchable_id'   => $resolved_batch_id,
                            'product_id'     => $target_product_id,
                            'payload'        => [
                                'shop_id'              => $shop_id,
                                'requested_product_id' => $product_id,
                                'target_product_id'    => $target_product_id,
                                'requested_by_user_id' => auth()->id(),
                            ],
                            'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                            'error_message' => null,
                            'processed_at'  => null,
                        ]);

                        ProcessProductExportItemJob::dispatch((int) $export_item->id);
                        $summary['exports_queued']++;

                        $batch->update([
                            'status'  => ProductImportBatchesStatusEnum::PROCESSING->value,
                            'options' => [
                                ...($batch->options ?? []),
                                'export_state'       => 'processing',
                                'export_started_at'  => now()->toDateTimeString(),
                                'export_finished_at' => null,
                            ],
                        ]);
                    } catch (Throwable) {
                        $summary['errors']++;
                    }
                }
            }

            self::syncBatchTotalItems((int) $batch->id);
        }

        return $summary;
    }

    private static function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id): void
    {
        if ($batch_id <= 0 || $product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $error_message = 'Product is not bound to selected shop. Export skipped.';

        $existing_export_item = ProductExportItem::query()
            ->forBatchProductShop($batch_id, $product_id, $shop_id)
            ->orderByDesc('id')
            ->first();

        if ($existing_export_item instanceof ProductExportItem) {
            $existing_export_item->update([
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at'  => now(),
                'payload'       => [
                    ...(is_array($existing_export_item->payload) ? $existing_export_item->payload : []),
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => null,
                    'failure_reason'       => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
            ]);
        } else {
            ProductExportItem::query()->create([
                'batchable_type' => ProductImportBatch::class,
                'batchable_id'   => $batch_id,
                'product_id'     => $product_id,
                'payload'        => [
                    'shop_id'              => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id'    => null,
                    'failure_reason'       => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
                'status'        => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at'  => now(),
            ]);
        }

        Log::channel('stack')->warning('Batch export skipped: product is not bound to selected shop', [
            'batch_id'             => $batch_id,
            'product_id'           => $product_id,
            'shop_id'              => $shop_id,
            'requested_by_user_id' => auth()->id(),
        ]);
    }

    /**
     * @param  Collection<int, ProductImportBatch>  $records
     * @return array<string, int>
     */
    private static function retryFailedExportsForBatches(Collection $records): array
    {
        $batch_ids = $records
            ->map(static fn (ProductImportBatch $batch): int => (int) $batch->id)
            ->filter(static fn (int $batch_id): bool => $batch_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($batch_ids === []) {
            return [
                'failed_found' => 0,
                'queued'       => 0,
            ];
        }

        $failed_export_items = ProductExportItem::query()
            ->where('batchable_type', ProductImportBatch::class)
            ->whereIn('batchable_id', $batch_ids)
            ->where('status', ProductExportItemsStatusEnum::FAILED->value)
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($failed_export_items as $failed_export_item) {
            $failed_export_item->update([
                'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductExportItemJob::dispatch((int) $failed_export_item->id);
            $queued++;
        }

        if ($queued > 0) {
            ProductImportBatch::query()
                ->whereIn('id', $batch_ids)
                ->update([
                    'status' => ProductImportBatchesStatusEnum::PROCESSING->value,
                ]);

            foreach (ProductImportBatch::query()->whereIn('id', $batch_ids)->get() as $batch) {
                $batch->update([
                    'options' => [
                        ...($batch->options ?? []),
                        'export_state'       => 'processing',
                        'export_started_at'  => now()->toDateTimeString(),
                        'export_finished_at' => null,
                    ],
                ]);
            }
        }

        return [
            'failed_found' => $failed_export_items->count(),
            'queued'       => $queued,
        ];
    }

    private static function syncBatchTotalItems(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $total_items = ProductImportItem::query()
            ->where('product_import_batch_id', $batch_id)
            ->count();

        ProductImportBatch::query()
            ->whereKey($batch_id)
            ->update([
                'total_items' => $total_items,
            ]);
    }
}
