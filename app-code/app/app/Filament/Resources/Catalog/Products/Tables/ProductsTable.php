<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Tables;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
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
                'importItems'       => static fn($import_items_query) => $import_items_query
                    ->orderByDesc('id'),
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
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $name  = Str::lower(Str::trim((string)Arr::get($data, 'name', '')));
                        $sku   = Str::trim((string)Arr::get($data, 'sku', ''));
                        $model = Str::trim((string)Arr::get($data, 'model', ''));
                        $ean   = Str::trim((string)Arr::get($data, 'ean', ''));

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
                            $query->whereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', ['%' . Str::lower($sku) . '%']);
                        }

                        if ($model !== '') {
                            $query->whereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', ['%' . Str::lower($model) . '%']);
                        }

                        if ($ean !== '') {
                            $query->whereRaw('LOWER(COALESCE(ean, \'\')) LIKE ?', ['%' . Str::lower($ean) . '%']);
                        }

                        return $query;
                    }),

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
                            'importItems',
                            static fn(Builder $items_query): Builder => $items_query->whereIn('status', [
                                ProductImportItemsStatusEnum::NORMALIZED->value,
                                ProductImportItemsStatusEnum::SUCCESSED->value,
                            ])
                        ),
                        false: static fn(Builder $query): Builder => $query->whereDoesntHave(
                            'importItems',
                            static fn(Builder $items_query): Builder => $items_query->whereIn('status', [
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
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
        $status_values = collect($product->importItems)
            ->pluck('status')
            ->filter()
            ->all();

        if ($status_values !== []) {
            return collect($status_values)
                ->contains(static fn($status): bool => in_array((string)$status, [
                    ProductImportItemsStatusEnum::NORMALIZED->value,
                    ProductImportItemsStatusEnum::SUCCESSED->value,
                ], true));
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
    private static function bindSelectedProductsToShops(Collection $records, array $shop_ids): array
    {
        $summary = [
            'products_total' => 0,
            'shops_total'    => count($shop_ids),
            'jobs_queued'    => 0,
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
                                'export_started_at'  => get_now_date()->toDateTimeString(),
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
                'processed_at' => get_now_date(),
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
                'processed_at' => get_now_date(),
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
