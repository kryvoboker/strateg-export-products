<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes\RelationManagers;

use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Models\Shops\Shop;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class ProductDeleteItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $owner_record, string $page_class): string
    {
        return __('admin/product_deletes/batches.titles.items');
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
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with(['product.descriptions'])
                    /** @phpstan-ignore-next-line */
                    ->where('product_delete_batch_id', (int) $this->getOwnerRecord()->id)
            )
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.item_status'))
                    ->formatStateUsing(fn (string $state): string => __('admin/product_deletes/batches.item_statuses.'.$state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('product_id')
                    ->label(__('admin/product_imports/batches.columns.product_id'))
                    ->sortable(),
                TextColumn::make('product.product_name')
                    ->label('Назва товару')
                    ->state(static fn (ProductDeleteItem $record): string => (string) ($record->product?->product_name ?? '#'.(int) ($record->product_id ?? 0)))
                    ->wrap(),
                TextColumn::make('product.model')
                    ->label('Model')
                    ->state(static fn (ProductDeleteItem $record): string => (string) ($record->product?->model ?? ''))
                    ->toggleable(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->state(static fn (ProductDeleteItem $record): string => (string) ($record->product?->sku ?? '')),
                TextColumn::make('product.ean')
                    ->label('EAN')
                    ->state(static fn (ProductDeleteItem $record): string => (string) ($record->product?->ean ?? ''))
                    ->toggleable(),
                TextColumn::make('external_product_id')
                    ->label('External product id')
                    ->state(static fn (ProductDeleteItem $record): string => (string) ((int) Arr::get($record->payload ?? [], 'external_product_id', (int) Arr::get($record->payload ?? [], 'resolved_external_product_id', 0)))),
                TextColumn::make('shop_name')
                    ->label('Магазин')
                    ->state(static function (ProductDeleteItem $record): string {
                        $shop_id = (int) Arr::get($record->payload ?? [], 'shop_id', (int) Arr::get($record->payload ?? [], 'resolved_shop_id', 0));

                        if ($shop_id <= 0) {
                            return '';
                        }

                        return (string) (Shop::query()->whereKey($shop_id)->value('name') ?? '');
                    }),
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
                        ProductDeleteItemsStatusEnum::NEW->value        => __('admin/product_deletes/batches.item_statuses.new'),
                        ProductDeleteItemsStatusEnum::PROCESSING->value => __('admin/product_deletes/batches.item_statuses.processing'),
                        ProductDeleteItemsStatusEnum::DELETED->value    => __('admin/product_deletes/batches.item_statuses.deleted'),
                        ProductDeleteItemsStatusEnum::FAILED->value     => __('admin/product_deletes/batches.item_statuses.failed'),
                    ]),
            ])
            ->recordActions([
                Action::make('viewPayload')
                    ->label(__('admin/product_imports/batches.actions.view_payload'))
                    ->icon(Heroicon::Eye)
                    ->modalWidth('7xl')
                    ->modalHeading(__('admin/product_imports/batches.actions.view_payload'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('actions.close'))
                    ->modalDescription(function (ProductDeleteItem $record): HtmlString {
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
                Action::make('deleteProductFromShop')
                    ->label(__('admin/product_deletes/batches.actions.delete_product_from_shop'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->visible(fn (ProductDeleteItem $record): bool => in_array($record->status, [
                        ProductDeleteItemsStatusEnum::NEW->value,
                        ProductDeleteItemsStatusEnum::FAILED->value,
                    ], true))
                    ->action(function (ProductDeleteItem $record): void {
                        $queued = app(ProductDeleteQueueService::class)->queueDeleteItem($record, true);

                        Notification::make()
                            ->title(__('admin/product_deletes/batches.messages.item_delete_queued'))
                            ->body(__('admin/product_deletes/batches.messages.item_delete_result', $queued))
                            ->success()
                            ->send();
                    }),
                Action::make('retryFailedDelete')
                    ->label(__('admin/product_deletes/batches.actions.retry_failed_deletes'))
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(fn (ProductDeleteItem $record): bool => $record->status === ProductDeleteItemsStatusEnum::FAILED->value)
                    ->action(function (ProductDeleteItem $record): void {
                        $queued = app(ProductDeleteQueueService::class)->queueDeleteItem($record, true);

                        Notification::make()
                            ->title(__('admin/product_deletes/batches.messages.item_retry_queued'))
                            ->body(__('admin/product_deletes/batches.messages.item_delete_result', $queued))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deleteProductsFromShops')
                        ->label(__('admin/product_deletes/batches.actions.delete_products_from_shops'))
                        ->icon(Heroicon::Trash)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $summary = app(ProductDeleteQueueService::class)->queueForDeleteItems($records, true);

                            Notification::make()
                                ->title(__('admin/product_deletes/batches.messages.bulk_delete_queued'))
                                ->body(__('admin/product_deletes/batches.messages.bulk_delete_items_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('retryFailedDeletes')
                        ->label(__('admin/product_deletes/batches.actions.retry_failed_deletes'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $summary = app(ProductDeleteQueueService::class)->retryFailedForDeleteItems($records);

                            Notification::make()
                                ->title(__('admin/product_deletes/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_deletes/batches.messages.bulk_retry_result', $summary))
                                ->success()
                                ->send();
                        }),
                ])->dropdownWidth(Width::Large),
            ])
            ->recordUrl(null);
    }

    private function getTypedOwnerRecord(): ProductDeleteBatch
    {
        /** @var ProductDeleteBatch $record */
        $record = $this->getOwnerRecord();

        return $record;
    }
}
