<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes\Tables;

use App\Enums\Product\Delete\ProductDeleteBatchesSourceTypeEnum;
use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Filament\Resources\ProductDeletes\ProductDeleteResource;
use App\Jobs\ProcessProductDeleteItemJob;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
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
use Illuminate\Support\Arr;
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
                            $summary = self::queueDeleteForSelectedBatches($records);

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
                            $summary = self::retryFailedDeletesForBatches($records);

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

    /**
     * @param  Collection<int, ProductDeleteBatch>  $records
     * @return array<string, int>
     */
    private static function queueDeleteForSelectedBatches(Collection $records): array
    {
        $summary = [
            'batches_selected'           => $records->count(),
            'batches_skipped_processing' => 0,
            'items_total'                => 0,
            'deletes_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_deleted'  => 0,
            'skipped_missing_payload'    => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductDeleteBatch) {
                continue;
            }

            if ($batch->isProcessing()) {
                $summary['batches_skipped_processing']++;

                continue;
            }

            $items = $batch->items()
                ->whereNotNull('product_id')
                ->whereIn('status', [
                    ProductDeleteItemsStatusEnum::NEW->value,
                    ProductDeleteItemsStatusEnum::FAILED->value,
                ])
                ->get();

            $summary['items_total'] += $items->count();

            foreach ($items as $item) {
                $queued = self::queueDeleteForItem($item, true);

                $summary['deletes_queued'] += (int) ($queued['deletes_queued'] ?? 0);
                $summary['already_failed'] += (int) ($queued['already_failed'] ?? 0);
                $summary['already_queued_or_deleted'] += (int) ($queued['already_queued_or_deleted'] ?? 0);
                $summary['skipped_missing_payload'] += (int) ($queued['skipped_missing_payload'] ?? 0);
                $summary['errors'] += (int) ($queued['errors'] ?? 0);
            }

            app(ProductDeleteQueueService::class)->syncBatchStatusByItems((int) $batch->id);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductDeleteBatch>  $records
     * @return array<string, int>
     */
    private static function retryFailedDeletesForBatches(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
            'errors'       => 0,
        ];

        foreach ($records as $batch) {
            if (! $batch instanceof ProductDeleteBatch) {
                continue;
            }

            $failed_items = $batch->items()
                ->where('status', ProductDeleteItemsStatusEnum::FAILED->value)
                ->get();

            foreach ($failed_items as $failed_item) {
                $summary['failed_found']++;

                $queued = self::queueDeleteForItem($failed_item, true);
                $summary['queued'] += (int) ($queued['deletes_queued'] ?? 0);
                $summary['errors'] += (int) ($queued['errors'] ?? 0);
            }

            app(ProductDeleteQueueService::class)->syncBatchStatusByItems((int) $batch->id);
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public static function queueDeleteForItem(ProductDeleteItem $item, bool $allow_retry_from_failed = false): array
    {
        $summary = [
            'deletes_queued'            => 0,
            'already_failed'            => 0,
            'already_queued_or_deleted' => 0,
            'skipped_missing_payload'   => 0,
            'errors'                    => 0,
        ];

        if ($item->status === ProductDeleteItemsStatusEnum::PROCESSING->value) {
            $summary['already_queued_or_deleted']++;

            return $summary;
        }

        if ($item->status === ProductDeleteItemsStatusEnum::DELETED->value) {
            $summary['already_queued_or_deleted']++;

            return $summary;
        }

        if ($item->status === ProductDeleteItemsStatusEnum::FAILED->value && ! $allow_retry_from_failed) {
            $summary['already_failed']++;

            return $summary;
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($item->payload) ? $item->payload : [];
        $shop_id = (int) Arr::get($payload, 'shop_id', (int) Arr::get($payload, 'resolved_shop_id', 0));

        if ($shop_id <= 0) {
            $summary['skipped_missing_payload']++;

            $item->update([
                'status'        => ProductDeleteItemsStatusEnum::FAILED->value,
                'error_message' => 'Missing shop_id for delete operation',
                'processed_at'  => now(),
            ]);

            return $summary;
        }

        $external_product_id = (int) Arr::get($payload, 'external_product_id', (int) Arr::get($payload, 'resolved_external_product_id', 0));

        $item->update([
            'status'        => ProductDeleteItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
            'payload'       => [
                ...$payload,
                'operation'           => 'delete',
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id > 0 ? $external_product_id : null,
            ],
        ]);

        ProcessProductDeleteItemJob::dispatch((int) $item->id);
        $summary['deletes_queued']++;

        return $summary;
    }
}
