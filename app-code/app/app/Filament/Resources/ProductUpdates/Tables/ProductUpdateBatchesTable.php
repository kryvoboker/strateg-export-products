<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Tables;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

                        return __('admin/product_updates/batches.statuses.' . $state);
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
                        ProductUpdateBatchesStatusEnum::NEW->value => __('admin/product_updates/batches.statuses.new'),
                        ProductUpdateBatchesStatusEnum::PROCESSING->value => __('admin/product_updates/batches.statuses.processing'),
                        ProductUpdateBatchesStatusEnum::COMPLETED->value => __('admin/product_updates/batches.statuses.completed'),
                        ProductUpdateBatchesStatusEnum::FAILED->value => __('admin/product_updates/batches.statuses.failed'),
                        ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value => __('admin/product_updates/batches.statuses.partial_failed'),
                        ProductUpdateBatchesStatusEnum::CANCELED->value => __('admin/product_updates/batches.statuses.canceled'),
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

                            $summary = self::queueUpdateForSelectedBatches($records, $shop_ids);

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
                            $summary = self::retryFailedUpdatesForBatches($records);

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

    /**
     * @param Collection<int, ProductUpdateBatch> $records
     * @param list<int> $shop_ids
     * @return array<string, int>
     */
    private static function queueUpdateForSelectedBatches(Collection $records, array $shop_ids): array
    {
        $summary = [
            'batches_selected' => $records->count(),
            'batches_skipped_processing' => 0,
            'products_total' => 0,
            'updates_queued' => 0,
            'already_failed' => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound' => 0,
            'skipped_without_external_id' => 0,
            'errors' => 0,
        ];

        foreach ($records as $batch) {
            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $product_ids = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductUpdateItemsStatusEnum::SUCCESSED->value,
                    ProductUpdateItemsStatusEnum::NORMALIZED->value,
                    ProductUpdateItemsStatusEnum::NEW->value,
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
                    $queued = self::createUpdateItemAndDispatch((int) $batch->id, (int) $product_id, (int) $shop_id);

                    $summary['updates_queued'] += (int) Arr::get($queued, 'updates_queued', 0);
                    $summary['already_failed'] += (int) Arr::get($queued, 'already_failed', 0);
                    $summary['already_queued_or_exported'] += (int) Arr::get($queued, 'already_queued_or_exported', 0);
                    $summary['skipped_not_bound'] += (int) Arr::get($queued, 'skipped_not_bound', 0);
                    $summary['skipped_without_external_id'] += (int) Arr::get($queued, 'skipped_without_external_id', 0);
                    $summary['errors'] += (int) Arr::get($queued, 'errors', 0);
                }
            }
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private static function createUpdateItemAndDispatch(int $batch_id, int $product_id, int $shop_id): array
    {
        $summary = [
            'updates_queued' => 0,
            'already_failed' => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound' => 0,
            'skipped_without_external_id' => 0,
            'errors' => 0,
        ];

        try {
            $product_shop = ProductShop::query()
                ->where('product_id', $product_id)
                ->where('shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();

            if (! $product_shop instanceof ProductShop) {
                $summary['skipped_not_bound']++;

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                $summary['skipped_without_external_id']++;

                return $summary;
            }

            $existing_update_item = ProductUpdateItem::query()
                ->where('product_update_batch_id', $batch_id)
                ->where('product_id', $product_id)
                ->whereRaw("(payload->>'operation') = 'update'")
                ->whereRaw("(payload->>'shop_id')::int = ?", [$shop_id])
                ->orderByDesc('id')
                ->first();

            if ($existing_update_item instanceof ProductUpdateItem) {
                if ($existing_update_item->status === ProductUpdateItemsStatusEnum::FAILED->value) {
                    $summary['already_failed']++;
                } else {
                    $summary['already_queued_or_exported']++;
                }

                return $summary;
            }

            $update_item = ProductUpdateItem::query()->create([
                'product_update_batch_id' => $batch_id,
                'product_id' => $product_id,
                'payload' => [
                    'operation' => 'update',
                    'shop_id' => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id' => $product_id,
                    'external_product_id' => $external_product_id,
                    'requested_by_user_id' => auth()->id(),
                ],
                'status' => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at' => null,
            ]);

            ProcessProductUpdateItemJob::dispatch((int) $update_item->id);
            $summary['updates_queued']++;

            self::markBatchAsUpdating($batch_id);
        } catch (\Throwable) {
            $summary['errors']++;
        }

        return $summary;
    }

    /**
     * @param Collection<int, ProductUpdateBatch> $records
     * @return array<string, int>
     */
    private static function retryFailedUpdatesForBatches(Collection $records): array
    {
        $batch_ids = $records
            ->map(static fn (ProductUpdateBatch $batch): int => (int) $batch->id)
            ->filter(static fn (int $batch_id): bool => $batch_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($batch_ids === []) {
            return [
                'failed_found' => 0,
                'queued' => 0,
            ];
        }

        $failed_update_items = ProductUpdateItem::query()
            ->whereIn('product_update_batch_id', $batch_ids)
            ->where('status', ProductUpdateItemsStatusEnum::FAILED->value)
            ->whereRaw("(payload->>'operation') = 'update'")
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($failed_update_items as $failed_update_item) {
            $failed_update_item->update([
                'status' => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at' => null,
            ]);

            ProcessProductUpdateItemJob::dispatch((int) $failed_update_item->id);
            $queued++;
        }

        if ($queued > 0) {
            ProductUpdateBatch::query()
                ->whereIn('id', $batch_ids)
                ->update([
                    'status' => ProductUpdateBatchesStatusEnum::PROCESSING->value,
                ]);

            foreach (ProductUpdateBatch::query()->whereIn('id', $batch_ids)->get() as $batch) {
                $batch->update([
                    'options' => [
                        ...($batch->options ?? []),
                        'update_state' => 'processing',
                        'update_started_at' => get_now_date()->toDateTimeString(),
                        'update_finished_at' => null,
                    ],
                ]);
            }
        }

        return [
            'failed_found' => $failed_update_items->count(),
            'queued' => $queued,
        ];
    }

    private static function markBatchAsUpdating(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductUpdateBatch::query()->find($batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $batch->update([
            'status' => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'options' => [
                ...($batch->options ?? []),
                'update_state' => 'processing',
                'update_started_at' => get_now_date()->toDateTimeString(),
                'update_finished_at' => null,
            ],
        ]);

        Log::channel('stack')->info('Product update batch marked as processing', [
            'batch_id' => $batch_id,
        ]);
    }
}

