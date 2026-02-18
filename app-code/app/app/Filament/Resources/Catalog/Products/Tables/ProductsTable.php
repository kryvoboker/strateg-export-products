<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Tables;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Shops\Shop;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->modifyQueryUsing(fn(Builder $query): Builder => $query->with([
                'descriptions'      => static fn($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
                'productShops.shop' => static fn($shop_query) => $shop_query
                    ->orderBy('name'),
                'importItem',
            ]))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('product_name')
                    ->label(__('admin/products/products.columns.name'))
                    ->state(static fn(Product $record): string => self::resolveProductName($record))
                    ->searchable(
                        query: static fn(Builder $query, string $search): Builder => $query->whereHas(
                            'descriptions',
                            static fn(Builder $description_query): Builder => $description_query->whereRaw(
                                'LOWER(name) LIKE ?',
                                ['%' . mb_strtolower(Str::trim($search)) . '%']
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

                TextColumn::make('bound_shops')
                    ->label(__('admin/products/products.columns.shops'))
                    ->state(static fn(Product $record): string => self::resolveBoundShopsText($record))
                    ->wrap(),

                TextColumn::make('bound_batch_ids')
                    ->label(__('admin/products/products.columns.batch_ids'))
                    ->state(static fn(Product $record): string => self::resolveBoundBatchIdsText($record))
                    ->wrap(),

                IconColumn::make('status')
                    ->label(__('admin/products/products.columns.status'))
                    ->boolean()
                    ->state(static fn(Product $record): bool => (bool)$record->is_active),

                IconColumn::make('is_processed')
                    ->label(__('admin/products/products.columns.is_processed'))
                    ->boolean()
                    ->state(static fn(Product $record): bool => self::resolveProcessedState($record)),

                IconColumn::make('is_exported')
                    ->label(__('admin/products/products.columns.is_exported'))
                    ->boolean()
                    ->state(static fn(Product $record): bool => self::resolveExportedState($record)),

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

                        if (!in_array((string)$value, ['0', '1'], true)) {
                            return $query;
                        }

                        return $query->where('is_active', (bool)((int)$value));
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
                    ])
                    ->query(static fn(Builder $query, array $data): Builder => self::applySearchFieldsQuery($query, $data)),

                SelectFilter::make('shop_id')
                    ->label(__('admin/products/products.filters.shop'))
                    ->options(static fn(): array => Shop::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int)($data['value'] ?? 0);

                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'productShops',
                            static fn(Builder $product_shops_query): Builder => $product_shops_query->where('shop_id', $shop_id)
                        );
                    }),

                SelectFilter::make('category_id')
                    ->label(__('admin/products/products.filters.category'))
                    ->options(static fn(): array => self::resolveCategoryFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $category_id = (int)($data['value'] ?? 0);

                        if ($category_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'categories',
                            static fn(Builder $categories_query): Builder => $categories_query->where('categories.id', $category_id)
                        );
                    }),

                SelectFilter::make('attribute_id')
                    ->label(__('admin/products/products.filters.attribute'))
                    ->options(static fn(): array => self::resolveAttributeFilterOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $attribute_id = (int)($data['value'] ?? 0);

                        if ($attribute_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas(
                            'attributes',
                            static fn(Builder $attributes_query): Builder => $attributes_query->where('attributes.id', $attribute_id)
                        );
                    }),

                TernaryFilter::make('is_processed')
                    ->label(__('admin/products/products.filters.is_processed'))
                    ->queries(
                        true : static fn(Builder $query): Builder => $query->whereHas(
                            'importItem',
                            static fn(Builder $item_query): Builder => $item_query->whereIn('status', [
                                ProductImportItemsStatusEnum::NORMALIZED->value,
                                ProductImportItemsStatusEnum::SUCCESSED->value,
                            ])
                        ),
                        false: static fn(Builder $query): Builder => $query->whereDoesntHave(
                            'importItem',
                            static fn(Builder $item_query): Builder => $item_query->whereIn('status', [
                                ProductImportItemsStatusEnum::NORMALIZED->value,
                                ProductImportItemsStatusEnum::SUCCESSED->value,
                            ])
                        ),
                        blank: static fn(Builder $query): Builder => $query,
                    ),

                TernaryFilter::make('is_exported')
                    ->label(__('admin/products/products.filters.is_exported'))
                    ->queries(
                        true : static fn(Builder $query): Builder => $query->whereHas(
                            'productShops',
                            static fn(Builder $product_shops_query): Builder => $product_shops_query
                                ->whereNotNull('external_product_id')
                                ->where('external_product_id', '>', 0)
                        ),
                        false: static fn(Builder $query): Builder => $query->whereDoesntHave(
                            'productShops',
                            static fn(Builder $product_shops_query): Builder => $product_shops_query
                                ->whereNotNull('external_product_id')
                                ->where('external_product_id', '>', 0)
                        ),
                        blank: static fn(Builder $query): Builder => $query,
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
                                ->options(fn(): array => Shop::query()
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
                            $shop_ids = collect($data['shop_ids'] ?? [])
                                ->map(static fn($shop_id): int => (int)$shop_id)
                                ->filter(static fn(int $shop_id): bool => $shop_id > 0)
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
                                ->options(fn(): array => Shop::query()
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
                            $shop_ids = collect($data['shop_ids'] ?? [])
                                ->map(static fn($shop_id): int => (int)$shop_id)
                                ->filter(static fn(int $shop_id): bool => $shop_id > 0)
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
                                ->options(fn(): array => Shop::query()
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
                            $shop_ids = collect($data['shop_ids'] ?? [])
                                ->map(static fn($shop_id): int => (int)$shop_id)
                                ->filter(static fn(int $shop_id): bool => $shop_id > 0)
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

                            $summary = self::queueExportForSelectedProducts($records, $shop_ids);

                            Notification::make()
                                ->title(__('admin/products/products.messages.bulk_export_queued'))
                                ->body(__('admin/products/products.messages.bulk_export_result', $summary))
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
     * @param array<string, mixed> $data
     */
    public static function applySearchFieldsQuery(Builder $query, array $data): Builder
    {
        $name                = Str::lower(Str::trim((string)Arr::get($data, 'name', '')));
        $sku                 = Str::lower(Str::trim((string)Arr::get($data, 'sku', '')));
        $model               = Str::lower(Str::trim((string)Arr::get($data, 'model', '')));
        $ean                 = Str::lower(Str::trim((string)Arr::get($data, 'ean', '')));
        $external_product_id = Str::trim((string)Arr::get($data, 'external_product_id', ''));
        $quantity            = Str::trim((string)Arr::get($data, 'quantity', ''));
        $price               = Str::trim((string)Arr::get($data, 'price', ''));
        $attribute_name      = Str::lower(Str::trim((string)Arr::get($data, 'attribute_name', '')));
        $attribute_value     = Str::lower(Str::trim((string)Arr::get($data, 'attribute_value', '')));
        $category_name       = Str::lower(Str::trim((string)Arr::get($data, 'category_name', '')));

        if ($name !== '') {
            $query->whereHas(
                'descriptions',
                static fn(Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . $name . '%']
                )
            );
        }

        if ($sku !== '') {
            $query->whereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', ['%' . $sku . '%']);
        }

        if ($model !== '') {
            $query->whereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', ['%' . $model . '%']);
        }

        if ($ean !== '') {
            $query->whereRaw('LOWER(COALESCE(ean, \'\')) LIKE ?', ['%' . $ean . '%']);
        }

        if ($external_product_id !== '') {
            if (is_numeric($external_product_id)) {
                $query->whereHas(
                    'productShops',
                    static fn(Builder $product_shops_query): Builder => $product_shops_query->where('external_product_id', (int)$external_product_id)
                );
            } else {
                $query->whereHas(
                    'productShops',
                    static fn(Builder $product_shops_query): Builder => $product_shops_query->whereRaw(
                        'LOWER(COALESCE(CAST(external_product_id AS TEXT), \'\')) LIKE ?',
                        ['%' . Str::lower($external_product_id) . '%']
                    )
                );
            }
        }

        if ($quantity !== '') {
            if (is_numeric($quantity)) {
                $query->where('quantity', (int)$quantity);
            } else {
                $query->whereRaw('LOWER(COALESCE(CAST(quantity AS TEXT), \'\')) LIKE ?', ['%' . Str::lower($quantity) . '%']);
            }
        }

        if ($price !== '') {
            if (is_numeric($price)) {
                $query->where('price', (float)$price);
            } else {
                $query->whereRaw('LOWER(COALESCE(CAST(price AS TEXT), \'\')) LIKE ?', ['%' . Str::lower($price) . '%']);
            }
        }

        if ($attribute_name !== '') {
            $query->whereHas(
                'attributes.descriptions',
                static fn(Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . $attribute_name . '%']
                )
            );
        }

        if ($attribute_value !== '') {
            $query->whereHas(
                'productToAttributes',
                static fn(Builder $product_to_attribute_query): Builder => $product_to_attribute_query->whereRaw(
                    'LOWER(COALESCE(text, \'\')) LIKE ?',
                    ['%' . $attribute_value . '%']
                )
            );
        }

        if ($category_name !== '') {
            $query->whereHas(
                'categories.descriptions',
                static fn(Builder $description_query): Builder => $description_query->whereRaw(
                    'LOWER(name) LIKE ?',
                    ['%' . $category_name . '%']
                )
            );
        }

        return $query;
    }

    private static function resolveProductName(Product $product): string
    {
        $name = Str::trim((string)$product->product_name);

        if ($name !== '') {
            return $name;
        }

        return '#' . (int)$product->id;
    }

    private static function resolveBoundShopsText(Product $product): string
    {
        $shop_names = collect($product->productShops)
            ->map(static fn(ProductShop $product_shop): string => Str::squish((string)($product_shop->shop?->name ?? '')))
            ->filter(static fn(string $shop_name): bool => $shop_name !== '')
            ->all();

        if ($shop_names === []) {
            $shop_names = ProductShop::query()
                ->where('product_id', (int)$product->id)
                ->join('shops', 'shops.id', '=', 'product_shop.shop_id')
                ->orderBy('shops.name')
                ->pluck('shops.name')
                ->map(static fn($shop_name): string => Str::squish((string)$shop_name))
                ->filter(static fn(string $shop_name): bool => $shop_name !== '')
                ->all();
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
            ->map(static fn($batch_id): int => (int)$batch_id)
            ->filter(static fn(int $batch_id): bool => $batch_id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($batch_ids === []) {
            $batch_ids = ProductImportItem::query()
                ->where('product_id', (int)$product->id)
                ->pluck('product_import_batch_id')
                ->map(static fn($batch_id): int => (int)$batch_id)
                ->filter(static fn(int $batch_id): bool => $batch_id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        if ($batch_ids === []) {
            return __('admin/products/products.columns.no_batch');
        }

        return implode(', ', array_map(static fn(int $batch_id): string => (string)$batch_id, $batch_ids));
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
            ->where('product_id', (int)$product->id)
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
                static fn(ProductShop $product_shop): bool => (int)($product_shop->external_product_id ?? 0) > 0
            );
        }

        return ProductShop::query()
            ->where('product_id', (int)$product->id)
            ->whereNotNull('external_product_id')
            ->where('external_product_id', '>', 0)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private static function resolveCategoryFilterOptions(): array
    {
        $categories = Category::query()
            ->with([
                'descriptions' => static fn($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get(['id']);

        $options = [];
        foreach ($categories as $category) {
            $name = Str::trim((string)($category->descriptions->first()?->name ?? ''));
            if ($name === '') {
                $name = Str::trim((string)(CategoryDescription::query()
                    ->where('category_id', (int)$category->id)
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id')
                    ->value('name') ?? ''));
            }

            $options[(int)$category->id] = $name !== '' ? $name : ('#' . (int)$category->id);
        }

        asort($options);

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function resolveAttributeFilterOptions(): array
    {
        $attributes = Attribute::query()
            ->with([
                'descriptions' => static fn($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get(['id']);

        $options = [];
        foreach ($attributes as $attribute) {
            $name = Str::trim((string)($attribute->descriptions->first()?->name ?? ''));
            if ($name === '') {
                $name = Str::trim((string)(AttributeDescription::query()
                    ->where('attribute_id', (int)$attribute->id)
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id')
                    ->value('name') ?? ''));
            }

            $options[(int)$attribute->id] = $name !== '' ? $name : ('#' . (int)$attribute->id);
        }

        asort($options);

        return $options;
    }

    /**
     * @param list<int> $shop_ids
     *
     * @return array<string, int>
     */
    private static function queueUpdateForSelectedProducts(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total'              => 0,
            'shops_total'                 => count($shop_ids),
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        $product_ids = $records
            ->filter(static fn($record): bool => $record instanceof Product)
            ->map(static fn(Product $record): int => (int) $record->id)
            ->filter(static fn(int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($product_ids === [] || $shop_ids === []) {
            return $summary;
        }

        $summary['products_total'] = count($product_ids);
        $requested_by_user_id      = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => $requested_by_user_id,
            'source_type'     => ProductUpdateBatchesSourceTypeEnum::LOCAL_PRODUCTS->value,
            'source_name'     => 'Catalog local products update',
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'triggered_from'      => 'catalog_products',
                'requested_by_user_id' => $requested_by_user_id,
                'update_state'        => 'processing',
                'update_started_at'   => now()->toDateTimeString(),
                'update_finished_at'  => null,
            ],
            'started_at'      => now(),
            'finished_at'     => null,
        ]);

        foreach ($product_ids as $product_id) {
            foreach ($shop_ids as $shop_id) {
                $queued_result = self::createLocalUpdateItemAndDispatch(
                    (int) $batch->id,
                    (int) $product_id,
                    (int) $shop_id,
                    $requested_by_user_id
                );

                $summary['updates_queued'] += (int) Arr::get($queued_result, 'updates_queued', 0);
                $summary['already_failed'] += (int) Arr::get($queued_result, 'already_failed', 0);
                $summary['already_queued_or_exported'] += (int) Arr::get($queued_result, 'already_queued_or_exported', 0);
                $summary['skipped_not_bound'] += (int) Arr::get($queued_result, 'skipped_not_bound', 0);
                $summary['skipped_without_external_id'] += (int) Arr::get($queued_result, 'skipped_without_external_id', 0);
                $summary['failed_created'] += (int) Arr::get($queued_result, 'failed_created', 0);
                $summary['errors'] += (int) Arr::get($queued_result, 'errors', 0);
            }
        }

        self::syncLocalUpdateBatchStatus((int) $batch->id);

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private static function createLocalUpdateItemAndDispatch(
        int $batch_id,
        int $product_id,
        int $shop_id,
        ?int $requested_by_user_id = null
    ): array {
        $summary = [
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        try {
            $existing_update_item = ProductUpdateItem::query()
                ->where('product_update_batch_id', $batch_id)
                ->where('product_id', $product_id)
                ->where('payload->operation', 'update')
                ->where('payload->shop_id', $shop_id)
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

            $product_shop = ProductShop::query()
                ->where('product_id', $product_id)
                ->where('shop_id', $shop_id)
                ->orderByDesc('id')
                ->first();

            if (! $product_shop instanceof ProductShop) {
                self::createLocalFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'Product is not bound to selected shop',
                    $requested_by_user_id
                );
                $summary['skipped_not_bound']++;
                $summary['failed_created']++;

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                self::createLocalFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'External product id is missing for update',
                    $requested_by_user_id
                );
                $summary['skipped_without_external_id']++;
                $summary['failed_created']++;

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
                    'requested_by_user_id' => $requested_by_user_id,
                    'triggered_from'       => 'catalog_products',
                    'update_instructions'  => [],
                ],
                'status'        => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductUpdateItemJob::dispatchSync((int) $update_item->id);
            $summary['updates_queued']++;
        } catch (QueryException $exception) {
            $sql_state = (string) ($exception->errorInfo[0] ?? '');

            if ($sql_state === '23505') {
                $summary['already_queued_or_exported']++;

                return $summary;
            }

            Log::channel('stack')->error('Failed to create local product update item', [
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'message'    => $exception->getMessage(),
            ]);

            $summary['errors']++;
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to queue local product update', [
                'batch_id'   => $batch_id,
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'message'    => $exception->getMessage(),
            ]);

            $summary['errors']++;
        }

        return $summary;
    }

    private static function createLocalFailedUpdateItem(
        int $batch_id,
        int $product_id,
        int $shop_id,
        string $error_message,
        ?int $requested_by_user_id = null
    ): void {
        Log::channel('stack')->error('Local product update skipped', [
            'batch_id'   => $batch_id,
            'product_id' => $product_id,
            'shop_id'    => $shop_id,
            'message'    => $error_message,
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => $batch_id,
            'product_id'              => $product_id,
            'payload'                 => [
                'operation'            => 'update',
                'shop_id'              => $shop_id,
                'requested_product_id' => $product_id,
                'target_product_id'    => $product_id,
                'requested_by_user_id' => $requested_by_user_id,
                'triggered_from'       => 'catalog_products',
                'update_instructions'  => [],
            ],
            'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at'  => now(),
        ]);
    }

    private static function syncLocalUpdateBatchStatus(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductUpdateBatch::query()->find($batch_id);
        if (! $batch instanceof ProductUpdateBatch) {
            return;
        }

        $status_rows = ProductUpdateItem::query()
            ->selectRaw('status, COUNT(*) AS status_total')
            ->where('product_update_batch_id', $batch_id)
            ->where('payload->operation', 'update')
            ->groupBy('status')
            ->get();

        $total_update_items = (int) $status_rows->sum(static fn($row): int => (int) ($row->status_total ?? 0));
        if ($total_update_items <= 0) {
            return;
        }

        $processing_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::PROCESSING->value)->status_total ?? 0);
        $failed_count     = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::FAILED->value)->status_total ?? 0);
        $updated_count    = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::SUCCESSED->value)->status_total ?? 0);

        $final_status = match (true) {
            $processing_count > 0                            => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            $failed_count > 0 && $updated_count > 0         => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $updated_count === 0       => ProductUpdateBatchesStatusEnum::FAILED->value,
            default                                          => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status'          => $final_status,
            'total_items'     => $total_update_items,
            'processed_items' => max($updated_count + $failed_count, 0),
            'failed_items'    => max($failed_count, 0),
            'finished_at'     => $processing_count > 0 ? null : now(),
            'options'         => [
                ...($batch->options ?? []),
                'update_state'         => $processing_count > 0 ? 'processing' : 'finished',
                'update_total_items'   => $total_update_items,
                'update_success_items' => $updated_count,
                'update_failed_items'  => $failed_count,
                'update_finished_at'   => $processing_count > 0 ? null : now()->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @param list<int> $shop_ids
     *
     * @return array<string, int>
     */
    private static function bindSelectedProductsToShops(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total' => 0,
            'shops_total'    => count($shop_ids),
            'jobs_queued'    => 0,
            'skipped_already_bound' => 0,
        ];

        foreach ($records as $record) {
            if (!$record instanceof Product) {
                continue;
            }

            $summary['products_total']++;

            $source_item = ProductImportItem::query()
                ->where('product_id', (int)$record->id)
                ->orderByDesc('id')
                ->first();

            $source_payload = $source_item !== null && is_array($source_item->payload)
                ? $source_item->payload
                : [];

            $product_import_batch_id = (int)($source_item?->product_import_batch_id ?? 0);

            foreach ($shop_ids as $shop_id) {
                $already_bound = ProductShop::query()
                    ->where('product_id', (int) $record->id)
                    ->where('shop_id', (int) $shop_id)
                    ->exists();

                if ($already_bound) {
                    $summary['skipped_already_bound']++;

                    continue;
                }

                ProcessProductShopBindingJob::dispatch(
                    (int)$record->id,
                    (int)$shop_id,
                    $product_import_batch_id,
                    $source_payload,
                    auth()->id()
                );

                $summary['jobs_queued']++;
            }
        }

        return $summary;
    }

    /**
     * @param list<int> $shop_ids
     *
     * @return array<string, int>
     */
    private static function queueExportForSelectedProducts(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        foreach ($records as $record) {
            if (!$record instanceof Product) {
                continue;
            }

            $summary['products_total']++;

            $source_item = ProductImportItem::query()
                ->where('product_id', (int)$record->id)
                ->orderByDesc('id')
                ->first();

            $source_batch_id = (int) ($source_item?->product_import_batch_id ?? 0);

            foreach ($shop_ids as $shop_id) {
                try {
                    $product_shop = ProductShop::query()
                        ->where('product_id', (int) $record->id)
                        ->where('shop_id', (int) $shop_id)
                        ->orderByDesc('id')
                        ->first();

                    if (! $product_shop instanceof ProductShop) {
                        self::markExportAsFailedForNotBoundShop(
                            $source_batch_id,
                            (int) $record->id,
                            (int) $shop_id
                        );
                        $summary['skipped_not_bound']++;

                        continue;
                    }

                    $target_product_id = (int) ($product_shop->product_id ?? 0);
                    if ($target_product_id <= 0) {
                        self::markExportAsFailedForNotBoundShop(
                            $source_batch_id,
                            (int) $record->id,
                            (int) $shop_id
                        );
                        $summary['skipped_not_bound']++;

                        continue;
                    }

                    $batch_id = self::resolveBatchIdForProductShop($target_product_id, (int)$shop_id, $source_batch_id);
                    if ($batch_id <= 0) {
                        $summary['errors']++;

                        continue;
                    }

                    $existing_export_item = ProductExportItem::query()
                        ->forBatchProductShop($batch_id, $target_product_id, (int)$shop_id)
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
                        'product_import_batch_id' => $batch_id,
                        'product_id'              => $target_product_id,
                        'payload'                 => [
                            'shop_id'              => (int)$shop_id,
                            'requested_product_id' => (int)$record->id,
                            'target_product_id'    => $target_product_id,
                            'requested_by_user_id' => auth()->id(),
                        ],
                        'status'                  => ProductExportItemsStatusEnum::PROCESSING->value,
                        'error_message'           => null,
                        'processed_at'            => null,
                    ]);

                    ProcessProductExportItemJob::dispatch((int)$export_item->id);
                    $summary['exports_queued']++;

                    $batch = ProductImportBatch::query()->find($batch_id);
                    if ($batch instanceof ProductImportBatch) {
                        $batch->update([
                            'status'  => ProductImportBatchesStatusEnum::PROCESSING->value,
                            'options' => [
                                ...($batch->options ?? []),
                                'export_state'       => 'processing',
                                'export_started_at'  => now()->toDateTimeString(),
                                'export_finished_at' => null,
                            ],
                        ]);
                    }
                } catch (Throwable) {
                    $summary['errors']++;
                }
            }
        }

        return $summary;
    }

    private static function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id): void
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $resolved_batch_id = $batch_id;
        if ($resolved_batch_id <= 0) {
            $resolved_batch_id = (int) (ProductImportItem::query()
                ->where('product_id', $product_id)
                ->orderByDesc('id')
                ->value('product_import_batch_id') ?? 0);
        }

        if ($resolved_batch_id <= 0) {
            return;
        }

        $error_message = 'Product is not bound to selected shop. Export skipped.';

        $existing_export_item = ProductExportItem::query()
            ->forBatchProductShop($resolved_batch_id, $product_id, $shop_id)
            ->orderByDesc('id')
            ->first();

        if ($existing_export_item instanceof ProductExportItem) {
            $existing_export_item->update([
                'status' => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at' => now(),
                'payload' => [
                    ...(is_array($existing_export_item->payload) ? $existing_export_item->payload : []),
                    'shop_id' => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id' => null,
                    'failure_reason' => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
            ]);
        } else {
            ProductExportItem::query()->create([
                'product_import_batch_id' => $resolved_batch_id,
                'product_id' => $product_id,
                'payload' => [
                    'shop_id' => $shop_id,
                    'requested_product_id' => $product_id,
                    'target_product_id' => null,
                    'failure_reason' => 'not_bound_to_shop',
                    'requested_by_user_id' => auth()->id(),
                ],
                'status' => ProductExportItemsStatusEnum::FAILED->value,
                'error_message' => $error_message,
                'processed_at' => now(),
            ]);
        }

        Log::channel('stack')->warning('Products table export skipped: product is not bound to selected shop', [
            'batch_id' => $resolved_batch_id,
            'product_id' => $product_id,
            'shop_id' => $shop_id,
            'requested_by_user_id' => auth()->id(),
        ]);
    }

    private static function resolveBatchIdForProductShop(int $product_id, int $shop_id, int $fallback_batch_id = 0): int
    {
        $batch_id = (int)(ProductShop::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->value('product_import_batch_id') ?? 0);

        if ($batch_id > 0) {
            return $batch_id;
        }

        if ($fallback_batch_id > 0) {
            return $fallback_batch_id;
        }

        return (int)(ProductImportItem::query()
            ->where('product_id', $product_id)
            ->orderByDesc('id')
            ->value('product_import_batch_id') ?? 0);
    }
}
