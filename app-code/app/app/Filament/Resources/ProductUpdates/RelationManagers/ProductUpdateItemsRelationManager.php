<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\RelationManagers;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ProductUpdateItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin/product_updates/batches.navigation_label');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.item_status'))
                    ->formatStateUsing(fn (string $state): string => __('admin/product_updates/batches.item_statuses.'.$state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('product_id')
                    ->label(__('admin/product_imports/batches.columns.product_id'))
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label(__('admin/product_imports/batches.columns.item_error'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('processed_at')
                    ->label(__('admin/product_imports/batches.columns.item_processed_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin/product_imports/batches.columns.item_status'))
                    ->options([
                        ProductUpdateItemsStatusEnum::NEW->value        => __('admin/product_updates/batches.item_statuses.new'),
                        ProductUpdateItemsStatusEnum::PROCESSING->value => __('admin/product_updates/batches.item_statuses.processing'),
                        ProductUpdateItemsStatusEnum::NORMALIZED->value => __('admin/product_updates/batches.item_statuses.normalized'),
                        ProductUpdateItemsStatusEnum::SUCCESSED->value  => __('admin/product_updates/batches.item_statuses.successed'),
                        ProductUpdateItemsStatusEnum::FAILED->value     => __('admin/product_updates/batches.item_statuses.failed'),
                    ]),
                SelectFilter::make('shop_id')
                    ->label(__('admin/product_imports/batches.filters.shop'))
                    ->options(fn (): array => Shop::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->query(static function ($query, array $data) {
                        $shop_id = (int) Arr::get($data, 'value', 0);
                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->whereRaw("(payload->>'shop_id')::int = ?", [$shop_id]);
                    }),
            ])
            ->recordActions([
                Action::make('viewPayload')
                    ->label(__('admin/product_imports/batches.actions.view_payload'))
                    ->icon(Heroicon::Eye)
                    ->modalWidth('7xl')
                    ->modalHeading(__('admin/product_imports/batches.actions.view_payload'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('actions.close'))
                    ->modalDescription(function (ProductUpdateItem $record): HtmlString {
                        $payload = $record->payload;

                        if (! is_array($payload) || $payload === []) {
                            return new HtmlString('<p>'.__('admin/product_imports/batches.messages.payload_is_empty').'</p>');
                        }

                        $formatted_json = json_encode(Arr::sortRecursive($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        return new HtmlString(
                            '<div class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 p-4">'
                            .'<pre class="w-full text-sm text-gray-800 dark:text-gray-200 overflow-x-auto whitespace-pre-wrap">'
                            .e((string) $formatted_json)
                            .'</pre></div>'
                        );
                    })
                    ->action(static fn (): null => null),
                Action::make('updateProductToShops')
                    ->label(__('admin/product_updates/batches.actions.update_product_to_shops'))
                    ->icon(Heroicon::ArrowPathRoundedSquare)
                    ->color('success')
                    ->visible(fn (ProductUpdateItem $record): bool => $this->hasBoundShopsForRecord($record))
                    ->disabled(fn (ProductUpdateItem $record): bool => $record->status === ProductUpdateItemsStatusEnum::PROCESSING->value)
                    ->action(function (ProductUpdateItem $record): void {
                        $shop_ids = $this->getBoundShopIdsForRecord($record);
                        if ($shop_ids === []) {
                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.item_update_needs_binding'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $summary = $this->queueUpdateForSingleItem($record, $shop_ids);

                        Notification::make()
                            ->title(__('admin/product_updates/batches.messages.item_update_queued'))
                            ->body(__('admin/product_updates/batches.messages.item_update_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('retryFailedProductUpdates')
                    ->label(__('admin/product_updates/batches.actions.retry_failed_updates'))
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(fn (ProductUpdateItem $record): bool => $record->status === ProductUpdateItemsStatusEnum::FAILED->value)
                    ->action(function (ProductUpdateItem $record): void {
                        $record->update([
                            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                            'error_message' => null,
                            'processed_at'  => null,
                        ]);

                        ProcessProductUpdateItemJob::dispatch((int) $record->id);

                        Notification::make()
                            ->title(__('admin/product_updates/batches.messages.item_retry_queued'))
                            ->body(__('admin/product_updates/batches.messages.item_retry_result', [
                                'failed_found' => 1,
                                'queued'       => 1,
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
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
                        ->action(function ($records, array $data): void {
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

                            $summary = $this->queueUpdateForSelectedItems(collect($records), $shop_ids);

                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_update_queued'))
                                ->body(__('admin/product_updates/batches.messages.bulk_update_items_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retryFailedUpdates')
                        ->label(__('admin/product_updates/batches.actions.retry_failed_updates'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_updates/batches.actions.retry_failed_updates'))
                        ->action(function ($records): void {
                            $records_collection = collect($records);

                            $failed_found = 0;
                            $queued       = 0;
                            foreach ($records_collection as $record) {
                                if (! $record instanceof ProductUpdateItem) {
                                    continue;
                                }
                                if ($record->status !== ProductUpdateItemsStatusEnum::FAILED->value) {
                                    continue;
                                }

                                $failed_found++;
                                $record->update([
                                    'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                                    'error_message' => null,
                                    'processed_at'  => null,
                                ]);
                                ProcessProductUpdateItemJob::dispatch((int) $record->id);
                                $queued++;
                            }

                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_updates/batches.messages.bulk_retry_result', [
                                    'failed_found' => $failed_found,
                                    'queued'       => $queued,
                                ]))
                                ->success()
                                ->send();
                        }),
                ])->dropdownWidth(Width::Large),
            ]);
    }

    private function hasBoundShopsForRecord(ProductUpdateItem $record): bool
    {
        return $this->getBoundShopIdsForRecord($record) !== [];
    }

    /**
     * @return list<int>
     */
    private function getBoundShopIdsForRecord(ProductUpdateItem $record): array
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return [];
        }

        return ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function queueUpdateForSingleItem(ProductUpdateItem $record, array $shop_ids): array
    {
        $summary = [
            'products_total'              => 0,
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_update_batch_id ?? 0);

        if ($product_id <= 0 || $batch_id <= 0) {
            return $summary;
        }

        $summary['products_total'] = 1;

        foreach ($shop_ids as $shop_id) {
            $queued_result = $this->queueUpdateForBatchProductShop($batch_id, $product_id, (int) $shop_id);

            $summary['updates_queued'] += (int) Arr::get($queued_result, 'updates_queued', 0);
            $summary['already_failed'] += (int) Arr::get($queued_result, 'already_failed', 0);
            $summary['already_queued_or_exported'] += (int) Arr::get($queued_result, 'already_queued_or_exported', 0);
            $summary['skipped_not_bound'] += (int) Arr::get($queued_result, 'skipped_not_bound', 0);
            $summary['skipped_without_external_id'] += (int) Arr::get($queued_result, 'skipped_without_external_id', 0);
            $summary['errors'] += (int) Arr::get($queued_result, 'errors', 0);
        }

        return $summary;
    }

    /**
     * @param  Collection<int, ProductUpdateItem>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function queueUpdateForSelectedItems(Collection $records, array $shop_ids): array
    {
        $summary = [
            'items_selected'                => $records->count(),
            'items_skipped_processing'      => 0,
            'items_skipped_without_product' => 0,
            'products_total'                => 0,
            'updates_queued'                => 0,
            'already_failed'                => 0,
            'already_queued_or_exported'    => 0,
            'skipped_not_bound'             => 0,
            'skipped_without_external_id'   => 0,
            'errors'                        => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductUpdateItem) {
                continue;
            }

            if ($record->status === ProductUpdateItemsStatusEnum::PROCESSING->value) {
                $summary['items_skipped_processing']++;

                continue;
            }

            if ((int) ($record->product_id ?? 0) <= 0) {
                $summary['items_skipped_without_product']++;

                continue;
            }

            $item_summary = $this->queueUpdateForSingleItem($record, $shop_ids);
            $summary['products_total'] += (int) ($item_summary['products_total'] ?? 0);
            $summary['updates_queued'] += (int) ($item_summary['updates_queued'] ?? 0);
            $summary['already_failed'] += (int) ($item_summary['already_failed'] ?? 0);
            $summary['already_queued_or_exported'] += (int) ($item_summary['already_queued_or_exported'] ?? 0);
            $summary['skipped_not_bound'] += (int) ($item_summary['skipped_not_bound'] ?? 0);
            $summary['skipped_without_external_id'] += (int) ($item_summary['skipped_without_external_id'] ?? 0);
            $summary['errors'] += (int) ($item_summary['errors'] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function queueUpdateForBatchProductShop(int $batch_id, int $product_id, int $shop_id): array
    {
        $summary = [
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'errors'                      => 0,
        ];

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
            'product_id'              => $product_id,
            'payload'                 => [
                'operation'            => 'update',
                'shop_id'              => $shop_id,
                'requested_product_id' => $product_id,
                'target_product_id'    => $product_id,
                'external_product_id'  => $external_product_id,
                'requested_by_user_id' => auth()->id(),
                'update_instructions'  => $this->resolvePreparedUpdateInstructions($batch_id, $product_id),
            ],
            'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
            'error_message' => null,
            'processed_at'  => null,
        ]);

        ProcessProductUpdateItemJob::dispatch((int) $update_item->id);
        $summary['updates_queued']++;

        $this->markBatchAsUpdating($batch_id);

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePreparedUpdateInstructions(int $batch_id, int $product_id): array
    {
        if ($batch_id <= 0 || $product_id <= 0) {
            return [];
        }

        $prepared_item = ProductUpdateItem::query()
            ->where('product_update_batch_id', $batch_id)
            ->where('product_id', $product_id)
            ->whereRaw("(payload->>'operation') = 'prepare_update'")
            ->orderByDesc('id')
            ->first();

        if (! $prepared_item instanceof ProductUpdateItem) {
            return [];
        }

        $prepared_payload    = is_array($prepared_item->payload) ? $prepared_item->payload : [];
        $update_instructions = Arr::get($prepared_payload, 'update_instructions', []);

        return is_array($update_instructions) ? $update_instructions : [];
    }

    private function markBatchAsUpdating(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductUpdateBatch::query()->find($batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $batch->update([
            'status'  => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'options' => [
                ...($batch->options ?? []),
                'update_state'       => 'processing',
                'update_started_at'  => get_now_date()->toDateTimeString(),
                'update_finished_at' => null,
            ],
        ]);
    }
}
