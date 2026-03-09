<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Tables;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessCatalogProductRestoreBatchJob;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use App\Services\Products\ProductResourceOptionsService;
use App\Services\Products\ProductTableActionService;
use App\Supports\Services\Products\ProductBackupRestoreService;
use App\Supports\Services\Products\ProductDeleteQueueService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'descriptions' => static fn ($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
                'productShops.shop' => static fn ($shop_query) => $shop_query
                    ->orderBy('name'),
                'importItem',
                'productToManufacturerBrand.manufacturer.descriptions',
                'productToManufacturerBrand.brand.descriptions',
            ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('product_name')
                    ->label(__('admin/products/products.columns.name'))
                    ->state(static fn (Product $record): string => self::resolveProductName($record))
                    ->searchable(
                        query: static fn (Builder $query, string $search): Builder => $query->whereHas(
                            'descriptions',
                            static fn (Builder $description_query): Builder => $description_query->whereRaw(
                                'LOWER(name) LIKE ?',
                                ['%'.mb_strtolower(Str::trim($search)).'%']
                            )
                        )
                    )
                    ->wrap(),

                TextColumn::make('sku')
                    ->label(__('admin/products/products.columns.sku'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('model')
                    ->label(__('admin/products/products.columns.model'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ean')
                    ->label(__('admin/products/products.columns.ean'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('manufacturer_name')
                    ->label('Manufacturer')
                    ->state(static fn (Product $record): string => (string) ($record->productToManufacturerBrand?->manufacturer?->manufacturer_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->state(static fn (Product $record): string => (string) ($record->productToManufacturerBrand?->brand?->brand_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('bound_shops')
                    ->label(__('admin/products/products.columns.shops'))
                    ->state(static fn (Product $record): string => self::resolveBoundShopsText($record))
                    ->wrap(),

                TextColumn::make('bound_batch_ids')
                    ->label(__('admin/products/products.columns.batch_ids'))
                    ->state(static fn (Product $record): string => self::resolveBoundBatchIdsText($record))
                    ->wrap(),

                IconColumn::make('status')
                    ->label(__('admin/products/products.columns.status'))
                    ->boolean()
                    ->state(static fn (Product $record): bool => (bool) $record->is_active),

                IconColumn::make('is_processed')
                    ->label(__('admin/products/products.columns.is_processed'))
                    ->boolean()
                    ->state(static fn (Product $record): bool => self::resolveProcessedState($record)),

                IconColumn::make('is_exported')
                    ->label(__('admin/products/products.columns.is_exported'))
                    ->boolean()
                    ->state(static fn (Product $record): bool => self::resolveExportedState($record)),

                TextColumn::make('date_added')
                    ->label(__('admin/products/products.columns.date_added'))
                    ->dateTime(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label(__('admin/products/products.filters.status'))
                    ->options([
                        '1' => __('admin/products/products.statuses.active'),
                        '0' => __('admin/products/products.statuses.inactive'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = Arr::get($data, 'value');

                        if (! in_array((string) $value, ['0', '1'], true)) {
                            return $query;
                        }

                        return $query->where('is_active', (bool) ((int) $value));
                    }),

                Filter::make('search_fields')
                    ->label(__('admin/products/products.filters.search_fields'))
                    ->schema([
                        TextInput::make('name')->label(__('admin/products/products.columns.name')),
                        TextInput::make('sku')->label(__('admin/products/products.columns.sku')),
                        TextInput::make('model')->label(__('admin/products/products.columns.model')),
                        TextInput::make('ean')->label(__('admin/products/products.columns.ean')),
                        TextInput::make('external_product_id')->label('External product id'),
                        TextInput::make('quantity')->label(__('admin/products/products.columns.quantity')),
                        TextInput::make('price')->label(__('admin/products/products.columns.price')),
                        TextInput::make('attribute_name')->label('Attribute name'),
                        TextInput::make('attribute_value')->label('Attribute value'),
                        TextInput::make('category_name')->label('Category name'),
                        TextInput::make('manufacturer_name')->label('Manufacturer'),
                        TextInput::make('brand_name')->label('Brand'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => self::applySearchFieldsQuery($query, $data)),

                SelectFilter::make('shop_id')
                    ->label(__('admin/products/products.filters.shop'))
                    ->options(static fn (): array => Shop::resolveActiveOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int) ($data['value'] ?? 0);

                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'productShops',
                            static fn (Builder $product_shops_query): Builder => $product_shops_query->where('shop_id', $shop_id)
                        );
                    }),

                SelectFilter::make('category_id')
                    ->label(__('admin/products/products.filters.category'))
                    ->options(static fn (): array => self::resolveCategoryFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $category_id = (int) ($data['value'] ?? 0);

                        if ($category_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'categories',
                            static fn (Builder $categories_query): Builder => $categories_query->where('categories.id', $category_id)
                        );
                    }),

                SelectFilter::make('attribute_id')
                    ->label(__('admin/products/products.filters.attribute'))
                    ->options(static fn (): array => self::resolveAttributeFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $attribute_id = (int) ($data['value'] ?? 0);

                        if ($attribute_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'attributes',
                            static fn (Builder $attributes_query): Builder => $attributes_query->where('attributes.id', $attribute_id)
                        );
                    }),
                SelectFilter::make('manufacturer_id')
                    ->label('Manufacturer')
                    ->options(static fn (): array => self::resolveManufacturerFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $manufacturer_id = (int) ($data['value'] ?? 0);

                        if ($manufacturer_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'productToManufacturerBrand',
                            static fn (Builder $binding_query): Builder => $binding_query->where('manufacturer_id', $manufacturer_id)
                        );
                    }),
                SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->options(static fn (): array => self::resolveBrandFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $brand_id = (int) ($data['value'] ?? 0);

                        if ($brand_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'productToManufacturerBrand',
                            static fn (Builder $binding_query): Builder => $binding_query->where('brand_id', $brand_id)
                        );
                    }),

                TernaryFilter::make('is_processed')
                    ->label(__('admin/products/products.filters.is_processed'))
                    ->queries(
                        true : static fn (Builder $query): Builder => $query->whereHas(
                            'importItem',
                            static fn (Builder $item_query): Builder => $item_query->whereIn('status', [
                                ProductImportItemsStatusEnum::NORMALIZED->value,
                                ProductImportItemsStatusEnum::SUCCESSED->value,
                            ])
                        ),
                        false: static fn (Builder $query): Builder => $query->whereDoesntHave(
                            'importItem',
                            static fn (Builder $item_query): Builder => $item_query->whereIn('status', [
                                ProductImportItemsStatusEnum::NORMALIZED->value,
                                ProductImportItemsStatusEnum::SUCCESSED->value,
                            ])
                        ),
                        blank: static fn (Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('is_exported')
                    ->label(__('admin/products/products.filters.is_exported'))
                    ->queries(
                        true : static fn (Builder $query): Builder => $query->whereHas(
                            'productShops',
                            static fn (Builder $product_shops_query): Builder => $product_shops_query
                                ->whereNotNull('external_product_id')
                                ->where('external_product_id', '>', 0)
                        ),
                        false: static fn (Builder $query): Builder => $query->whereDoesntHave(
                            'productShops',
                            static fn (Builder $product_shops_query): Builder => $product_shops_query
                                ->whereNotNull('external_product_id')
                                ->where('external_product_id', '>', 0)
                        ),
                        blank: static fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('updateProductToShops')
                    ->label(__('admin/products/products.actions.update_product_to_shops'))
                    ->icon(Heroicon::ArrowPathRoundedSquare)
                    ->color('success')
                    ->action(function (Product $record): void {
                        $shop_ids = ProductShop::query()
                            ->where('product_id', (int) ($record->id ?? 0))
                            ->pluck('shop_id')
                            ->map(static fn ($shop_id): int => (int) $shop_id)
                            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                            ->unique()
                            ->values()
                            ->all();

                        if ($shop_ids === []) {
                            Notification::make()
                                ->title(__('admin/products/products.messages.select_shops_required'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $summary = self::queueUpdateForSelectedProducts(
                            new Collection([$record]),
                            $shop_ids
                        );

                        Notification::make()
                            ->title(__('admin/products/products.messages.item_update_queued'))
                            ->body(__('admin/products/products.messages.item_update_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('restoreProductInShops')
                    ->label(__('admin/products/products.actions.restore_product_in_shops'))
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(static fn (Product $record): bool => self::hasValidExternalBackupForAnyBoundShop($record))
                    ->action(function (Product $record): void {
                        $shop_ids = ProductShop::query()
                            ->where('product_id', (int) ($record->id ?? 0))
                            ->pluck('shop_id')
                            ->map(static fn ($shop_id): int => (int) $shop_id)
                            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                            ->unique()
                            ->values()
                            ->all();

                        $summary = self::queueRestoreForSelectedProducts(
                            new Collection([$record]),
                            $shop_ids
                        );

                        Notification::make()
                            ->title(__('admin/products/products.messages.item_restore_queued'))
                            ->body(__('admin/products/products.messages.item_restore_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('deleteProductFromShops')
                    ->label(__('admin/products/products.actions.delete_product_from_shops'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->visible(static fn (Product $record): bool => self::hasAnyExternalBinding($record))
                    ->schema([
                        Select::make('shop_ids')
                            ->label(__('admin/products/products.filters.shop'))
                            ->options(fn (Product $record): array => self::resolveDeletableShopOptions($record))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(function (Product $record, array $data): void {
                        $shop_ids = self::resolveShopIdsFromActionData($data);

                        if ($shop_ids === []) {
                            Notification::make()
                                ->title(__('admin/products/products.messages.select_shops_required'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $summary = self::queueDeleteForSelectedProducts(new Collection([$record]), $shop_ids);

                        Notification::make()
                            ->title(__('admin/products/products.messages.item_delete_queued'))
                            ->body(__('admin/products/products.messages.item_delete_result', $summary))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('updateProductsToShops')
                        ->label(__('admin/products/products.actions.update_products_to_shops'))
                        ->icon(Heroicon::ArrowPathRoundedSquare)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/products/products.filters.shop'))
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
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = self::resolveShopIdsFromActionData($data);

                            if ($shop_ids === []) {
                                Notification::make()
                                    ->title(__('admin/products/products.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = self::queueUpdateForSelectedProducts($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_update_queued'))
                                ->body(__('admin/products/products.messages.bulk_update_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bindProductsToShops')
                        ->label(__('admin/products/products.actions.bind_products_to_shops'))
                        ->icon(Heroicon::Link)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/products/products.filters.shop'))
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
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = self::resolveShopIdsFromActionData($data);

                            if ($shop_ids === []) {
                                Notification::make()
                                    ->title(__('admin/products/products.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = self::bindSelectedProductsToShops($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_bind_queued'))
                                ->body(__('admin/products/products.messages.bulk_bind_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('exportProductsToShops')
                        ->label(__('admin/products/products.actions.export_products_to_shops'))
                        ->icon(Heroicon::CloudArrowUp)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/products/products.filters.shop'))
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
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = self::resolveShopIdsFromActionData($data);

                            if ($shop_ids === []) {
                                Notification::make()
                                    ->title(__('admin/products/products.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = self::queueExportForSelectedProducts($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_export_queued'))
                                ->body(__('admin/products/products.messages.bulk_export_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('restoreProductsInShops')
                        ->label(__('admin/products/products.actions.restore_products_in_shops'))
                        ->icon(Heroicon::ArrowUturnLeft)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/products/products.filters.shop'))
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
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = self::resolveShopIdsFromActionData($data);

                            $summary = self::queueRestoreForSelectedProducts($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_restore_queued'))
                                ->body(__('admin/products/products.messages.bulk_restore_result', $summary))
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('deleteProductsFromShops')
                        ->label(__('admin/products/products.actions.delete_products_from_shops'))
                        ->icon(Heroicon::Trash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/products/products.filters.shop'))
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
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = self::resolveShopIdsFromActionData($data);

                            if ($shop_ids === []) {
                                Notification::make()
                                    ->title(__('admin/products/products.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = self::queueDeleteForSelectedProducts($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_delete_queued'))
                                ->body(__('admin/products/products.messages.bulk_delete_result', $summary))
                                ->success()
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ])
                    ->dropdownWidth(Width::Large),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function applySearchFieldsQuery(Builder $query, array $data): Builder
    {
        $name                = Str::lower(Str::trim((string) Arr::get($data, 'name', '')));
        $sku                 = Str::lower(Str::trim((string) Arr::get($data, 'sku', '')));
        $model               = Str::lower(Str::trim((string) Arr::get($data, 'model', '')));
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
                'descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$name.'%']
                )
            );
        }

        if ($sku !== '') {
            $query->whereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', ['%'.$sku.'%']);
        }

        if ($model !== '') {
            $query->whereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', ['%'.$model.'%']);
        }

        if ($ean !== '') {
            $query->whereRaw('LOWER(COALESCE(ean, \'\')) LIKE ?', ['%'.$ean.'%']);
        }

        if ($external_product_id !== '') {
            if (is_numeric($external_product_id)) {
                $query->whereHas(
                    'productShops',
                    static fn (Builder $product_shops_query): Builder => $product_shops_query->where('external_product_id', (int) $external_product_id)
                );
            } else {
                $query->whereHas(
                    'productShops',
                    static fn (Builder $product_shops_query): Builder => $product_shops_query->whereRaw(
                        'LOWER(COALESCE(CAST(external_product_id AS TEXT), \'\')) LIKE ?',
                        ['%'.Str::lower($external_product_id).'%']
                    )
                );
            }
        }

        if ($quantity !== '') {
            if (is_numeric($quantity)) {
                $query->where('quantity', (int) $quantity);
            } else {
                $query->whereRaw('LOWER(COALESCE(CAST(quantity AS TEXT), \'\')) LIKE ?', ['%'.Str::lower($quantity).'%']);
            }
        }

        if ($price !== '') {
            if (is_numeric($price)) {
                $query->where('price', (float) $price);
            } else {
                $query->whereRaw('LOWER(COALESCE(CAST(price AS TEXT), \'\')) LIKE ?', ['%'.Str::lower($price).'%']);
            }
        }

        if ($attribute_name !== '') {
            $query->whereHas(
                'attributes.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$attribute_name.'%']
                )
            );
        }

        if ($attribute_value !== '') {
            $query->whereHas(
                'productToAttributes',
                static fn (Builder $product_to_attribute_query): Builder => $product_to_attribute_query->whereRaw(
                    'LOWER(COALESCE(text, \'\')) LIKE ?',
                    ['%'.$attribute_value.'%']
                )
            );
        }

        if ($category_name !== '') {
            $query->whereHas(
                'categories.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$category_name.'%']
                )
            );
        }

        if ($manufacturer_name !== '') {
            $query->whereHas(
                'productToManufacturerBrand.manufacturer.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$manufacturer_name.'%']
                )
            );
        }

        if ($brand_name !== '') {
            $query->whereHas(
                'productToManufacturerBrand.brand.descriptions',
                static fn (Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%'.$brand_name.'%']
                )
            );
        }

        return $query;
    }

    private static function resolveProductName(Product $product): string
    {
        $name = Str::trim((string) $product->product_name);

        if ($name !== '') {
            return $name;
        }

        return '#'.(int) $product->id;
    }

    private static function resolveBoundShopsText(Product $product): string
    {
        $shop_names = collect($product->productShops)
            ->map(static fn (ProductShop $product_shop): string => Str::squish((string) ($product_shop->shop?->name ?? '')))
            ->filter(static fn (string $shop_name): bool => $shop_name !== '')
            ->all();

        if ($shop_names === []) {
            $shop_names = ProductShop::resolveBoundShopNames((int) $product->id);
        }

        $unique_shop_names = [];
        foreach ($shop_names as $shop_name) {
            $unique_shop_names[Str::lower($shop_name)] = $shop_name;
        }

        if ($unique_shop_names === []) {
            return __('admin/products/products.columns.no_bound_shops');
        }

        return implode(', ', array_values($unique_shop_names));
    }

    private static function resolveBoundBatchIdsText(Product $product): string
    {
        $batch_ids = collect($product->productShops)
            ->pluck('product_import_batch_id')
            ->map(static fn ($batch_id): int => (int) $batch_id)
            ->filter(static fn (int $batch_id): bool => $batch_id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($batch_ids === []) {
            $batch_ids = ProductImportItem::query()
                ->where('product_id', (int) $product->id)
                ->pluck('product_import_batch_id')
                ->map(static fn ($batch_id): int => (int) $batch_id)
                ->filter(static fn (int $batch_id): bool => $batch_id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        if ($batch_ids === []) {
            return __('admin/products/products.columns.no_batch');
        }

        return implode(', ', array_map(static fn (int $batch_id): string => (string) $batch_id, $batch_ids));
    }

    private static function resolveProcessedState(Product $product): bool
    {
        $status = (string) ($product->importItem?->status ?? '');
        if ($status !== '') {
            return in_array($status, [
                ProductImportItemsStatusEnum::NORMALIZED->value,
                ProductImportItemsStatusEnum::SUCCESSED->value,
            ], true);
        }

        return ProductImportItem::query()
            ->where('product_id', (int) $product->id)
            ->whereIn('status', [
                ProductImportItemsStatusEnum::NORMALIZED->value,
                ProductImportItemsStatusEnum::SUCCESSED->value,
            ])
            ->exists();
    }

    private static function resolveExportedState(Product $product): bool
    {
        $product_shop_items = collect($product->productShops);
        if ($product_shop_items->isNotEmpty()) {
            return $product_shop_items->contains(
                static fn (ProductShop $product_shop): bool => (int) ($product_shop->external_product_id ?? 0) > 0
            );
        }

        return ProductShop::hasAnyExternalBinding((int) $product->id);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveCategoryFilterOptions(): array
    {
        return app(ProductResourceOptionsService::class)->getCategoryFilterOptions();
    }

    /**
     * @return array<int, string>
     */
    private static function resolveAttributeFilterOptions(): array
    {
        return app(ProductResourceOptionsService::class)->getAttributeFilterOptions();
    }

    /**
     * @return array<int, string>
     */
    private static function resolveManufacturerFilterOptions(): array
    {
        return app(ProductResourceOptionsService::class)->getManufacturerFilterOptions();
    }

    /**
     * @return array<int, string>
     */
    private static function resolveBrandFilterOptions(): array
    {
        return app(ProductResourceOptionsService::class)->getBrandFilterOptions();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function resolveShopIdsFromActionData(array $data): array
    {
        return normalize_positive_int_list((array) Arr::get($data, 'shop_ids', []));
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function queueDeleteForSelectedProducts(Collection $records, array $shop_ids): array
    {
        $product_ids = $records
            ->filter(static fn ($record): bool => $record instanceof Product)
            ->map(static fn (Product $record): int => (int) $record->id)
            ->filter(static fn (int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        return app(ProductDeleteQueueService::class)->queueForProductIdsAndShopIds(
            $product_ids,
            $shop_ids,
            'catalog_products',
            is_numeric(auth()->id()) ? (int) auth()->id() : null
        );
    }

    private static function hasAnyExternalBinding(Product $product): bool
    {
        return ProductShop::hasAnyExternalBinding((int) $product->id);
    }

    /**
     * @return array<int, string>
     */
    private static function resolveDeletableShopOptions(Product $product): array
    {
        return ProductShop::resolveDeletableShopOptions((int) $product->id);
    }

    /**
     * @param  iterable<mixed>  $records
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function queueUpdateForSelectedProducts(iterable $records, array $shop_ids): array
    {
        return app(ProductTableActionService::class)->queueUpdateForSelectedProducts($records, $shop_ids);
    }

    private static function hasValidExternalBackupForAnyBoundShop(Product $record): bool
    {
        return app(ProductTableActionService::class)->hasValidExternalBackupForAnyBoundShop($record);
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function queueRestoreForSelectedProducts(Collection $records, array $shop_ids): array
    {
        return app(ProductTableActionService::class)->queueRestoreForSelectedProducts($records, $shop_ids);
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function bindSelectedProductsToShops(Collection $records, array $shop_ids): array
    {
        return app(ProductTableActionService::class)->bindSelectedProductsToShops($records, $shop_ids);
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private static function queueExportForSelectedProducts(Collection $records, array $shop_ids): array
    {
        return app(ProductTableActionService::class)->queueExportForSelectedProducts($records, $shop_ids);
    }

    private static function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id): void
    {
        app(ProductTableActionService::class)->markExportAsFailedForNotBoundShop($batch_id, $product_id, $shop_id);
    }

    private static function resolveBatchIdForProductShop(int $product_id, int $shop_id, int $fallback_batch_id = 0): int
    {
        return app(ProductTableActionService::class)->resolveBatchIdForProductShop($product_id, $shop_id, $fallback_batch_id);
    }
}
