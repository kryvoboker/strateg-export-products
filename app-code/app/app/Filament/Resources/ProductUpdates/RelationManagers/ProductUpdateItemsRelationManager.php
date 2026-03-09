<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\RelationManagers;

use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
use App\Services\Products\ProductUpdateQueueService;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->with([
                    'product.descriptions',
                    'product.productToManufacturerBrand.manufacturer.descriptions',
                    'product.productToManufacturerBrand.brand.descriptions',
                ])
                /** @phpstan-ignore-next-line */
                ->where('product_update_batch_id', (int) $this->getOwnerRecord()->id)
            )
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
                TextColumn::make('product.product_name')
                    ->label('Назва товару')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->product_name ?? '#'.(int) ($record->product_id ?? 0)))
                    ->wrap(),
                TextColumn::make('product.model')
                    ->label('Model')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->model ?? ''))
                    ->toggleable(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->sku ?? '')),
                TextColumn::make('product.ean')
                    ->label('EAN')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->ean ?? ''))
                    ->toggleable(),
                TextColumn::make('manufacturer_name')
                    ->label('Manufacturer')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->productToManufacturerBrand?->manufacturer?->manufacturer_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->state(static fn (ProductUpdateItem $record): string => (string) ($record->product?->productToManufacturerBrand?->brand?->brand_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_product_id')
                    ->label('External product id')
                    ->state(static function (ProductUpdateItem $record): string {
                        $product_id = (int) ($record->product_id ?? 0);
                        $shop_id    = (int) Arr::get($record->payload ?? [], 'shop_id', 0);

                        if ($product_id <= 0) {
                            return '';
                        }

                        if ($shop_id > 0) {
                            $external_product_id = ProductShop::resolveExternalProductId($product_id, $shop_id);

                            return $external_product_id > 0 ? (string) $external_product_id : '';
                        }

                        return implode(', ', ProductShop::resolveExternalProductIds($product_id));
                    })
                    ->toggleable(),
                TextColumn::make('shop_name')
                    ->label('Магазин')
                    ->state(static function (ProductUpdateItem $record): string {
                        $shop_id = (int) Arr::get($record->payload ?? [], 'shop_id', 0);

                        if ($shop_id <= 0) {
                            $shop_id = (int) Arr::get($record->payload ?? [], 'resolved_shop_id', 0);
                        }

                        if ($shop_id <= 0) {
                            return '';
                        }

                        return Shop::resolveNameById($shop_id);
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
                Filter::make('search_fields')
                    ->label(__('admin/products/products.filters.search_fields'))
                    ->schema([
                        TextInput::make('name')->label('Назва товару'),
                        TextInput::make('model')->label('Model'),
                        TextInput::make('sku')->label('SKU'),
                        TextInput::make('ean')->label('EAN'),
                        TextInput::make('external_product_id')->label('External product id'),
                        TextInput::make('quantity')->label(__('admin/products/products.columns.quantity')),
                        TextInput::make('price')->label(__('admin/products/products.columns.price')),
                        TextInput::make('attribute_name')->label('Attribute name'),
                        TextInput::make('attribute_value')->label('Attribute value'),
                        TextInput::make('category_name')->label('Category name'),
                        TextInput::make('manufacturer_name')->label('Manufacturer'),
                        TextInput::make('brand_name')->label('Brand'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyBatchSearchFieldsQuery(
                        $query,
                        $data,
                        (int) $this->getTypedOwnerRecord()->id
                    )),
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
                    ->options(fn (): array => Shop::resolveActiveOptions())
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
                    ->visible(function (ProductUpdateItem $record): bool {
                        return $this->hasBoundShopsForRecord($record) &&
                            $record->status === ProductUpdateItemsStatusEnum::SUCCESSED->value;
                    })
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

                        $summary = app(ProductUpdateQueueService::class)->queueForSingleUpdateItem(
                            $record,
                            $shop_ids,
                            is_numeric(auth()->id()) ? (int) auth()->id() : null,
                        );

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
                        $summary = app(ProductUpdateQueueService::class)->retryFailedForSingleUpdateItem($record);

                        Notification::make()
                            ->title(__('admin/product_updates/batches.messages.item_retry_queued'))
                            ->body(__('admin/product_updates/batches.messages.item_retry_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('deleteProductFromShops')
                    ->label(__('admin/product_deletes/batches.actions.delete_product_from_shop'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->visible(fn (ProductUpdateItem $record): bool => $this->hasExternalProductIdForRecord($record))
                    ->schema([
                        Select::make('shop_ids')
                            ->label(__('admin/product_imports/batches.filters.shop'))
                            ->options(fn (ProductUpdateItem $record): array => $this->resolveDeleteShopOptionsForRecord($record))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(function (ProductUpdateItem $record, array $data): void {
                        $product_id = (int) ($record->product_id ?? 0);
                        $shop_ids = collect($data['shop_ids'] ?? [])
                            ->map(static fn ($shop_id): int => (int) $shop_id)
                            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                            ->unique()
                            ->values()
                            ->all();

                        if ($product_id <= 0 || $shop_ids === []) {
                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_update_no_shops'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $summary = app(ProductDeleteQueueService::class)->queueForProductIdsAndShopIds(
                            [$product_id],
                            $shop_ids,
                            'product_update_items',
                            is_numeric(auth()->id()) ? (int) auth()->id() : null
                        );

                        Notification::make()
                            ->title(__('admin/product_deletes/batches.messages.item_delete_queued'))
                            ->body(__('admin/product_deletes/batches.messages.item_delete_result', $summary))
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
                                ->options(fn (): array => Shop::resolveActiveOptions())
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

                            $summary = app(ProductUpdateQueueService::class)->queueForUpdateItems(
                                collect($records),
                                $shop_ids,
                                is_numeric(auth()->id()) ? (int) auth()->id() : null,
                            );

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
                            $summary = app(ProductUpdateQueueService::class)->retryFailedForUpdateItems(collect($records));

                            Notification::make()
                                ->title(__('admin/product_updates/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_updates/batches.messages.bulk_retry_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('deleteProductsFromShops')
                        ->label(__('admin/product_deletes/batches.actions.delete_products_from_shops'))
                        ->icon(Heroicon::Trash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/product_imports/batches.filters.shop'))
                                ->options(fn (): array => Shop::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function ($records, array $data): void {
                            $shop_ids = collect($data['shop_ids'] ?? [])
                                ->map(static fn ($shop_id): int => (int) $shop_id)
                                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                                ->unique()
                                ->values()
                                ->all();
                            $product_ids = collect($records)
                                ->filter(static fn ($record): bool => $record instanceof ProductUpdateItem)
                                ->map(static fn (ProductUpdateItem $record): int => (int) ($record->product_id ?? 0))
                                ->filter(static fn (int $product_id): bool => $product_id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            if ($shop_ids === [] || $product_ids === []) {
                                Notification::make()
                                    ->title(__('admin/product_updates/batches.messages.bulk_update_no_shops'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = app(ProductDeleteQueueService::class)->queueForProductIdsAndShopIds(
                                $product_ids,
                                $shop_ids,
                                'product_update_items',
                                is_numeric(auth()->id()) ? (int) auth()->id() : null
                            );

                            Notification::make()
                                ->title(__('admin/product_deletes/batches.messages.bulk_delete_queued'))
                                ->body(__('admin/product_deletes/batches.messages.bulk_delete_items_result', [
                                    ...$summary,
                                    'items_total' => count($product_ids),
                                ]))
                                ->success()
                                ->send();
                        }),
                ])->dropdownWidth(Width::Large),
            ])
            ->recordUrl(null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function applyBatchSearchFieldsQuery(Builder $query, array $data, int $batch_id): Builder
    {
        if ($batch_id > 0) {
            $query->where('product_update_batch_id', $batch_id);
        }

        $name                = Str::lower(Str::trim((string) Arr::get($data, 'name', '')));
        $model               = Str::lower(Str::trim((string) Arr::get($data, 'model', '')));
        $sku                 = Str::lower(Str::trim((string) Arr::get($data, 'sku', '')));
        $ean                 = Str::lower(Str::trim((string) Arr::get($data, 'ean', '')));
        $external_product_id = Str::trim((string) Arr::get($data, 'external_product_id', ''));
        $quantity            = Str::trim((string) Arr::get($data, 'quantity', ''));
        $price               = Str::trim((string) Arr::get($data, 'price', ''));
        $attribute_name      = Str::lower(Str::trim((string) Arr::get($data, 'attribute_name', '')));
        $attribute_value     = Str::lower(Str::trim((string) Arr::get($data, 'attribute_value', '')));
        $category_name       = Str::lower(Str::trim((string) Arr::get($data, 'category_name', '')));
        $manufacturer_name   = Str::lower(Str::trim((string) Arr::get($data, 'manufacturer_name', '')));
        $brand_name          = Str::lower(Str::trim((string) Arr::get($data, 'brand_name', '')));

        if ($name !== '') {
            $query->whereHas(
                'product.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$name.'%']
                )
            );
        }

        if ($model !== '') {
            $query->whereHas(
                'product',
                static fn (Builder $product_query): Builder => $product_query->whereRaw(
                    'LOWER(COALESCE(model, \'\')) LIKE ?',
                    ['%'.$model.'%']
                )
            );
        }

        if ($sku !== '') {
            $query->whereHas(
                'product',
                static fn (Builder $product_query): Builder => $product_query->whereRaw(
                    'LOWER(COALESCE(sku, \'\')) LIKE ?',
                    ['%'.$sku.'%']
                )
            );
        }

        if ($ean !== '') {
            $query->whereHas(
                'product',
                static fn (Builder $product_query): Builder => $product_query->whereRaw(
                    'LOWER(COALESCE(ean, \'\')) LIKE ?',
                    ['%'.$ean.'%']
                )
            );
        }

        if ($external_product_id !== '') {
            $query->whereExists(static function ($product_shop_query) use ($external_product_id): void {
                $product_shop_query
                    ->selectRaw('1')
                    ->from('product_shop')
                    ->whereColumn('product_shop.product_id', 'product_update_items.product_id');

                if (is_numeric($external_product_id)) {
                    $product_shop_query->where('product_shop.external_product_id', (int) $external_product_id);
                } else {
                    $product_shop_query->whereRaw(
                        'LOWER(COALESCE(CAST(product_shop.external_product_id AS TEXT), \'\')) LIKE ?',
                        ['%'.Str::lower($external_product_id).'%']
                    );
                }
            });
        }

        if ($quantity !== '') {
            $query->whereHas('product', static function (Builder $product_query) use ($quantity): Builder {
                if (is_numeric($quantity)) {
                    return $product_query->where('quantity', (int) $quantity);
                }

                return $product_query->whereRaw(
                    'LOWER(COALESCE(CAST(quantity AS TEXT), \'\')) LIKE ?',
                    ['%'.Str::lower($quantity).'%']
                );
            });
        }

        if ($price !== '') {
            $query->whereHas('product', static function (Builder $product_query) use ($price): Builder {
                if (is_numeric($price)) {
                    return $product_query->where('price', (float) $price);
                }

                return $product_query->whereRaw(
                    'LOWER(COALESCE(CAST(price AS TEXT), \'\')) LIKE ?',
                    ['%'.Str::lower($price).'%']
                );
            });
        }

        if ($attribute_name !== '') {
            $query->whereHas(
                'product.attributes.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$attribute_name.'%']
                )
            );
        }

        if ($attribute_value !== '') {
            $query->whereHas(
                'product.productToAttributes',
                static fn (Builder $product_to_attribute_query): Builder => $product_to_attribute_query->whereRaw(
                    'LOWER(COALESCE(text, \'\')) LIKE ?',
                    ['%'.$attribute_value.'%']
                )
            );
        }

        if ($category_name !== '') {
            $query->whereHas(
                'product.categories.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$category_name.'%']
                )
            );
        }

        if ($manufacturer_name !== '') {
            $query->whereHas(
                'product.productToManufacturerBrand.manufacturer.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$manufacturer_name.'%']
                )
            );
        }

        if ($brand_name !== '') {
            $query->whereHas(
                'product.productToManufacturerBrand.brand.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$brand_name.'%']
                )
            );
        }

        return $query;
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

        return ProductShop::resolveBoundShopIds($product_id);
    }

    private function hasExternalProductIdForRecord(ProductUpdateItem $record): bool
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return false;
        }

        return ProductShop::hasAnyExternalBinding($product_id);
    }

    /**
     * @return array<int, string>
     */
    private function resolveDeleteShopOptionsForRecord(ProductUpdateItem $record): array
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return [];
        }

        return ProductShop::resolveDeletableShopOptions($product_id);
    }

    private function getTypedOwnerRecord(): ProductUpdateBatch
    {
        /** @var ProductUpdateBatch $record */
        $record = $this->getOwnerRecord();

        return $record;
    }
}
