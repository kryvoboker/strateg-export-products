<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\RelationManagers;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Jobs\ProcessProductExportItemJob;
use App\Jobs\ProcessProductRestoreBatchJob;
use App\Jobs\ProcessProductShopBindingJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Attributes\AttributeShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Categories\CategoryShop;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Services\Products\ProductEditPersistenceService;
use App\Services\Products\ProductResourceOptionsService;
use App\Supports\Services\Products\ProductBackupRestoreService;
use App\Supports\Services\Products\ProductDeleteQueueService;
use App\Supports\Services\Products\ProductShopBindingService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductImportItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin/product_imports/batches.titles.items');
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
                    ->where('product_import_batch_id', (int) $this->getOwnerRecord()->id)
            )
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.item_status'))
                    ->formatStateUsing(fn (string $state): string => __('admin/product_imports/batches.item_statuses.'.$state))
                    ->badge()
                    ->colors([
                        'gray'    => fn (string $state): bool => $state === ProductImportItemsStatusEnum::NEW->value,
                        'warning' => fn (string $state): bool => $state === ProductImportItemsStatusEnum::PROCESSING->value,
                        'success' => fn (string $state): bool => in_array($state, [
                            ProductImportItemsStatusEnum::NORMALIZED->value,
                            ProductImportItemsStatusEnum::SUCCESSED->value,
                        ], true),
                        'danger' => fn (string $state): bool => $state === ProductImportItemsStatusEnum::FAILED->value,
                    ])
                    ->sortable(),
                TextColumn::make('product_id')
                    ->label(__('admin/product_imports/batches.columns.product_id'))
                    ->sortable(),
                TextColumn::make('product.product_name')
                    ->label('Назва товару')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->product_name ?? '#'.(int) ($record->product_id ?? 0)))
                    ->wrap(),
                TextColumn::make('product.model')
                    ->label('Model')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->model ?? ''))
                    ->toggleable(),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->sku ?? '')),
                TextColumn::make('product.ean')
                    ->label('EAN')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->ean ?? ''))
                    ->toggleable(),
                TextColumn::make('manufacturer_name')
                    ->label('Manufacturer')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->productToManufacturerBrand?->manufacturer?->manufacturer_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('brand_name')
                    ->label('Brand')
                    ->state(static fn (ProductImportItem $record): string => (string) ($record->product?->productToManufacturerBrand?->brand?->brand_name ?? ''))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('external_product_id')
                    ->label('External product id')
                    ->state(static function (ProductImportItem $record): string {
                        $product_id = (int) ($record->product_id ?? 0);
                        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

                        if ($product_id <= 0 || $batch_id <= 0) {
                            return '';
                        }

                        return ProductShop::query()
                            ->where('product_id', $product_id)
                            ->where('product_import_batch_id', $batch_id)
                            ->whereNotNull('external_product_id')
                            ->pluck('external_product_id')
                            ->map(static fn ($external_product_id): string => (string) $external_product_id)
                            ->filter(static fn (string $external_product_id): bool => $external_product_id !== '')
                            ->unique()
                            ->values()
                            ->implode(', ');
                    })
                    ->toggleable(),
                TextColumn::make('export_status')
                    ->label(__('admin/product_imports/batches.columns.export_status'))
                    ->state(fn (ProductImportItem $record): string => $this->resolveExportStatus($record))
                    ->badge()
                    ->colors([
                        'gray'    => fn (string $state): bool => $state === ProductImportItemsStatusEnum::NOT_QUEUED->value,
                        'warning' => fn (string $state): bool => $state === ProductExportItemsStatusEnum::PROCESSING->value,
                        'success' => fn (string $state): bool => $state === ProductExportItemsStatusEnum::EXPORTED->value,
                        'danger'  => fn (string $state): bool => in_array($state, [
                            ProductExportItemsStatusEnum::FAILED->value,
                            ProductExportItemsStatusEnum::PARTIAL_FAILED->value,
                        ], true),
                    ])
                    ->formatStateUsing(fn (string $state): string => __('admin/product_imports/batches.export_item_statuses.'.$state)),
                TextColumn::make('bound_shops')
                    ->label(__('admin/product_imports/batches.columns.bound_shops'))
                    ->wrap()
                    ->getStateUsing(function (ProductImportItem $record): string {
                        $product_id = (int) ($record->product_id ?? 0);
                        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

                        if ($product_id <= 0 || $batch_id <= 0) {
                            return __('admin/product_imports/batches.messages.no_bound_shops');
                        }

                        $shop_names = ProductShop::query()
                            ->where('product_id', $product_id)
                            ->where('product_import_batch_id', $batch_id)
                            ->join('shops', 'shops.id', '=', 'product_shop.shop_id')
                            ->orderBy('shops.name')
                            ->pluck('shops.name')
                            ->unique()
                            ->values()
                            ->all();

                        if ($shop_names === []) {
                            return __('admin/product_imports/batches.messages.no_bound_shops');
                        }

                        return implode(', ', $shop_names);
                    }),
                TextColumn::make('payload.source_meta.row_number')
                    ->label(__('admin/product_imports/batches.columns.source_row'))
                    ->getStateUsing(fn (ProductImportItem $record): string => (string) ($record->getSourceRowNumber() ?? __('admin/product_imports/batches.columns.empty_value')))
                    ->sortable(),
                TextColumn::make('payload.source_meta.path')
                    ->label(__('admin/product_imports/batches.columns.source_file'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap()
                    ->getStateUsing(fn (ProductImportItem $record): string => $record->getSourceFilePath() ?? __('admin/product_imports/batches.columns.empty_value')),
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
                        ProductImportItemsStatusEnum::NEW->value        => __('admin/product_imports/batches.item_statuses.new'),
                        ProductImportItemsStatusEnum::PROCESSING->value => __('admin/product_imports/batches.item_statuses.processing'),
                        ProductImportItemsStatusEnum::NORMALIZED->value => __('admin/product_imports/batches.item_statuses.normalized'),
                        ProductImportItemsStatusEnum::SUCCESSED->value  => __('admin/product_imports/batches.item_statuses.successed'),
                        ProductImportItemsStatusEnum::FAILED->value     => __('admin/product_imports/batches.item_statuses.failed'),
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

                        return $query->whereExists(function ($exists_query) use ($shop_id): void {
                            $exists_query
                                ->selectRaw('1')
                                ->from('product_shop')
                                ->whereColumn('product_shop.product_id', 'product_import_items.product_id')
                                ->whereColumn('product_shop.product_import_batch_id', 'product_import_items.product_import_batch_id')
                                ->where('product_shop.shop_id', $shop_id);
                        });
                    }),
                TernaryFilter::make('error_message')
                    ->label(__('admin/product_imports/batches.filters.has_errors'))
                    ->nullable()
                    ->queries(
                        true : fn ($query) => $query->whereNotNull('error_message')->where('error_message', '!=', ''),
                        false: fn ($query) => $query->where(function ($inner_query): void {
                            $inner_query
                                ->whereNull('error_message')
                                ->orWhere('error_message', '');
                        }),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                Action::make('viewPayload')
                    ->label(__('admin/product_imports/batches.actions.view_payload'))
                    ->icon(Heroicon::Eye)
                    ->modalWidth('7xl')
                    ->modalHeading(__('admin/product_imports/batches.actions.view_payload'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('actions.close'))
                    ->modalDescription(function (ProductImportItem $record) {
                        $payload = $record->payload;

                        if (! is_array($payload) || $payload === []) {
                            return new HtmlString('<p>'.__('admin/product_imports/batches.messages.payload_is_empty').'</p>');
                        }

                        $formatted_json = json_encode(
                            Arr::sortRecursive($payload),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );

                        return new HtmlString(
                            '<div class="w-full rounded-lg bg-gray-50 dark:bg-gray-800 p-4">'
                            .'<pre class="w-full text-sm text-gray-800 dark:text-gray-200 overflow-x-auto whitespace-pre-wrap">'
                            .e($formatted_json)
                            .'</pre></div>'
                        );
                    })
                    ->action(static fn (): null => null),

                Action::make('editProduct')
                    ->label(__('admin/product_imports/batches.actions.edit_product'))
                    ->icon(Heroicon::PencilSquare)
                    ->visible(fn (ProductImportItem $record): bool => $record->product_id !== null)
                    ->disabled(fn (ProductImportItem $record): bool => ! Product::query()->whereKey($record->product_id)->exists())
                    ->modalWidth('7xl')
                    ->modalHeading(__('admin/product_imports/batches.product_edit.heading'))
                    ->fillForm(function (ProductImportItem $record): array {
                        $product_resource_options_service = app(ProductResourceOptionsService::class);

                        $product = Product::query()->with([
                            'descriptions',
                            'images',
                            'categories.descriptions',
                            'productToAttributes',
                            'productToManufacturerBrand',
                            'specials',
                            'discounts',
                        ])->find((int) $record->product_id);

                        if ($product === null) {
                            return [];
                        }

                        $current_shop_id = ProductShop::query()
                            ->where('product_id', $product->id)
                            ->orderBy('id')
                            ->value('shop_id');

                        $current_shop_language_id = null;
                        if ($current_shop_id !== null) {
                            $current_shop_language_id = ShopLanguage::query()
                                ->where('shop_id', (int) $current_shop_id)
                                ->where('is_active', true)
                                ->orderBy('id')
                                ->value('id');
                        }

                        /** @var Collection<int, SeoUrl> $seo_urls */
                        $seo_urls = SeoUrl::query()
                            ->where('seoable_type', Product::class)
                            ->where('seoable_id', $product->id)
                            ->orderBy('id')
                            ->get();

                        $descriptions_by_language = $product->descriptions
                            ->mapWithKeys(static fn (ProductDescription $description): array => [
                                (int) $description->shop_language_id => [
                                    'name'             => $description->name,
                                    'description'      => $description->description,
                                    'meta_title'       => $description->meta_title,
                                    'meta_description' => $description->meta_description,
                                    'meta_keywords'    => $description->meta_keywords,
                                ],
                            ])
                            ->toArray();

                        $attributes_by_language = $product->productToAttributes
                            ->groupBy(static fn (ProductToAttribute $attribute): int => (int) ($attribute->shop_language_id ?? 0))
                            ->map(static fn (Collection $rows): array => $rows
                                ->map(static fn (ProductToAttribute $attribute): array => [
                                    'attribute_id' => $attribute->attribute_id,
                                    'text'         => $attribute->text,
                                ])->values()->all())
                            ->toArray();

                        $attributes_selected_by_language = $product->productToAttributes
                            ->groupBy(static fn (ProductToAttribute $attribute): int => (int) ($attribute->shop_language_id ?? 0))
                            ->map(static fn (Collection $rows): array => $rows
                                ->pluck('attribute_id')
                                ->filter()
                                ->map(static fn ($attribute_id): int => (int) $attribute_id)
                                ->unique()
                                ->values()
                                ->all())
                            ->toArray();

                        $attributes_custom_by_language = $product->productToAttributes
                            ->groupBy(static fn (ProductToAttribute $attribute): int => (int) ($attribute->shop_language_id ?? 0))
                            ->map(function (Collection $rows, $shop_language_id) use ($product_resource_options_service): array {
                                $resolved_shop_language_id = is_numeric($shop_language_id) ? (int) $shop_language_id : 0;
                                $attribute_name_map = $product_resource_options_service->getAttributeLabelsByIds(
                                    $rows->pluck('attribute_id')->filter()->map(static fn ($attribute_id): int => (int) $attribute_id)->all(),
                                    $resolved_shop_language_id,
                                );

                                return $rows
                                ->map(static function (ProductToAttribute $attribute) use ($attribute_name_map): array {
                                    return [
                                        'attribute_name' => (string) ($attribute_name_map[(int) ($attribute->attribute_id ?? 0)] ?? ''),
                                        'text'           => $attribute->text,
                                    ];
                                })
                                ->values()
                                ->all();
                            })
                            ->toArray();

                        $seo_urls_by_language = [];
                        foreach ($seo_urls as $seo_url) {
                            $shop_language_id = (int) ($seo_url->shop_language_id ?? 0);
                            if ($shop_language_id <= 0) {
                                $shop_language_id = (int) ($current_shop_language_id ?? 0);
                            }

                            if ($shop_language_id <= 0) {
                                continue;
                            }

                            $seo_urls_by_language[$shop_language_id][] = [
                                'query_key'   => $seo_url->query_key,
                                'query_value' => $seo_url->query_value,
                                'keyword'     => $seo_url->keyword,
                                'sort_order'  => $seo_url->sort_order,
                            ];
                        }

                        return [
                            'bind_shop_id'          => $current_shop_id !== null ? (int) $current_shop_id : null,
                            'bind_shop_language_id' => $current_shop_language_id !== null ? (int) $current_shop_language_id : null,
                            'product_id'            => $product->id,
                            'model'                 => $product->model,
                            'sku'                   => $product->sku,
                            'ean'                   => $product->ean,
                            'quantity'              => $product->quantity,
                            'minimum'               => $product->minimum,
                            'image'                 => $product->image,
                            'price'                 => $product->price,
                            'manufacturer_id'       => (int) ($product->productToManufacturerBrand?->manufacturer_id ?? 0) ?: null,
                            'brand_id'              => (int) ($product->productToManufacturerBrand?->brand_id ?? 0) ?: null,
                            'is_active'             => (bool) $product->is_active,
                            'date_available'        => $product->date_available,
                            'date_added'            => $product->date_added,
                            'descriptions'          => $product->descriptions
                                ->map(static fn (ProductDescription $description): array => [
                                    'shop_language_id' => $description->shop_language_id,
                                    'name'             => $description->name,
                                    'description'      => $description->description,
                                    'meta_title'       => $description->meta_title,
                                    'meta_description' => $description->meta_description,
                                    'meta_keywords'    => $description->meta_keywords,
                                ])->values()->all(),
                            'descriptions_by_language' => $descriptions_by_language,
                            'images'                   => $product->images
                                ->map(static fn (ProductImage $image): array => [
                                    'image'      => $image->image,
                                    'sort_order' => $image->sort_order,
                                ])->values()->all(),
                            'category_source_scope'   => $current_shop_id !== null ? 'shop' : 'all',
                            'categories_existing_ids' => $product->categories
                                ->pluck('id')
                                ->filter()
                                ->map(static fn ($category_id): int => (int) $category_id)
                                ->unique()
                                ->values()
                                ->all(),
                            'categories_custom_paths' => '',
                            'categories'              => $product->categories
                                ->map(function (Category $category) use ($product_resource_options_service, $current_shop_language_id): array {
                                    $category_name_map = $product_resource_options_service->getCategoryLabelsByIds(
                                        [(int) $category->id],
                                        (int) ($current_shop_language_id ?? 0),
                                    );

                                    return [
                                        'category_id'   => $category->id,
                                        'category_name' => (string) ($category_name_map[(int) $category->id] ?? ('#'.$category->id)),
                                    ];
                                })->values()->all(),
                            'attributes' => $product->productToAttributes
                                ->map(static fn (ProductToAttribute $attribute): array => [
                                    'attribute_id'     => $attribute->attribute_id,
                                    'shop_language_id' => $attribute->shop_language_id,
                                    'text'             => $attribute->text,
                                ])->values()->all(),
                            'attribute_source_scope'          => $current_shop_id !== null ? 'shop' : 'all',
                            'attributes_selected_by_language' => $attributes_selected_by_language,
                            'attributes_custom_by_language'   => $attributes_custom_by_language,
                            'attributes_by_language'          => $attributes_by_language,
                            'seo_urls_by_language'            => $seo_urls_by_language,
                            'specials'                        => $product->specials
                                ->map(static fn (ProductSpecial $special): array => [
                                    'user_group_id' => $special->user_group_id,
                                    'price'         => $special->price,
                                    'priority'      => $special->priority,
                                    'date_start'    => $special->date_start,
                                    'date_end'      => $special->date_end,
                                ])->values()->all(),
                            'discounts' => $product->discounts
                                ->map(static fn (ProductDiscount $discount): array => [
                                    'user_group_id' => $discount->user_group_id,
                                    'quantity'      => $discount->quantity,
                                    'price'         => $discount->price,
                                    'priority'      => $discount->priority,
                                    'date_start'    => $discount->date_start,
                                    'date_end'      => $discount->date_end,
                                ])->values()->all(),
                        ];
                    })
                    ->schema([
                        Tabs::make('product_edit_tabs')
                            ->tabs([
                                Tab::make('main')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.main'))
                                    ->schema([
                                        Select::make('bind_shop_id')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.bind_shop_id'))
                                            ->options(fn (callable $get, ProductImportItem $record): array => $this->getAvailableShopOptionsForProduct(
                                                (int) ($record->product_id ?? 0),
                                                (int) ($get('bind_shop_id') ?? 0)
                                            ))
                                            ->live()
                                            ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                                $shop_id              = ($state ?? 0);
                                                $language_options     = $this->getShopLanguageOptions($shop_id);
                                                $selected_language_id = (int) ($get('bind_shop_language_id') ?? 0);

                                                if ($selected_language_id > 0 && array_key_exists($selected_language_id, $language_options)) {
                                                    return;
                                                }

                                                $set('bind_shop_language_id', array_key_first($language_options));
                                            })
                                            ->searchable()
                                            ->helperText(__('admin/product_imports/batches.product_edit.helpers.bind_shop_id')),
                                        Select::make('bind_shop_language_id')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.bind_shop_language_id'))
                                            ->options(fn (callable $get): array => $this->getShopLanguageOptions((int) ($get('bind_shop_id') ?? 0)))
                                            ->disabled(fn (callable $get): bool => (int) ($get('bind_shop_id') ?? 0) <= 0)
                                            ->searchable(),
                                        TextInput::make('product_id')->label(__('admin/product_imports/batches.product_edit.fields.product_id'))->disabled(),
                                        TextInput::make('model')->label(__('admin/product_imports/batches.product_edit.fields.model'))->maxLength(255),
                                        TextInput::make('sku')->label(__('admin/product_imports/batches.product_edit.fields.sku'))->maxLength(255),
                                        TextInput::make('ean')->label(__('admin/product_imports/batches.product_edit.fields.ean'))->maxLength(255),
                                        Select::make('manufacturer_id')
                                            ->label('Manufacturer')
                                            ->options(fn (callable $get): array => $this->getManufacturerOptionsByScope(
                                                (int) ($get('bind_shop_id') ?? 0),
                                                (int) ($get('bind_shop_language_id') ?? 0),
                                                [(int) ($get('manufacturer_id') ?? 0)],
                                            ))
                                            ->searchable()
                                            ->preload(),
                                        Select::make('brand_id')
                                            ->label('Brand')
                                            ->options(fn (callable $get): array => $this->getBrandOptionsByScope(
                                                (int) ($get('bind_shop_id') ?? 0),
                                                (int) ($get('bind_shop_language_id') ?? 0),
                                                [(int) ($get('brand_id') ?? 0)],
                                            ))
                                            ->searchable()
                                            ->preload(),
                                        TextInput::make('quantity')->label(__('admin/product_imports/batches.product_edit.fields.quantity'))->numeric(),
                                        TextInput::make('minimum')->label(__('admin/product_imports/batches.product_edit.fields.minimum'))->numeric(),
                                        TextInput::make('image')->label(__('admin/product_imports/batches.product_edit.fields.image'))->maxLength(3000),
                                        TextInput::make('price')->label(__('admin/product_imports/batches.product_edit.fields.price'))->numeric(),
                                        Toggle::make('is_active')->label(__('admin/product_imports/batches.product_edit.fields.is_active')),
                                        DateTimePicker::make('date_available')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_available')),
                                        DateTimePicker::make('date_added')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_added')),
                                    ])->columns(3),
                                Tab::make('descriptions')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.descriptions'))
                                    ->schema([
                                        Tabs::make('description_language_tabs')
                                            ->tabs(fn (callable $get): array => $this->getDescriptionLanguageTabs((int) ($get('bind_shop_id') ?? 0)))
                                            ->columnSpanFull(),
                                    ]),
                                Tab::make('images')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.images'))
                                    ->schema([
                                        Repeater::make('images')
                                            ->schema([
                                                TextInput::make('image')->label(__('admin/product_imports/batches.product_edit.fields.image_path'))->maxLength(3000),
                                                TextInput::make('sort_order')->label(__('admin/product_imports/batches.product_edit.fields.sort_order'))->numeric()->default(1),
                                            ])->columns(),
                                    ]),
                                Tab::make('categories')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.categories'))
                                    ->schema([
                                        Select::make('category_source_scope')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.source_scope'))
                                            ->options([
                                                'all'  => __('admin/product_imports/batches.product_edit.source_scopes.all'),
                                                'shop' => __('admin/product_imports/batches.product_edit.source_scopes.shop'),
                                            ])
                                            ->default('shop')
                                            ->native(false)
                                            ->live(),
                                        Select::make('categories_existing_ids')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.categories_existing_ids'))
                                            ->options(fn (callable $get): array => $this->getCategoryOptionsByScope(
                                                (string) ($get('category_source_scope') ?? 'all'),
                                                (int) ($get('bind_shop_id') ?? 0),
                                                (int) ($get('bind_shop_language_id') ?? 0),
                                                is_array($get('categories_existing_ids')) ? $get('categories_existing_ids') : [],
                                            ))
                                            ->multiple()
                                            ->searchable()
                                            ->preload()
                                            ->helperText(__('admin/product_imports/batches.product_edit.helpers.select_existing_categories')),
                                        Textarea::make('categories_custom_paths')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.categories_custom_paths'))
                                            ->rows(3)
                                            ->helperText(__('admin/product_imports/batches.product_edit.helpers.category_separator')),
                                    ]),
                                Tab::make('attributes')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.attributes'))
                                    ->schema([
                                        Select::make('attribute_source_scope')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.source_scope'))
                                            ->options([
                                                'all'  => __('admin/product_imports/batches.product_edit.source_scopes.all'),
                                                'shop' => __('admin/product_imports/batches.product_edit.source_scopes.shop'),
                                            ])
                                            ->default('shop')
                                            ->native(false)
                                            ->live(),
                                        Tabs::make('attribute_language_tabs')
                                            ->tabs(fn (callable $get): array => $this->getAttributeLanguageTabs(
                                                (int) ($get('bind_shop_id') ?? 0),
                                                (string) ($get('attribute_source_scope') ?? 'all'),
                                                $this->resolveAttributeLanguageIdsFromState($get),
                                            ))
                                            ->columnSpanFull(),
                                    ]),
                                Tab::make('seo')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.seo'))
                                    ->schema([
                                        Tabs::make('seo_language_tabs')
                                            ->tabs(function (callable $get): array {
                                                $seo_urls_by_language = $get('seo_urls_by_language');
                                                $seo_language_ids     = is_array($seo_urls_by_language)
                                                    ? array_values(array_unique(array_map(static fn ($shop_language_id): int => (int) $shop_language_id, array_keys($seo_urls_by_language))))
                                                    : [];

                                                return $this->getSeoUrlLanguageTabs((int) ($get('bind_shop_id') ?? 0), $seo_language_ids);
                                            })
                                            ->columnSpanFull(),
                                    ]),
                                Tab::make('pricing')
                                    ->label(__('admin/product_imports/batches.product_edit.tabs.pricing'))
                                    ->schema([
                                        Repeater::make('discounts')
                                            ->label(__('admin/product_imports/batches.product_edit.sections.discounts'))
                                            ->schema([
                                                TextInput::make('user_group_id')->label(__('admin/product_imports/batches.product_edit.fields.user_group_id'))->numeric()->default(1),
                                                TextInput::make('quantity')->label(__('admin/product_imports/batches.product_edit.fields.quantity'))->numeric()->default(1),
                                                TextInput::make('price')->label(__('admin/product_imports/batches.product_edit.fields.price'))->numeric()->default(0),
                                                TextInput::make('priority')->label(__('admin/product_imports/batches.product_edit.fields.priority'))->numeric()->default(1),
                                                DateTimePicker::make('date_start')
                                                    ->format(config('app.datetime_format'))
                                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_start')),
                                                DateTimePicker::make('date_end')
                                                    ->format(config('app.datetime_format'))
                                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_end')),
                                            ])->columns(3),
                                        Repeater::make('specials')
                                            ->label(__('admin/product_imports/batches.product_edit.sections.specials'))
                                            ->schema([
                                                TextInput::make('user_group_id')->label(__('admin/product_imports/batches.product_edit.fields.user_group_id'))->numeric()->default(1),
                                                TextInput::make('price')->label(__('admin/product_imports/batches.product_edit.fields.price'))->numeric()->default(0),
                                                TextInput::make('priority')->label(__('admin/product_imports/batches.product_edit.fields.priority'))->numeric()->default(1),
                                                DateTimePicker::make('date_start')
                                                    ->format(config('app.datetime_format'))
                                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_start')),
                                                DateTimePicker::make('date_end')
                                                    ->format(config('app.datetime_format'))
                                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_end')),
                                            ])->columns(3),
                                    ]),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->action(function (ProductImportItem $record, array $data, Action $action): void {
                        $this->saveEditedProduct($record, $data);

                        $action->halt();
                    }),
                Action::make('restoreProductFromBackup')
                    ->label(__('admin/product_imports/batches.actions.restore_product_from_backup'))
                    ->icon(Heroicon::ArrowUturnLeft)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin/product_imports/batches.actions.restore_product_from_backup'))
                    ->modalDescription(__('admin/product_imports/batches.messages.restore_product_confirmation'))
                    ->visible(static fn (ProductImportItem $record): bool => self::canShowRestoreFromBackupAction((int) ($record->product_id ?? 0)))
                    ->action(function (ProductImportItem $record): void {
                        $product_id = (int) ($record->product_id ?? 0);
                        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

                        if ($product_id <= 0) {
                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.restore_product_not_available'))
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $this->safeLogInfo('Restore from backup action triggered', [
                                'product_import_item_id'  => (int) $record->id,
                                'product_import_batch_id' => $batch_id,
                                'product_id'              => $product_id,
                                'user_id'                 => auth()->id(),
                                'restore_mode'            => 'sync_no_queue',
                                'line'                    => __LINE__,
                                'file'                    => __FILE__,
                            ]);

                            self::restoreProductFromLatestBackup($record);

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.restore_product_success'))
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Log::channel('stack')->error('Restore from backup action failed', [
                                'product_import_item_id'  => (int) $record->id,
                                'product_import_batch_id' => $batch_id,
                                'product_id'              => $product_id,
                                'user_id'                 => auth()->id(),
                                'error_msg'               => $exception->getMessage(),
                                'file'                    => $exception->getFile(),
                                'line'                    => $exception->getLine(),
                                'exception'               => $exception,
                                'restore_mode'            => 'sync_no_queue',
                            ]);

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.restore_product_failed'))
                                ->body(Str::limit(Str::trim($exception->getMessage()), 500))
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('exportProductToShops')
                    ->label(__('admin/product_imports/batches.actions.export_product_to_shops'))
                    ->icon(Heroicon::CloudArrowUp)
                    ->color('success')
                    ->visible(function (ProductImportItem $record): bool {
                        $export_status = $this->resolveExportStatus($record);

                        return (int) ($record->product_id ?? 0) > 0 &&
                            $this->hasBoundShopsForRecord($record) === true &&
                            $this->checkIsCanTryingExportProduct($record) === false &&
                            ProductExportItemsStatusEnum::tryFrom($export_status)?->value === null;
                    })
                    ->disabled(fn (ProductImportItem $record): bool => $record->status === ProductImportItemsStatusEnum::PROCESSING->value)
                    ->action(function (ProductImportItem $record): void {
                        $shop_ids = ProductShop::query()
                            ->where('product_import_batch_id', (int) ($record->product_import_batch_id ?? 0))
                            ->where('product_id', (int) ($record->product_id ?? 0))
                            ->pluck('shop_id')
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

                        $summary = $this->queueExportForSingleItem($record, $shop_ids);

                        Notification::make()
                            ->title(__('admin/product_imports/batches.messages.item_export_queued'))
                            ->body(__('admin/product_imports/batches.messages.item_export_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('exportRequiresBinding')
                    ->label(__('admin/product_imports/batches.actions.export_product_to_shops'))
                    ->icon(Heroicon::ExclamationTriangle)
                    ->color('warning')
                    ->visible(function (ProductImportItem $record): bool {
                        return (int) ($record->product_id ?? 0) > 0 && ! $this->hasBoundShopsForRecord($record);
                    })
                    ->action(function (): void {
                        Notification::make()
                            ->title(__('admin/product_imports/batches.messages.item_export_needs_binding'))
                            ->warning()
                            ->send();
                    }),
                Action::make('retryFailedProductExports')
                    ->label(__('admin/product_imports/batches.actions.retry_failed_exports'))
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->visible(function (ProductImportItem $record): bool {
                        return $this->checkIsCanTryingExportProduct($record) === true;
                    })
                    ->action(function (ProductImportItem $record): void {
                        $summary = $this->retryFailedExportsForSingleItem($record);

                        Notification::make()
                            ->title(__('admin/product_imports/batches.messages.item_retry_queued'))
                            ->body(__('admin/product_imports/batches.messages.item_retry_result', $summary))
                            ->success()
                            ->send();
                    }),
                Action::make('deleteProductFromShops')
                    ->label(__('admin/product_deletes/batches.actions.delete_product_from_shop'))
                    ->icon(Heroicon::Trash)
                    ->color('danger')
                    ->visible(fn (ProductImportItem $record): bool => $this->hasExternalProductIdForRecord($record))
                    ->schema([
                        Select::make('shop_ids')
                            ->label(__('admin/product_imports/batches.filters.shop'))
                            ->options(fn (ProductImportItem $record): array => $this->resolveDeleteShopOptionsForRecord($record))
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(function (ProductImportItem $record, array $data): void {
                        $product_id = (int) ($record->product_id ?? 0);
                        $shop_ids   = collect($data['shop_ids'] ?? [])
                            ->map(static fn ($shop_id): int => (int) $shop_id)
                            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                            ->unique()
                            ->values()
                            ->all();

                        if ($product_id <= 0 || $shop_ids === []) {
                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_bind_no_shops'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $summary = app(ProductDeleteQueueService::class)->queueForProductIdsAndShopIds(
                            [$product_id],
                            $shop_ids,
                            'product_import_items',
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
                    BulkAction::make('restoreProductsFromBackups')
                        ->label(__('admin/product_imports/batches.actions.restore_products_from_backups'))
                        ->icon(Heroicon::ArrowUturnLeft)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_imports/batches.actions.restore_products_from_backups'))
                        ->modalDescription(__('admin/product_imports/batches.messages.restore_products_bulk_confirmation'))
                        ->action(function ($records): void {
                            $record_ids = collect($records)
                                ->filter(static fn ($record): bool => $record instanceof ProductImportItem)
                                ->map(static fn (ProductImportItem $record): int => (int) $record->id)
                                ->filter(static fn (int $id): bool => $id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            if ($record_ids === []) {
                                Notification::make()
                                    ->title(__('admin/product_imports/batches.messages.restore_products_bulk_no_items'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            ProcessProductRestoreBatchJob::dispatch($record_ids, auth()->id());

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.restore_products_bulk_queued'))
                                ->body(__('admin/product_imports/batches.messages.restore_products_bulk_result', [
                                    'items_selected' => count($record_ids),
                                ]))
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
                                ->filter(static fn ($record): bool => $record instanceof ProductImportItem)
                                ->map(static fn (ProductImportItem $record): int => (int) ($record->product_id ?? 0))
                                ->filter(static fn (int $product_id): bool => $product_id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            if ($shop_ids === [] || $product_ids === []) {
                                Notification::make()
                                    ->title(__('admin/product_imports/batches.messages.bulk_bind_no_shops'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $summary = app(ProductDeleteQueueService::class)->queueForProductIdsAndShopIds(
                                $product_ids,
                                $shop_ids,
                                'product_import_items',
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
                        ->action(function ($records, array $data): void {
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

                            $records_collection = collect($records);
                            $summary            = [
                                'items_selected'                => $records_collection->count(),
                                'items_skipped_processing'      => 0,
                                'items_skipped_without_product' => 0,
                                'products_total'                => 0,
                                'jobs_queued'                   => 0,
                                'items_skipped_already_bound'   => 0,
                                'products_skipped'              => 0,
                            ];

                            $processed_keys = [];

                            foreach ($records_collection as $record) {
                                if (! $record instanceof ProductImportItem) {
                                    continue;
                                }

                                if ($record->status === ProductImportItemsStatusEnum::PROCESSING->value) {
                                    $summary['items_skipped_processing']++;

                                    continue;
                                }

                                $product_id = (int) ($record->product_id ?? 0);
                                if ($product_id <= 0) {
                                    $summary['items_skipped_without_product']++;

                                    continue;
                                }

                                $batch_id = (int) ($record->product_import_batch_id ?? 0);
                                if ($batch_id <= 0) {
                                    $summary['products_skipped']++;

                                    continue;
                                }

                                $record_key = $batch_id.':'.$product_id;
                                if (in_array($record_key, $processed_keys, true)) {
                                    continue;
                                }

                                $processed_keys[] = $record_key;
                                $summary['products_total']++;

                                $source_payload = is_array($record->payload) ? $record->payload : [];
                                foreach ($shop_ids as $shop_id) {
                                    $already_bound = ProductShop::query()
                                        ->where('product_id', $product_id)
                                        ->where('shop_id', (int) $shop_id)
                                        ->exists();

                                    if ($already_bound) {
                                        $summary['items_skipped_already_bound']++;

                                        continue;
                                    }

                                    ProcessProductShopBindingJob::dispatch(
                                        $product_id,
                                        $shop_ids,
                                        $batch_id,
                                        $source_payload,
                                        auth()->id()
                                    );

                                    $summary['jobs_queued']++;
                                    break;
                                }
                            }

                            if ($summary['products_total'] <= 0) {
                                Notification::make()
                                    ->title(__('admin/product_imports/batches.messages.bulk_bind_no_products'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_bind_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_bind_items_queued_result', [
                                    ...$summary,
                                ]))
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
                        ->action(function ($records, array $data): void {
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

                            $summary = $this->queueExportForSelectedItems(collect($records), $shop_ids);

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_export_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_export_items_result', $summary))
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('retryFailedExports')
                        ->label(__('admin/product_imports/batches.actions.retry_failed_exports'))
                        ->icon(Heroicon::ArrowPath)
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->modalHeading(__('admin/product_imports/batches.actions.retry_failed_exports'))
                        ->action(function ($records): void {
                            $summary = $this->retryFailedExportsForSelectedItems(collect($records));

                            Notification::make()
                                ->title(__('admin/product_imports/batches.messages.bulk_retry_queued'))
                                ->body(__('admin/product_imports/batches.messages.bulk_retry_result', $summary))
                                ->success()
                                ->send();
                        }),
                ])
                    ->dropdownWidth(Width::Large),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function applyBatchSearchFieldsQuery(Builder $query, array $data, int $batch_id): Builder
    {
        if ($batch_id > 0) {
            $query->where('product_import_batch_id', $batch_id);
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
                    ->whereColumn('product_shop.product_id', 'product_import_items.product_id')
                    ->whereColumn('product_shop.product_import_batch_id', 'product_import_items.product_import_batch_id');

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

    public static function canShowRestoreFromBackupAction(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        return app(ProductBackupRestoreService::class)
            ->hasValidLatestLocalSnapshotForProduct($product_id);
    }

    public static function restoreProductFromLatestBackup(ProductImportItem $record): ProductBackups
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            throw new RuntimeException('Product id is required for restore');
        }

        if (! self::canShowRestoreFromBackupAction($product_id)) {
            throw new RuntimeException('Valid local product backup not found');
        }

        return app(ProductBackupRestoreService::class)
            ->restoreLatestSnapshotForProduct($product_id);
    }

    private function checkIsCanTryingExportProduct(ProductImportItem $record): bool
    {
        $product_id = (int) ($record->product_id ?? 0);

        if ($product_id <= 0) {
            return false;
        }

        return ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, (int) $record->product_import_batch_id)
            ->where('product_id', $product_id)
            ->where(function (Builder $query) {
                $query->where('status', ProductExportItemsStatusEnum::FAILED->value)
                    ->orWhere('status', ProductExportItemsStatusEnum::PARTIAL_FAILED->value);
            })
            ->exists();
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function queueExportForSingleItem(ProductImportItem $record, array $shop_ids): array
    {
        $summary = [
            'products_total'             => 0,
            'exports_queued'             => 0,
            'already_failed'             => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound'          => 0,
            'errors'                     => 0,
        ];

        $requested_product_id = (int) ($record->product_id ?? 0);
        $batch_id             = (int) ($record->product_import_batch_id ?? 0);

        if ($requested_product_id <= 0 || $batch_id <= 0) {
            return $summary;
        }

        $summary['products_total'] = 1;

        foreach ($shop_ids as $shop_id) {
            try {
                $product_id = $this->resolveExportProductIdForShop($record, (int) $shop_id);
                if ($product_id <= 0) {
                    $this->markExportAsFailedForNotBoundShop($batch_id, $requested_product_id, (int) $shop_id);
                    $summary['skipped_not_bound']++;

                    continue;
                }

                $product_shop = ProductShop::query()
                    ->where('product_id', $product_id)
                    ->where('shop_id', $shop_id)
                    ->where('product_import_batch_id', $batch_id)
                    ->first();

                if (! $product_shop instanceof ProductShop) {
                    $product_shop = ProductShop::query()
                        ->where('product_id', $product_id)
                        ->where('shop_id', $shop_id)
                        ->orderByDesc('id')
                        ->first();
                }

                if (! $product_shop instanceof ProductShop) {
                    $this->markExportAsFailedForNotBoundShop($batch_id, $requested_product_id, (int) $shop_id);
                    $summary['skipped_not_bound']++;

                    continue;
                }

                $resolved_batch_id = (int) ($product_shop->product_import_batch_id ?? 0);
                if ($resolved_batch_id <= 0) {
                    $resolved_batch_id = $batch_id;
                }

                $existing_export_item = ProductExportItem::query()
                    ->forBatchProductShop($resolved_batch_id, $product_id, $shop_id)
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
                    'product_id'     => $product_id,
                    'payload'        => [
                        'shop_id'              => $shop_id,
                        'requested_product_id' => $requested_product_id,
                        'target_product_id'    => $product_id,
                        'requested_by_user_id' => auth()->id(),
                    ],
                    'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                    'error_message' => null,
                    'processed_at'  => null,
                ]);

                ProcessProductExportItemJob::dispatch((int) $export_item->id);
                $summary['exports_queued']++;
            } catch (Throwable) {
                $summary['errors']++;
            }
        }

        if ($summary['exports_queued'] > 0) {
            $this->markBatchAsExporting($batch_id);
        }

        return $summary;
    }

    private function markExportAsFailedForNotBoundShop(int $batch_id, int $product_id, int $shop_id): void
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

        Log::channel('stack')->warning('Product export skipped: product is not bound to selected shop', [
            'batch_id'             => $batch_id,
            'product_id'           => $product_id,
            'shop_id'              => $shop_id,
            'requested_by_user_id' => auth()->id(),
        ]);
    }

    /**
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    private function queueExportForSelectedItems(Collection $records, array $shop_ids): array
    {
        $summary = [
            'items_selected'                => $records->count(),
            'items_skipped_processing'      => 0,
            'items_skipped_without_product' => 0,
            'products_total'                => 0,
            'exports_queued'                => 0,
            'already_failed'                => 0,
            'already_queued_or_exported'    => 0,
            'skipped_not_bound'             => 0,
            'errors'                        => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductImportItem) {
                continue;
            }

            if ($record->status === ProductImportItemsStatusEnum::PROCESSING->value) {
                $summary['items_skipped_processing']++;

                continue;
            }

            $product_id = (int) ($record->product_id ?? 0);
            if ($product_id <= 0) {
                $summary['items_skipped_without_product']++;

                continue;
            }

            $item_summary = $this->queueExportForSingleItem($record, $shop_ids);

            $summary['products_total'] += ($item_summary['products_total'] ?? 0);
            $summary['exports_queued'] += ($item_summary['exports_queued'] ?? 0);
            $summary['already_failed'] += ($item_summary['already_failed'] ?? 0);
            $summary['already_queued_or_exported'] += ($item_summary['already_queued_or_exported'] ?? 0);
            $summary['skipped_not_bound'] += ($item_summary['skipped_not_bound'] ?? 0);
            $summary['errors'] += $item_summary['errors'] ?? 0;
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function retryFailedExportsForSelectedItems(Collection $records): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
        ];

        foreach ($records as $record) {
            if (! $record instanceof ProductImportItem) {
                continue;
            }

            $item_summary = $this->retryFailedExportsForSingleItem($record);
            $summary['failed_found'] += ($item_summary['failed_found'] ?? 0);
            $summary['queued'] += $item_summary['queued'] ?? 0;
        }

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    private function retryFailedExportsForSingleItem(ProductImportItem $record): array
    {
        $summary = [
            'failed_found' => 0,
            'queued'       => 0,
        ];

        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

        if ($product_id <= 0 || $batch_id <= 0) {
            return $summary;
        }

        $failed_export_items = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('status', ProductExportItemsStatusEnum::FAILED->value)
            ->orderBy('id')
            ->get();

        $summary['failed_found'] = $failed_export_items->count();

        foreach ($failed_export_items as $failed_export_item) {
            $failed_export_item->update([
                'status'        => ProductExportItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at'  => null,
            ]);

            ProcessProductExportItemJob::dispatch((int) $failed_export_item->id);
            $summary['queued']++;
        }

        if ($summary['queued'] > 0) {
            $this->markBatchAsExporting($batch_id);
        }

        return $summary;
    }

    private function resolveExportStatus(ProductImportItem $record): string
    {
        $product_id = (int) ($record->product_id ?? 0);
        $batch_id   = (int) ($record->product_import_batch_id ?? 0);

        if ($product_id <= 0 || $batch_id <= 0) {
            return ProductImportItemsStatusEnum::NOT_QUEUED->value;
        }

        $total_export_items = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->count();

        if ($total_export_items <= 0) {
            return ProductImportItemsStatusEnum::NOT_QUEUED->value;
        }

        $processing_count = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('status', ProductExportItemsStatusEnum::PROCESSING->value)
            ->count();

        if ($processing_count > 0) {
            return ProductExportItemsStatusEnum::PROCESSING->value;
        }

        $failed_count = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('status', ProductExportItemsStatusEnum::FAILED->value)
            ->count();

        $exported_count = ProductExportItem::query()
            ->forBatchable(ProductImportBatch::class, $batch_id)
            ->where('product_id', $product_id)
            ->where('status', ProductExportItemsStatusEnum::EXPORTED->value)
            ->count();

        if ($failed_count > 0 && $exported_count > 0) {
            return ProductExportItemsStatusEnum::PARTIAL_FAILED->value;
        }

        if ($failed_count > 0) {
            return ProductExportItemsStatusEnum::FAILED->value;
        }

        if ($exported_count > 0) {
            return ProductExportItemsStatusEnum::EXPORTED->value;
        }

        return ProductImportItemsStatusEnum::NOT_QUEUED->value;
    }

    private function markBatchAsExporting(int $batch_id): void
    {
        if ($batch_id <= 0) {
            return;
        }

        $batch = ProductImportBatch::query()->find($batch_id);
        if ($batch === null) {
            return;
        }

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

    private function hasBoundShopsForProduct(int $product_id, int $batch_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        $query = ProductShop::query()
            ->where('product_id', $product_id);

        if ($batch_id > 0) {
            $query->where(function ($inner_query) use ($batch_id): void {
                $inner_query
                    ->where('product_import_batch_id', $batch_id)
                    ->orWhereNotNull('product_import_batch_id');
            });
        }

        return $query->exists();
    }

    private function hasBoundShopsForRecord(ProductImportItem $record): bool
    {
        return $this->getBoundShopIdsForRecord($record) !== [];
    }

    /**
     * @return array<int, string>
     */
    private function getBoundShopOptionsForProduct(int $product_id, int $batch_id): array
    {
        if ($product_id <= 0) {
            return [];
        }

        $shop_ids_for_batch = ProductShop::query()
            ->where('product_id', $product_id)
            ->when(
                $batch_id > 0,
                static fn ($query) => $query->where('product_import_batch_id', $batch_id)
            )
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $shop_ids = $shop_ids_for_batch;
        if ($shop_ids === []) {
            $shop_ids = ProductShop::query()
                ->where('product_id', $product_id)
                ->pluck('shop_id')
                ->map(static fn ($shop_id): int => (int) $shop_id)
                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($shop_ids === []) {
            return [];
        }

        return Shop::query()
            ->whereIn('id', $shop_ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    private function getBoundShopOptionsForRecord(ProductImportItem $record): array
    {
        $shop_ids = $this->getBoundShopIdsForRecord($record);
        if ($shop_ids === []) {
            return [];
        }

        return Shop::query()
            ->whereIn('id', $shop_ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return list<int>
     */
    private function getBoundShopIdsForRecord(ProductImportItem $record): array
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

    private function resolveExportProductIdForShop(ProductImportItem $record, int $shop_id): int
    {
        if ($shop_id <= 0) {
            return 0;
        }

        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return 0;
        }

        $product_shop = ProductShop::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->orderByDesc('id')
            ->first();

        return $product_shop instanceof ProductShop
            ? (int) $product_shop->product_id
            : 0;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    private function saveEditedProduct(ProductImportItem $record, array $data): void
    {
        $source_product_id = (int) ($record->product_id ?? 0);
        $source_product    = Product::query()->find($source_product_id);

        if ($source_product === null) {
            return;
        }

        $bind_shop_id          = (int) Arr::get($data, 'bind_shop_id', 0);
        $bind_shop_language_id = (int) Arr::get($data, 'bind_shop_language_id', 0);
        $data                  = $this->sanitizeAttributesSelectionBeforeSave($data, $source_product_id);
        $target_product        = $source_product;

        DB::transaction(function () use ($record, $source_product, $bind_shop_id, $bind_shop_language_id, $data, &$target_product): void {
            $batch_id = (int) ($record->product_import_batch_id ?? 0);

            if ($bind_shop_id > 0) {
                $target_product = $this->resolveTargetProductForShopBinding($source_product, $bind_shop_id, $batch_id);
            }

            try {
                $this->persistEditedProductData($target_product, $data, $bind_shop_language_id);
            } catch (Exception $e) {
                Notification::make()
                    ->title(__('admin/product_imports/batches.errors.update_product'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                throw $e;
            }

            if ($bind_shop_id > 0) {
                $shop_name = (string) (Shop::query()->whereKey($bind_shop_id)->value('name') ?? $bind_shop_id);
                ProductShop::query()->updateOrCreate([
                    'product_id' => $target_product->id,
                    'shop_id'    => $bind_shop_id,
                ], [
                    'product_import_batch_id' => $batch_id > 0 ? $batch_id : (int) ($record->product_import_batch_id ?? 0),
                    'external_product_id'     => null,
                ]);

                $is_updated = $target_product->update([
                    'marked_to_shop' => $shop_name,
                ]);

                if ($is_updated === true) {
                    Notification::make()
                        ->title(__('admin/product_imports/batches.messages.product_update_success'))
                        ->success()
                        ->send();
                }

                $this->ensureShopLinksForProduct($target_product->id, $bind_shop_id);

                if ($batch_id > 0) {
                    ProductImportItem::ensureBatchProductItem(
                        $batch_id,
                        (int) $target_product->id,
                        (array) ($record->payload ?? []),
                        ProductImportItemsStatusEnum::SUCCESSED->value
                    );

                    $this->syncBatchTotalItems($batch_id);
                }
            }

            if ($target_product->id !== (int) $record->product_id) {
                $is_updated = $record->update([
                    'product_id' => $target_product->id,
                ]);

                if ($is_updated === true) {
                    Notification::make()
                        ->title(__('admin/product_imports/batches.messages.product_update_success'))
                        ->success()
                        ->send();
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $form_data
     * @return array<string, mixed>
     */
    private function sanitizeAttributesSelectionBeforeSave(array $form_data, int $product_id = 0): array
    {
        $selected_by_language = Arr::get($form_data, 'attributes_selected_by_language', []);
        $custom_by_language   = Arr::get($form_data, 'attributes_custom_by_language', []);

        if (! is_array($selected_by_language) || ! is_array($custom_by_language)) {
            return $form_data;
        }

        foreach ($selected_by_language as $shop_language_id => $selected_attribute_ids) {
            if (! is_array($selected_attribute_ids)) {
                continue;
            }

            $custom_rows = $custom_by_language[$shop_language_id] ?? null;
            if (! is_array($custom_rows)) {
                continue;
            }

            $has_custom_attribute_names = collect($custom_rows)
                ->filter(static fn ($row): bool => is_array($row))
                ->contains(static fn (array $row): bool => Str::trim((string) Arr::get($row, 'attribute_name', '')) !== '');

            if (! $has_custom_attribute_names) {
                continue;
            }

            Arr::set($form_data, 'attributes_selected_by_language.'.$shop_language_id, []);

            $this->safeLogInfo('[FIX] Cleared selected attributes because custom attribute names are present', [
                'product_id'       => $product_id > 0 ? $product_id : null,
                'shop_language_id' => (int) $shop_language_id,
                'selected_before'  => array_values(array_unique(array_map(static fn ($id): int => (int) $id, $selected_attribute_ids))),
                'line'             => __LINE__,
                'file'             => __FILE__,
            ]);
        }

        return $form_data;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function safeLogInfo(string $message, array $context = []): void
    {
        try {
            if (! app()->bound('log')) {
                return;
            }

            Log::channel('daily')->info($message, $context);
        } catch (Throwable) {
            // no-op for isolated unit test containers without logger binding
        }
    }

    /**
     * @throws Throwable
     */
    private function resolveTargetProductForShopBinding(Product $source_product, int $shop_id, int $batch_id): Product
    {
        /** @var ProductShopBindingService $binding_service */
        $binding_service = app(ProductShopBindingService::class);

        $result = $binding_service->bindProductToShopAndReturnTargetProduct(
            (int) $source_product->id,
            $shop_id,
            $batch_id,
        );

        $target_product_id = (int) ($result['product_id'] ?? 0);
        $target_product    = $target_product_id > 0 ? Product::query()->find($target_product_id) : null;

        return $target_product instanceof Product ? $target_product : $source_product;
    }

    private function ensureShopLinksForProduct(int $product_id, int $shop_id): void
    {
        $category_ids = CategoryProduct::query()
            ->where('product_id', $product_id)
            ->pluck('category_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($category_ids as $category_id) {
            CategoryShop::query()->firstOrCreate([
                'category_id' => $category_id,
                'shop_id'     => $shop_id,
            ], [
                'external_category_id' => null,
            ]);
        }

        $attribute_ids = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->pluck('attribute_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($attribute_ids as $attribute_id) {
            AttributeShop::query()->firstOrCreate([
                'attribute_id' => $attribute_id,
                'shop_id'      => $shop_id,
            ], [
                'external_attribute_id' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistEditedProductData(Product $product, array $data, int $bind_shop_language_id = 0): void
    {
        app(ProductEditPersistenceService::class)->persist(
            $product,
            $data,
            $bind_shop_language_id,
            [
                'allow_legacy_descriptions' => true,
                'allow_legacy_attributes'   => true,
                'allow_legacy_seo_urls'     => false,
            ],
        );
    }

    private function syncBatchTotalItems(int $batch_id): void
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductCategoriesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        CategoryProduct::query()->where('product_id', $product_id)->delete();

        $resolved_category_ids = [];
        $seen_category_ids     = [];

        foreach (Arr::get($data, 'categories_existing_ids', []) as $category_id) {
            $resolved_category_id = (int) $category_id;
            if ($resolved_category_id <= 0) {
                continue;
            }

            if (array_key_exists($resolved_category_id, $seen_category_ids)) {
                throw ValidationException::withMessages([
                    'categories_existing_ids' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
                ]);
            }

            $seen_category_ids[$resolved_category_id] = true;
            $resolved_category_ids[]                  = $resolved_category_id;
        }

        [$category_paths, $duplicate_category_paths] = $this->parseHierarchyPathsWithDuplicates(
            (string) Arr::get($data, 'categories_custom_paths', '')
        );

        if ($duplicate_category_paths !== []) {
            throw ValidationException::withMessages([
                'categories_custom_paths' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
            ]);
        }

        foreach ($category_paths as $category_path) {
            $category_id = $this->resolveOrCreateCategoryIdByPath($category_path, $default_shop_language_id);
            if ($category_id === null) {
                continue;
            }

            if (array_key_exists($category_id, $seen_category_ids)) {
                throw ValidationException::withMessages([
                    'categories_custom_paths' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
                ]);
            }

            $seen_category_ids[$category_id] = true;
            $resolved_category_ids[]         = $category_id;
        }

        foreach ($resolved_category_ids as $category_id) {
            CategoryProduct::query()->create([
                'product_id'  => $product_id,
                'category_id' => $category_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductAttributesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        ProductToAttribute::query()->where('product_id', $product_id)->delete();

        $attribute_rows_to_create = [];
        $seen_attribute_pairs     = [];

        $attributes_selected_by_language = Arr::get($data, 'attributes_selected_by_language', []);
        if (is_array($attributes_selected_by_language)) {
            foreach ($attributes_selected_by_language as $shop_language_id => $attribute_ids) {
                $resolved_shop_language_id = $this->resolveAttributeRowLanguageId($shop_language_id, $default_shop_language_id);

                if (! is_array($attribute_ids)) {
                    continue;
                }

                foreach ($attribute_ids as $attribute_id) {
                    $resolved_attribute_id = (int) $attribute_id;
                    if ($resolved_attribute_id <= 0) {
                        continue;
                    }

                    $pair_key = ($resolved_shop_language_id ?? 0).':'.$resolved_attribute_id;
                    if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                        throw ValidationException::withMessages([
                            "attributes_selected_by_language.$shop_language_id" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    $seen_attribute_pairs[$pair_key] = true;
                    $attribute_rows_to_create[]      = [
                        'attribute_id'     => $resolved_attribute_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'text'             => '',
                    ];
                }
            }
        }

        $attributes_custom_by_language = Arr::get($data, 'attributes_custom_by_language', []);
        if (is_array($attributes_custom_by_language)) {
            foreach ($attributes_custom_by_language as $shop_language_id => $attribute_rows) {
                $resolved_shop_language_id = $this->resolveAttributeRowLanguageId($shop_language_id, $default_shop_language_id);

                if (! is_array($attribute_rows)) {
                    continue;
                }

                foreach ($attribute_rows as $row_index => $attribute_row) {
                    if (! is_array($attribute_row)) {
                        continue;
                    }

                    $attribute_text                                = (string) Arr::get($attribute_row, 'text', '');
                    [$attribute_paths, $duplicate_attribute_paths] = $this->parseHierarchyPathsWithDuplicates(
                        (string) Arr::get($attribute_row, 'attribute_name', ''),
                        false
                    );

                    if ($duplicate_attribute_paths !== []) {
                        throw ValidationException::withMessages([
                            "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    foreach ($attribute_paths as $attribute_path) {
                        $attribute_id = $this->resolveOrCreateAttributeIdByPath($attribute_path, $resolved_shop_language_id);
                        $pair_key     = ($resolved_shop_language_id ?? 0).':'.$attribute_id;

                        if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                            throw ValidationException::withMessages([
                                "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                            ]);
                        }

                        $seen_attribute_pairs[$pair_key] = true;
                        $attribute_rows_to_create[]      = [
                            'attribute_id'     => $attribute_id,
                            'shop_language_id' => $resolved_shop_language_id,
                            'text'             => $attribute_text,
                        ];
                    }
                }
            }
        }

        $legacy_attributes_by_language = Arr::get($data, 'attributes_by_language', []);
        if (is_array($legacy_attributes_by_language)) {
            foreach ($legacy_attributes_by_language as $shop_language_id => $attribute_rows) {
                $resolved_shop_language_id = $this->resolveAttributeRowLanguageId($shop_language_id, $default_shop_language_id);

                if (! is_array($attribute_rows)) {
                    continue;
                }

                foreach ($attribute_rows as $attribute_row) {
                    if (! is_array($attribute_row)) {
                        continue;
                    }

                    $attribute_id = (int) Arr::get($attribute_row, 'attribute_id', 0);
                    if ($attribute_id <= 0) {
                        continue;
                    }

                    $pair_key = $resolved_shop_language_id.':'.$attribute_id;
                    if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                        continue;
                    }

                    $seen_attribute_pairs[$pair_key] = true;
                    $attribute_rows_to_create[]      = [
                        'attribute_id'     => $attribute_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'text'             => (string) Arr::get($attribute_row, 'text', ''),
                    ];
                }
            }
        }

        foreach ($attribute_rows_to_create as $attribute_row_to_create) {
            ProductToAttribute::query()->create([
                'product_id'       => $product_id,
                'attribute_id'     => (int) $attribute_row_to_create['attribute_id'],
                'shop_language_id' => $attribute_row_to_create['shop_language_id'] !== null
                    ? (int) $attribute_row_to_create['shop_language_id']
                    : null,
                'text'             => (string) $attribute_row_to_create['text'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductManufacturerBrandFromFormData(int $product_id, array $data): void
    {
        $manufacturer_id = (int) Arr::get($data, 'manufacturer_id', 0);
        $brand_id        = (int) Arr::get($data, 'brand_id', 0);

        if ($manufacturer_id <= 0 && $brand_id <= 0) {
            ProductToManufacturerBrand::query()
                ->where('product_id', $product_id)
                ->delete();

            return;
        }

        ProductToManufacturerBrand::query()->updateOrCreate(
            [
                'product_id' => $product_id,
            ],
            [
                'manufacturer_id' => $manufacturer_id > 0 ? $manufacturer_id : null,
                'brand_id'        => $brand_id > 0 ? $brand_id : null,
            ]
        );
    }

    /**
     * @return array{0: list<list<string>>, 1: list<string>}
     */
    private function parseHierarchyPathsWithDuplicates(string $raw_value, bool $allow_hierarchy = true): array
    {
        $normalized_input = Str::trim($raw_value);
        if ($normalized_input === '') {
            return [[], []];
        }

        $path_chunks     = preg_split('/\s*\|\s*/u', $normalized_input) ?: [];
        $paths           = [];
        $seen_paths      = [];
        $duplicate_paths = [];

        foreach ($path_chunks as $path_chunk) {
            $path_chunk = Str::trim((string) $path_chunk);
            if ($path_chunk === '') {
                continue;
            }

            $path_segments = $allow_hierarchy
                ? (preg_split('/\s*>\s*/u', $path_chunk) ?: [])
                : [$path_chunk];

            $path_segments = array_values(array_filter(
                array_map(static fn ($segment): string => Str::trim((string) $segment), $path_segments),
                static fn (string $segment): bool => $segment !== '',
            ));

            if ($path_segments === []) {
                continue;
            }

            $path_key = Str::lower($allow_hierarchy ? implode(' > ', $path_segments) : implode(' ', $path_segments));
            if (array_key_exists($path_key, $seen_paths)) {
                $duplicate_paths[] = $allow_hierarchy ? implode(' > ', $path_segments) : implode(' ', $path_segments);

                continue;
            }

            $seen_paths[$path_key] = true;
            $paths[]               = $path_segments;
        }

        return [$paths, array_values(array_unique($duplicate_paths))];
    }

    /**
     * @param  list<string>  $category_path
     */
    private function resolveOrCreateCategoryIdByPath(array $category_path, int $shop_language_id): ?int
    {
        $category_path = array_values(array_filter(
            array_map(static fn ($segment): string => Str::trim((string) $segment), $category_path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($category_path === []) {
            return null;
        }

        $parent_category_id = null;

        foreach ($category_path as $category_name) {
            $existing_category_id = $this->findCategoryIdByParentAndName($parent_category_id, $category_name, $shop_language_id);

            if ($existing_category_id === null) {
                $category = Category::query()->create([
                    'parent_id'  => $parent_category_id,
                    'sort_order' => 0,
                    'is_active'  => true,
                ]);

                $existing_category_id = (int) $category->id;
            }

            $this->ensureCategoryDescription($existing_category_id, $shop_language_id, $category_name);
            $parent_category_id = $existing_category_id;
        }

        return $parent_category_id;
    }

    private function findCategoryIdByParentAndName(?int $parent_category_id, string $category_name, int $shop_language_id): ?int
    {
        $normalized_category_name = Str::lower(Str::trim($category_name));
        if ($normalized_category_name === '') {
            return null;
        }

        $query = Category::query()
            ->select('categories.id')
            ->join('category_descriptions', 'category_descriptions.category_id', '=', 'categories.id')
            ->whereRaw('LOWER(category_descriptions.name) = ?', [$normalized_category_name])
            ->when(
                $parent_category_id === null,
                static fn ($builder) => $builder->whereNull('categories.parent_id'),
                static fn ($builder) => $builder->where('categories.parent_id', $parent_category_id),
            )
            ->orderByRaw(
                'CASE WHEN category_descriptions.shop_language_id = ? THEN 0 ELSE 1 END',
                [$shop_language_id]
            )
            ->orderBy('categories.id');

        $category_id = $query->value('categories.id');

        return $category_id !== null ? (int) $category_id : null;
    }

    private function ensureCategoryDescription(int $category_id, int $shop_language_id, string $category_name): void
    {
        $clean_category_name = Str::trim($category_name);
        if ($clean_category_name === '') {
            return;
        }

        CategoryDescription::query()->firstOrCreate(
            [
                'category_id'      => $category_id,
                'shop_language_id' => $shop_language_id,
            ],
            [
                'name'             => $clean_category_name,
                'description'      => null,
                'h1_title'         => $clean_category_name,
                'meta_title'       => $clean_category_name,
                'meta_description' => null,
                'meta_keywords'    => null,
            ]
        );
    }

    /**
     * @param  list<string>  $attribute_path
     */
    private function resolveOrCreateAttributeIdByPath(array $attribute_path, ?int $shop_language_id): int
    {
        $attribute_path = array_values(array_filter(
            array_map(static fn ($segment): string => Str::trim((string) $segment), $attribute_path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($attribute_path === []) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        $resolved_attribute_id = null;

        foreach ($attribute_path as $attribute_name) {
            $existing_attribute_query = AttributeDescription::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($attribute_name)])
                ->when(
                    $shop_language_id !== null,
                    static fn ($query) => $query->where('shop_language_id', $shop_language_id),
                    static fn ($query) => $query->whereNull('shop_language_id'),
                );

            $existing_attribute_id = $existing_attribute_query->value('attribute_id');

            if ($existing_attribute_id !== null) {
                $resolved_attribute_id = (int) $existing_attribute_id;

                continue;
            }

            $attribute = Attribute::query()->create([
                'sort_order' => 1,
            ]);

            $resolved_attribute_id = (int) $attribute->id;

            AttributeDescription::query()->firstOrCreate([
                'attribute_id'     => $resolved_attribute_id,
                'shop_language_id' => $shop_language_id,
            ], [
                'name' => $attribute_name,
            ]);
        }

        if ($resolved_attribute_id <= 0) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        return $resolved_attribute_id;
    }

    private function resolveAttributeRowLanguageId(int|string|null $shop_language_id, int $default_shop_language_id): ?int
    {
        $resolved_shop_language_id = is_numeric($shop_language_id) ? (int) $shop_language_id : 0;

        if ($resolved_shop_language_id > 0) {
            return $resolved_shop_language_id;
        }

        if ($default_shop_language_id > 0) {
            return $default_shop_language_id;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function getCategoryOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_category_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getCategoryOptionsByScope(
            $scope,
            $shop_id,
            $shop_language_id,
            $selected_category_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private function getAttributeOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_attribute_ids = []
    ): array {
        return app(ProductResourceOptionsService::class)->getAttributeOptionsByScope(
            $scope,
            $shop_id,
            $shop_language_id,
            $selected_attribute_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private function getManufacturerOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_manufacturer_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getManufacturerOptionsByScope(
            $shop_id,
            $shop_language_id,
            $selected_manufacturer_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private function getBrandOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_brand_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getBrandOptionsByScope(
            $shop_id,
            $shop_language_id,
            $selected_brand_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private function getAvailableShopOptionsForProduct(int $product_id, int $current_shop_id = 0): array
    {
        if ($product_id <= 0) {
            return [];
        }

        $already_bound_shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->all();

        $options = Shop::query()
            ->where('is_active', true)
            ->when($already_bound_shop_ids !== [], fn ($query) => $query->whereNotIn('id', $already_bound_shop_ids))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();

        if ($current_shop_id > 0 && ! array_key_exists($current_shop_id, $options)) {
            $current_shop_name = Shop::query()->whereKey($current_shop_id)->value('name');
            if (is_string($current_shop_name) && $current_shop_name !== '') {
                $options[$current_shop_id] = $current_shop_name;
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function getShopLanguageOptions(int $shop_id): array
    {
        return app(ProductResourceOptionsService::class)->getShopLanguageOptions($shop_id);
    }

    /**
     * @return array<Tab>
     */
    private function getDescriptionLanguageTabs(int $shop_id): array
    {
        $shop_languages = $this->getShopLanguages($shop_id);
        if ($shop_languages->isEmpty()) {
            return [
                Tab::make('empty_descriptions')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.descriptions'))
                    ->schema([
                        TextEntry::make('descriptions_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->state(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($shop_languages as $shop_language) {
            $language_id = (int) $shop_language->id;

            $tabs[] = Tab::make('description_language_'.$language_id)
                ->label((string) $shop_language->name)
                ->badge((string) $shop_language->code)
                ->schema([
                    TextInput::make("descriptions_by_language.$language_id.name")
                        ->label(__('admin/product_imports/batches.product_edit.fields.name'))
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$language_id.description")
                        ->label(__('admin/product_imports/batches.product_edit.fields.description'))
                        ->rows(3),
                    TextInput::make("descriptions_by_language.$language_id.meta_title")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_title'))
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$language_id.meta_description")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_description'))
                        ->rows(3)
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$language_id.meta_keywords")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_keywords'))
                        ->rows(3)
                        ->maxLength(255),
                ]);
        }

        return $tabs;
    }

    /**
     * @param  list<int>  $attribute_language_ids
     * @return array<Tab>
     */
    private function getAttributeLanguageTabs(int $shop_id, string $scope, array $attribute_language_ids = []): array
    {
        $attribute_tab_contexts = app(ProductResourceOptionsService::class)->getAttributeTabContexts(
            $shop_id,
            $attribute_language_ids,
        );

        if ($attribute_tab_contexts->isEmpty()) {
            return [
                Tab::make('empty_attributes')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.attributes'))
                    ->schema([
                        TextEntry::make('attributes_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->state(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($attribute_tab_contexts as $attribute_tab_context) {
            $language_id = (int) $attribute_tab_context['id'];

            $tab = Tab::make('attribute_language_'.$language_id)
                ->label((string) $attribute_tab_context['name'])
                ->schema([
                    Select::make("attributes_selected_by_language.$language_id")
                        ->label(__('admin/product_imports/batches.product_edit.fields.attributes_existing_ids'))
                        ->options(fn (callable $get): array => $this->getAttributeOptionsByScope(
                            $scope,
                            (int) ($get('bind_shop_id') ?? 0),
                            $language_id,
                            is_array($get("attributes_selected_by_language.$language_id")) ? $get("attributes_selected_by_language.$language_id") : [],
                        ))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText(__('admin/product_imports/batches.product_edit.helpers.select_existing_attributes')),
                    Repeater::make("attributes_custom_by_language.$language_id")
                        ->hiddenLabel()
                        ->addActionLabel(__('admin/product_imports/batches.product_edit.actions.add_attribute'))
                        ->schema([
                            TextInput::make('attribute_name')
                                ->label(__('admin/product_imports/batches.product_edit.fields.attribute_name'))
                                ->maxLength(255)
                                ->helperText(__('admin/product_imports/batches.product_edit.helpers.attribute_separator')),
                            TextInput::make('text')
                                ->label(__('admin/product_imports/batches.product_edit.fields.text'))
                                ->maxLength(3000),
                        ])->columns(),
                ]);

            if ($attribute_tab_context['code'] !== '') {
                $tab->badge((string) $attribute_tab_context['code']);
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * @return list<int>
     */
    private function resolveAttributeLanguageIdsFromState(callable $get): array
    {
        return collect([
            ...array_keys(is_array($get('attributes_selected_by_language')) ? $get('attributes_selected_by_language') : []),
            ...array_keys(is_array($get('attributes_custom_by_language')) ? $get('attributes_custom_by_language') : []),
            ...array_keys(is_array($get('attributes_by_language')) ? $get('attributes_by_language') : []),
        ])
            ->map(static fn ($shop_language_id): int => is_numeric($shop_language_id) ? (int) $shop_language_id : 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<Tab>
     */
    private function getSeoUrlLanguageTabs(int $shop_id, array $seo_language_ids = []): array
    {
        $shop_languages = $this->getShopLanguages($shop_id);
        if ($shop_languages->isEmpty() && $seo_language_ids !== []) {
            $normalized_language_ids = collect($seo_language_ids)
                ->map(static fn ($language_id): int => (int) $language_id)
                ->filter(static fn (int $language_id): bool => $language_id > 0)
                ->unique()
                ->values()
                ->all();

            if ($normalized_language_ids !== []) {
                $shop_languages = ShopLanguage::query()
                    ->whereIn('id', $normalized_language_ids)
                    ->orderBy('name')
                    ->get();
            }
        }

        if ($shop_languages->isEmpty()) {
            return [
                Tab::make('empty_seo_urls')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.seo'))
                    ->schema([
                        TextEntry::make('seo_urls_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->state(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($shop_languages as $shop_language) {
            $language_id = (int) $shop_language->id;

            $tabs[] = Tab::make('seo_urls_language_'.$language_id)
                ->label((string) $shop_language->name)
                ->badge((string) $shop_language->code)
                ->schema([
                    TextInput::make("seo_urls_by_language.$language_id.0.query_key")
                        ->label(__('admin/product_imports/batches.product_edit.fields.query_key'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$language_id.0.query_value")
                        ->label(__('admin/product_imports/batches.product_edit.fields.query_value'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$language_id.0.keyword")
                        ->label(__('admin/product_imports/batches.product_edit.fields.keyword'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$language_id.0.sort_order")
                        ->label(__('admin/product_imports/batches.product_edit.fields.sort_order'))
                        ->numeric()
                        ->default(1),
                ]);
        }

        return $tabs;
    }

    /**
     * @return Collection<int, ShopLanguage>
     */
    private function getShopLanguages(int $shop_id): Collection
    {
        return app(ProductResourceOptionsService::class)->getShopLanguages($shop_id);
    }

    private function hasExternalProductIdForRecord(ProductImportItem $record): bool
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return false;
        }

        return ProductShop::query()
            ->where('product_id', $product_id)
            ->whereNotNull('external_product_id')
            ->where('external_product_id', '>', 0)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function resolveDeleteShopOptionsForRecord(ProductImportItem $record): array
    {
        $product_id = (int) ($record->product_id ?? 0);
        if ($product_id <= 0) {
            return [];
        }

        return ProductShop::query()
            ->where('product_id', $product_id)
            ->whereNotNull('external_product_id')
            ->where('external_product_id', '>', 0)
            ->join('shops', 'shops.id', '=', 'product_shop.shop_id')
            ->orderBy('shops.name')
            ->pluck('shops.name', 'product_shop.shop_id')
            ->toArray();
    }

    private function getTypedOwnerRecord(): ProductImportBatch
    {
        /** @var ProductImportBatch $record */
        $record = $this->getOwnerRecord();

        return $record;
    }
}
