<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Filament\Resources\Catalog\Products\ProductResource;
use App\Jobs\ProcessProductUpdateItemJob;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @var array<string, array{sheet:string, fields:list<string>}>
     */
    private const array API_UPDATE_FIELD_MAP = [
        'product' => [
            'sheet' => 'Product',
            'fields' => ['model', 'sku', 'ean', 'quantity', 'minimum', 'image', 'price', 'is_active', 'date_available', 'date_added'],
        ],
        'description' => [
            'sheet' => 'Description',
            'fields' => ['name', 'description', 'meta_title', 'meta_description', 'meta_keywords'],
        ],
        'image' => [
            'sheet' => 'Image',
            'fields' => ['image', 'sort_order'],
        ],
        'product_category' => [
            'sheet' => 'Product Category',
            'fields' => ['category_name'],
        ],
        'product_attribute' => [
            'sheet' => 'Product Attribute',
            'fields' => ['attribute_name', 'attribute_text'],
        ],
        'seo_url' => [
            'sheet' => 'Seo Url',
            'fields' => ['query_key', 'query_value', 'keyword', 'sort_order'],
        ],
        'special' => [
            'sheet' => 'Special',
            'fields' => ['user_group_id', 'price', 'priority', 'date_start', 'date_end'],
        ],
        'discount' => [
            'sheet' => 'Discount',
            'fields' => ['user_group_id', 'quantity', 'price', 'priority', 'date_start', 'date_end'],
        ],
    ];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('updateProductViaApi')
                ->label(__('admin/products/products.actions.update_product_via_api'))
                ->icon(Heroicon::ArrowPathRoundedSquare)
                ->color('success')
                ->requiresConfirmation()
                ->action('saveAndQueueProductUpdateViaApi'),
            ...parent::getFormActions(),
        ];
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function saveAndQueueProductUpdateViaApi(): void
    {
        try {
            $this->data = $this->sanitizeAttributesSelectionBeforeSave(is_array($this->data) ? $this->data : []);
            $this->save(false);
        } catch (Exception|Throwable $e) {
            Notification::make()
                ->title(__('admin/products/products.errors.product_save_before_update_by_api'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $this->record instanceof Product) {
            return;
        }

        $summary = $this->queueProductUpdateViaApi($this->record->refresh());

        if (($summary['updates_queued'] ?? 0) > 0) {
            Notification::make()
                ->title(__('admin/products/products.messages.item_update_queued'))
                ->body(__('admin/products/products.messages.item_update_result', $summary))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('admin/products/products.messages.item_update_not_queued'))
            ->body(__('admin/products/products.messages.item_update_result', $summary))
            ->warning()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $form_data
     * @return array<string, mixed>
     */
    private function sanitizeAttributesSelectionBeforeSave(array $form_data): array
    {
        $selected_by_language = Arr::get($form_data, 'attributes_selected_by_language', []);
        $custom_by_language = Arr::get($form_data, 'attributes_custom_by_language', []);

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

            Arr::set($form_data, 'attributes_selected_by_language.' . $shop_language_id, []);
        }

        return $form_data;
    }

    public function renderingHasRelationManagers(): void
    {
        $managers = $this->getRelationManagers();
        $active_relation_manager = (string) ($this->activeRelationManager ?? '');

        if (array_key_exists($active_relation_manager, $managers)) {
            $this->activeRelationManager = $active_relation_manager;

            return;
        }

        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            $this->activeRelationManager = $active_relation_manager;

            return;
        }

        $this->activeRelationManager = array_key_first($managers);

        if ($this->activeRelationManager === null) {
            $this->activeRelationManager = '';
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! $this->record instanceof Product) {
            return $data;
        }

        $product = Product::query()->with([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
        ])->find((int) $this->record->id);

        if (! $product instanceof Product) {
            return $data;
        }

        $current_shop_id = ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->orderBy('id')
            ->value('shop_id');
        $current_external_product_id = null;
        if ($current_shop_id !== null) {
            $current_external_product_id = ProductShop::query()
                ->where('product_id', (int) $product->id)
                ->where('shop_id', (int) $current_shop_id)
                ->orderByDesc('id')
                ->value('external_product_id');
        }

        $current_shop_language_id = null;
        if ($current_shop_id !== null) {
            $current_shop_language_id = ShopLanguage::query()
                ->where('shop_id', (int) $current_shop_id)
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');
        }

        $seo_urls = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', (int) $product->id)
            ->orderBy('id')
            ->get();

        $descriptions_by_language = $product->descriptions
            ->mapWithKeys(static fn (ProductDescription $description): array => [
                (int) $description->shop_language_id => [
                    'name' => $description->name,
                    'description' => $description->description,
                    'meta_title' => $description->meta_title,
                    'meta_description' => $description->meta_description,
                    'meta_keywords' => $description->meta_keywords,
                ],
            ])
            ->toArray();

        $attributes_by_language = $product->productToAttributes
            ->groupBy('shop_language_id')
            ->map(static fn ($rows): array => $rows
                ->map(static fn (ProductToAttribute $attribute): array => [
                    'attribute_id' => $attribute->attribute_id,
                    'text' => $attribute->text,
                ])->values()->all())
            ->toArray();

        $attributes_selected_by_language = $product->productToAttributes
            ->groupBy('shop_language_id')
            ->map(static fn ($rows): array => $rows
                ->pluck('attribute_id')
                ->filter()
                ->map(static fn ($attribute_id): int => (int) $attribute_id)
                ->unique()
                ->values()
                ->all())
            ->toArray();

        $attribute_name_map = AttributeDescription::query()
            ->whereIn('attribute_id', $product->productToAttributes->pluck('attribute_id')->filter()->all())
            ->whereIn('shop_language_id', $product->productToAttributes->pluck('shop_language_id')->filter()->all())
            ->get()
            ->mapWithKeys(static fn (AttributeDescription $description): array => [
                $description->attribute_id . ':' . $description->shop_language_id => (string) $description->name,
            ])
            ->toArray();

        $attributes_custom_by_language = $product->productToAttributes
            ->groupBy('shop_language_id')
            ->map(static fn ($rows) => $rows
                ->map(static function (ProductToAttribute $attribute) use ($attribute_name_map): array {
                    $attribute_key = $attribute->attribute_id . ':' . $attribute->shop_language_id;

                    return [
                        'attribute_name' => $attribute_name_map[$attribute_key] ?? '',
                        'text' => $attribute->text,
                    ];
                })
                ->values()
                ->all())
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

            if (! array_key_exists($shop_language_id, $seo_urls_by_language)) {
                $seo_urls_by_language[$shop_language_id] = [];
            }

            $seo_urls_by_language[$shop_language_id][] = [
                'query_key' => $seo_url->query_key,
                'query_value' => $seo_url->query_value,
                'keyword' => $seo_url->keyword,
                'sort_order' => $seo_url->sort_order,
            ];
        }

        return [
            ...$data,
            'bind_shop_id' => $current_shop_id !== null ? (int) $current_shop_id : null,
            'bind_shop_language_id' => $current_shop_language_id !== null ? (int) $current_shop_language_id : null,
            'product_id' => (int) $product->id,
            'model' => $product->model,
            'sku' => $product->sku,
            'ean' => $product->ean,
            'external_product_id' => is_numeric($current_external_product_id) ? (int) $current_external_product_id : null,
            'quantity' => $product->quantity,
            'minimum' => $product->minimum,
            'image' => $product->image,
            'price' => $product->price,
            'is_active' => (bool) $product->is_active,
            'date_available' => $product->date_available,
            'date_added' => $product->date_added,
            'descriptions' => $product->descriptions
                ->map(static fn (ProductDescription $description): array => [
                    'shop_language_id' => $description->shop_language_id,
                    'name' => $description->name,
                    'description' => $description->description,
                    'meta_title' => $description->meta_title,
                    'meta_description' => $description->meta_description,
                    'meta_keywords' => $description->meta_keywords,
                ])->values()->all(),
            'descriptions_by_language' => $descriptions_by_language,
            'images' => $product->images
                ->map(static fn (ProductImage $image): array => [
                    'image' => $image->image,
                    'sort_order' => $image->sort_order,
                ])->values()->all(),
            'category_source_scope' => $current_shop_id !== null ? 'shop' : 'all',
            'categories_existing_ids' => $product->categories
                ->pluck('id')
                ->filter()
                ->map(static fn ($category_id): int => (int) $category_id)
                ->unique()
                ->values()
                ->all(),
            'categories_custom_paths' => '',
            'attributes' => $product->productToAttributes
                ->map(static fn (ProductToAttribute $attribute): array => [
                    'attribute_id' => $attribute->attribute_id,
                    'shop_language_id' => $attribute->shop_language_id,
                    'text' => $attribute->text,
                ])->values()->all(),
            'attribute_source_scope' => $current_shop_id !== null ? 'shop' : 'all',
            'attributes_selected_by_language' => $attributes_selected_by_language,
            'attributes_custom_by_language' => $attributes_custom_by_language,
            'attributes_by_language' => $attributes_by_language,
            'seo_urls' => $seo_urls
                ->map(static fn (SeoUrl $seo_url): array => [
                    'query_key' => $seo_url->query_key,
                    'query_value' => $seo_url->query_value,
                    'keyword' => $seo_url->keyword,
                    'sort_order' => $seo_url->sort_order,
                ])->values()->all(),
            'seo_urls_by_language' => $seo_urls_by_language,
            'specials' => $product->specials
                ->map(static fn (ProductSpecial $special): array => [
                    'user_group_id' => $special->user_group_id,
                    'price' => $special->price,
                    'priority' => $special->priority,
                    'date_start' => $special->date_start,
                    'date_end' => $special->date_end,
                ])->values()->all(),
            'discounts' => $product->discounts
                ->map(static fn (ProductDiscount $discount): array => [
                    'user_group_id' => $discount->user_group_id,
                    'quantity' => $discount->quantity,
                    'price' => $discount->price,
                    'priority' => $discount->priority,
                    'date_start' => $discount->date_start,
                    'date_end' => $discount->date_end,
                ])->values()->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Product) {
            return $record;
        }

        $data = $this->sanitizeAttributesSelectionBeforeSave($data);
        $bind_shop_language_id = (int) Arr::get($data, 'bind_shop_language_id', 0);

        DB::transaction(function () use ($record, $data, $bind_shop_language_id): void {
            try {
                $this->persistEditedProductData($record, $data, $bind_shop_language_id);
            } catch (Exception $e) {
                Notification::make()
                    ->title(__('admin/products/products.errors.product_update'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                throw $e;
            }
        });

        return $record->refresh();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistEditedProductData(Product $product, array $data, int $default_shop_language_id = 0): void
    {
        $product_id = (int) $product->id;

        $product->update([
            'model' => Arr::get($data, 'model'),
            'sku' => Arr::get($data, 'sku'),
            'ean' => Arr::get($data, 'ean'),
            'quantity' => (int) Arr::get($data, 'quantity', 0),
            'minimum' => max((int) Arr::get($data, 'minimum', 1), 1),
            'image' => Arr::get($data, 'image'),
            'price' => (float) Arr::get($data, 'price', 0),
            'is_active' => (bool) Arr::get($data, 'is_active', false),
            'date_available' => Arr::get($data, 'date_available'),
            'date_added' => Arr::get($data, 'date_added'),
        ]);

        $this->syncExternalProductIdForSelectedShop($product_id, $data);

        ProductDescription::query()->where('product_id', $product_id)->delete();
        $descriptions_by_language = Arr::get($data, 'descriptions_by_language', []);
        if (is_array($descriptions_by_language) && $descriptions_by_language !== []) {
            foreach ($descriptions_by_language as $shop_language_id => $description_row) {
                if (! is_array($description_row)) {
                    continue;
                }

                $resolved_shop_language_id = (int) $shop_language_id;
                if ($resolved_shop_language_id <= 0) {
                    $resolved_shop_language_id = $default_shop_language_id;
                }

                if ($resolved_shop_language_id <= 0) {
                    continue;
                }

                ProductDescription::query()->create([
                    'product_id' => $product_id,
                    'shop_language_id' => $resolved_shop_language_id,
                    'name' => Arr::get($description_row, 'name'),
                    'description' => Arr::get($description_row, 'description'),
                    'meta_title' => Arr::get($description_row, 'meta_title'),
                    'meta_description' => Arr::get($description_row, 'meta_description'),
                    'meta_keywords' => Arr::get($description_row, 'meta_keywords'),
                ]);
            }
        }

        ProductImage::query()->where('product_id', $product_id)->delete();
        foreach (Arr::get($data, 'images', []) as $image_row) {
            if (! is_array($image_row)) {
                continue;
            }

            $image = Str::trim((string) Arr::get($image_row, 'image', ''));
            if ($image === '') {
                continue;
            }

            ProductImage::query()->create([
                'product_id' => $product_id,
                'image' => $image,
                'sort_order' => (int) Arr::get($image_row, 'sort_order', 1),
            ]);
        }

        $this->syncProductCategoriesFromFormData($product_id, $data, $default_shop_language_id);
        $this->syncProductAttributesFromFormData($product_id, $data, $default_shop_language_id);

        SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->delete();

        $seo_urls_by_language = Arr::get($data, 'seo_urls_by_language', []);
        if (is_array($seo_urls_by_language) && $seo_urls_by_language !== []) {
            foreach ($seo_urls_by_language as $shop_language_id => $seo_rows) {
                $resolved_shop_language_id = (int) $shop_language_id;
                if ($resolved_shop_language_id <= 0) {
                    $resolved_shop_language_id = $default_shop_language_id;
                }

                if ($resolved_shop_language_id <= 0 || ! is_array($seo_rows)) {
                    continue;
                }

                foreach ($seo_rows as $seo_row) {
                    if (! is_array($seo_row)) {
                        continue;
                    }

                    SeoUrl::query()->create([
                        'seoable_type' => Product::class,
                        'seoable_id' => $product_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'query_value' => (string) $product_id,
                        'keyword' => (string) Arr::get($seo_row, 'keyword', ''),
                        'sort_order' => (int) Arr::get($seo_row, 'sort_order', 1),
                    ]);
                }
            }
        }

        ProductDiscount::query()->where('product_id', $product_id)->delete();
        foreach (Arr::get($data, 'discounts', []) as $discount_row) {
            if (! is_array($discount_row)) {
                continue;
            }

            ProductDiscount::query()->create([
                'product_id' => $product_id,
                'user_group_id' => (int) Arr::get($discount_row, 'user_group_id', 1),
                'quantity' => max((int) Arr::get($discount_row, 'quantity', 1), 1),
                'price' => (float) Arr::get($discount_row, 'price', 0),
                'priority' => (int) Arr::get($discount_row, 'priority', 1),
                'date_start' => Arr::get($discount_row, 'date_start') ?: now()->toDateTimeString(),
                'date_end' => Arr::get($discount_row, 'date_end') ?: now()->toDateTimeString(),
            ]);
        }

        ProductSpecial::query()->where('product_id', $product_id)->delete();
        foreach (Arr::get($data, 'specials', []) as $special_row) {
            if (! is_array($special_row)) {
                continue;
            }

            ProductSpecial::query()->create([
                'product_id' => $product_id,
                'user_group_id' => (int) Arr::get($special_row, 'user_group_id', 1),
                'price' => (float) Arr::get($special_row, 'price', 0),
                'priority' => (int) Arr::get($special_row, 'priority', 1),
                'date_start' => Arr::get($special_row, 'date_start') ?: now()->toDateTimeString(),
                'date_end' => Arr::get($special_row, 'date_end') ?: now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncExternalProductIdForSelectedShop(int $product_id, array $data): void
    {
        $shop_id = (int) Arr::get($data, 'bind_shop_id', 0);
        if ($product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $product_shop = ProductShop::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->orderByDesc('id')
            ->first();

        if (! $product_shop instanceof ProductShop) {
            Log::channel('stack')->warning('Product shop binding not found while updating external_product_id', [
                'product_id' => $product_id,
                'shop_id' => $shop_id,
            ]);

            return;
        }

        $raw_external_product_id = Arr::get($data, 'external_product_id');
        $external_product_id = (is_numeric($raw_external_product_id) && (int) $raw_external_product_id > 0)
            ? (int) $raw_external_product_id
            : null;

        try {
            $product_shop->update([
                'external_product_id' => $external_product_id,
            ]);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to update external_product_id for product shop binding', [
                'product_id' => $product_id,
                'shop_id' => $shop_id,
                'external_product_id' => $external_product_id,
                'error_msg' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncProductCategoriesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        CategoryProduct::query()->where('product_id', $product_id)->delete();

        $resolved_category_ids = [];
        $seen_category_ids = [];

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
            $resolved_category_ids[] = $resolved_category_id;
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
            $resolved_category_ids[] = $category_id;
        }

        foreach ($resolved_category_ids as $category_id) {
            CategoryProduct::query()->create([
                'product_id' => $product_id,
                'category_id' => $category_id,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncProductAttributesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        ProductToAttribute::query()->where('product_id', $product_id)->delete();

        $attribute_rows_to_create = [];
        $seen_attribute_pairs = [];

        $attributes_selected_by_language = Arr::get($data, 'attributes_selected_by_language', []);
        if (is_array($attributes_selected_by_language)) {
            foreach ($attributes_selected_by_language as $shop_language_id => $attribute_ids) {
                $resolved_shop_language_id = (int) $shop_language_id;
                if ($resolved_shop_language_id <= 0) {
                    $resolved_shop_language_id = $default_shop_language_id;
                }

                if ($resolved_shop_language_id <= 0 || ! is_array($attribute_ids)) {
                    continue;
                }

                foreach ($attribute_ids as $attribute_id) {
                    $resolved_attribute_id = (int) $attribute_id;
                    if ($resolved_attribute_id <= 0) {
                        continue;
                    }

                    $pair_key = $resolved_shop_language_id . ':' . $resolved_attribute_id;
                    if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                        throw ValidationException::withMessages([
                            "attributes_selected_by_language.$shop_language_id" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    $seen_attribute_pairs[$pair_key] = true;
                    $attribute_rows_to_create[] = [
                        'attribute_id' => $resolved_attribute_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'text' => '',
                    ];
                }
            }
        }

        $attributes_custom_by_language = Arr::get($data, 'attributes_custom_by_language', []);
        if (is_array($attributes_custom_by_language)) {
            foreach ($attributes_custom_by_language as $shop_language_id => $attribute_rows) {
                $resolved_shop_language_id = (int) $shop_language_id;
                if ($resolved_shop_language_id <= 0) {
                    $resolved_shop_language_id = $default_shop_language_id;
                }

                if ($resolved_shop_language_id <= 0 || ! is_array($attribute_rows)) {
                    continue;
                }

                foreach ($attribute_rows as $row_index => $attribute_row) {
                    if (! is_array($attribute_row)) {
                        continue;
                    }

                    $attribute_text = (string) Arr::get($attribute_row, 'text', '');
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
                        $pair_key = $resolved_shop_language_id . ':' . $attribute_id;

                        if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                            throw ValidationException::withMessages([
                                "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                            ]);
                        }

                        $seen_attribute_pairs[$pair_key] = true;
                        $attribute_rows_to_create[] = [
                            'attribute_id' => $attribute_id,
                            'shop_language_id' => $resolved_shop_language_id,
                            'text' => $attribute_text,
                        ];
                    }
                }
            }
        }

        foreach ($attribute_rows_to_create as $attribute_row_to_create) {
            ProductToAttribute::query()->create([
                'product_id' => $product_id,
                'attribute_id' => (int) $attribute_row_to_create['attribute_id'],
                'shop_language_id' => (int) $attribute_row_to_create['shop_language_id'],
                'text' => (string) $attribute_row_to_create['text'],
            ]);
        }
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

        $path_chunks = preg_split('/\s*\|\s*/u', $normalized_input) ?: [];
        $paths = [];
        $seen_paths = [];
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
            $paths[] = $path_segments;
        }

        return [$paths, array_values(array_unique($duplicate_paths))];
    }

    /**
     * @param list<string> $category_path
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
                    'parent_id' => $parent_category_id,
                    'sort_order' => 0,
                    'is_active' => true,
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
                'category_id' => $category_id,
                'shop_language_id' => $shop_language_id,
            ],
            [
                'name' => $clean_category_name,
                'description' => null,
                'h1_title' => $clean_category_name,
                'meta_title' => $clean_category_name,
                'meta_description' => null,
                'meta_keywords' => null,
            ]
        );
    }

    /**
     * @param list<string> $attribute_path
     */
    private function resolveOrCreateAttributeIdByPath(array $attribute_path, int $shop_language_id): int
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
            $existing_attribute_id = AttributeDescription::query()
                ->where('shop_language_id', $shop_language_id)
                ->whereRaw('LOWER(name) = ?', [Str::lower($attribute_name)])
                ->value('attribute_id');

            if ($existing_attribute_id !== null) {
                $resolved_attribute_id = (int) $existing_attribute_id;

                continue;
            }

            $attribute = Attribute::query()->create([
                'sort_order' => 1,
                'is_active' => true,
            ]);

            $resolved_attribute_id = (int) $attribute->id;

            AttributeDescription::query()->firstOrCreate([
                'attribute_id' => $resolved_attribute_id,
                'shop_language_id' => $shop_language_id,
            ], [
                'name' => $attribute_name,
            ]);
        }

        if ($resolved_attribute_id === null) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        return $resolved_attribute_id;
    }

    /**
     * @return array<string, int>
     */
    private function queueProductUpdateViaApi(Product $product): array
    {
        $summary = [
            'products_total'              => 1,
            'shops_total'                 => 0,
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        $form_data = $this->form->getState();
        $update_instructions = $this->buildUpdateInstructionsFromModes($product, $form_data);

        if ($update_instructions === []) {
            return $summary;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($shop_ids === []) {
            $summary['skipped_not_bound'] = 1;

            return $summary;
        }

        $summary['shops_total'] = count($shop_ids);
        $requested_by_user_id = is_numeric(auth()->id()) ? (int) auth()->id() : null;

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => $requested_by_user_id,
            'source_type'     => ProductUpdateBatchesSourceTypeEnum::EDIT_PRODUCT_API->value,
            'source_name'     => 'Edit product API update #' . (int) $product->id,
            'source_path'     => null,
            'status'          => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'triggered_from'       => 'edit_product_page',
                'product_id'           => (int) $product->id,
                'requested_by_user_id' => $requested_by_user_id,
                'update_state'         => 'processing',
                'update_started_at'    => now()->toDateTimeString(),
                'update_finished_at'   => null,
            ],
            'started_at'      => now(),
            'finished_at'     => null,
        ]);

        foreach ($shop_ids as $shop_id) {
            $queued_result = $this->createEditApiUpdateItemAndDispatch(
                (int) $batch->id,
                (int) $product->id,
                (int) $shop_id,
                $update_instructions,
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

        $this->syncEditApiBatchStatus((int) $batch->id);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $form_data
     * @return array<string, mixed>
     */
    private function buildUpdateInstructionsFromModes(Product $product, array $form_data): array
    {
        $mode_state = Arr::get($form_data, 'api_update_modes', []);
        if (! is_array($mode_state) || $mode_state === []) {
            return [];
        }

        $value_map = $this->resolveUpdateInstructionValueMap($product);
        $instructions = [];

        foreach (self::API_UPDATE_FIELD_MAP as $group_key => $group_config) {
            $sheet_name = (string) Arr::get($group_config, 'sheet', '');
            $fields = Arr::get($group_config, 'fields', []);

            if ($sheet_name === '' || ! is_array($fields) || $fields === []) {
                continue;
            }

            $sheet_fields = [];

            foreach ($fields as $field_key) {
                $mode = Str::lower(Str::trim((string) Arr::get($mode_state, $group_key . '.' . $field_key, 'skip')));
                $action = $this->resolveUpdateInstructionActionByMode($mode);
                if ($action === 'skip') {
                    continue;
                }

                $sheet_fields[$field_key] = [
                    'action' => $action,
                    'value' => $action === 'delete' ? null : Arr::get($value_map, $group_key . '.' . $field_key),
                ];
            }

            if ($sheet_fields === []) {
                continue;
            }

            $instructions[$sheet_name] = [
                'fields' => $sheet_fields,
            ];
        }

        return $instructions;
    }

    private function resolveUpdateInstructionActionByMode(string $mode): string
    {
        return match ($mode) {
            'delete' => 'delete',
            'update' => 'set',
            default => 'skip',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUpdateInstructionValueMap(Product $product): array
    {
        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
        ]);

        $category_names = $product->categories
            ->map(static fn (Category $category): string => (string) ($category->descriptions->sortBy('id')->first()?->name ?? ''))
            ->filter(static fn (string $name): bool => Str::trim($name) !== '')
            ->values()
            ->all();

        $attribute_pairs = $product->productToAttributes
            ->map(static fn (ProductToAttribute $attribute): array => [
                'attribute_id' => (int) ($attribute->attribute_id ?? 0),
                'shop_language_id' => (int) ($attribute->shop_language_id ?? 0),
            ])
            ->filter(static fn (array $pair): bool => $pair['attribute_id'] > 0 && $pair['shop_language_id'] > 0)
            ->values()
            ->all();

        $attribute_name_map = AttributeDescription::getNameMapByAttributeLanguagePairs($attribute_pairs);

        $attribute_names = [];
        $attribute_texts = [];
        foreach ($product->productToAttributes as $product_to_attribute) {
            $attribute_id = (int) ($product_to_attribute->attribute_id ?? 0);
            $shop_language_id = (int) ($product_to_attribute->shop_language_id ?? 0);
            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $attribute_name = (string) ($attribute_name_map[$attribute_id . ':' . $shop_language_id] ?? '');
            if (Str::trim($attribute_name) !== '') {
                $attribute_names[] = $attribute_name;
            }

            $attribute_text = Str::trim((string) ($product_to_attribute->text ?? ''));
            if ($attribute_text !== '') {
                $attribute_texts[] = $attribute_text;
            }
        }

        $seo_urls = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', (int) $product->id)
            ->orderBy('id')
            ->get();

        $product_descriptions = $product->descriptions
            ->mapWithKeys(static fn (ProductDescription $description): array => [
                (int) $description->shop_language_id => [
                    'name' => $description->name,
                    'description' => $description->description,
                    'meta_title' => $description->meta_title,
                    'meta_description' => $description->meta_description,
                    'meta_keywords' => $description->meta_keywords,
                ],
            ])
            ->toArray();

        return [
            'product' => [
                'model' => $product->model,
                'sku' => $product->sku,
                'ean' => $product->ean,
                'quantity' => $product->quantity,
                'minimum' => $product->minimum,
                'image' => $product->image,
                'price' => $product->price,
                'is_active' => (bool) $product->is_active,
                'date_available' => $product->date_available?->toDateTimeString(),
                'date_added' => $product->date_added?->toDateTimeString(),
            ],
            'description' => [
                'name' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'name'))->toArray(),
                'description' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'description'))->toArray(),
                'meta_title' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_title'))->toArray(),
                'meta_description' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_description'))->toArray(),
                'meta_keywords' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_keywords'))->toArray(),
            ],
            'image' => [
                'image' => $product->images->pluck('image')->values()->all(),
                'sort_order' => $product->images->pluck('sort_order')->values()->all(),
            ],
            'product_category' => [
                'category_name' => array_values(array_unique($category_names)),
            ],
            'product_attribute' => [
                'attribute_name' => array_values(array_unique($attribute_names)),
                'attribute_text' => array_values($attribute_texts),
            ],
            'seo_url' => [
                'query_key' => $seo_urls->pluck('query_key')->values()->all(),
                'query_value' => $seo_urls->pluck('query_value')->values()->all(),
                'keyword' => $seo_urls->pluck('keyword')->values()->all(),
                'sort_order' => $seo_urls->pluck('sort_order')->values()->all(),
            ],
            'special' => [
                'user_group_id' => $product->specials->pluck('user_group_id')->values()->all(),
                'price' => $product->specials->pluck('price')->values()->all(),
                'priority' => $product->specials->pluck('priority')->values()->all(),
                'date_start' => $product->specials->pluck('date_start')->values()->all(),
                'date_end' => $product->specials->pluck('date_end')->values()->all(),
            ],
            'discount' => [
                'user_group_id' => $product->discounts->pluck('user_group_id')->values()->all(),
                'quantity' => $product->discounts->pluck('quantity')->values()->all(),
                'price' => $product->discounts->pluck('price')->values()->all(),
                'priority' => $product->discounts->pluck('priority')->values()->all(),
                'date_start' => $product->discounts->pluck('date_start')->values()->all(),
                'date_end' => $product->discounts->pluck('date_end')->values()->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $update_instructions
     * @return array<string, int>
     */
    private function createEditApiUpdateItemAndDispatch(
        int $batch_id,
        int $product_id,
        int $shop_id,
        array $update_instructions,
        ?int $requested_by_user_id = null
    ): array {
        $summary = [
            'updates_queued' => 0,
            'already_failed' => 0,
            'already_queued_or_exported' => 0,
            'skipped_not_bound' => 0,
            'skipped_without_external_id' => 0,
            'failed_created' => 0,
            'errors' => 0,
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
                $this->createEditApiFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'Product is not bound to selected shop',
                    $requested_by_user_id,
                    $update_instructions
                );
                $summary['skipped_not_bound']++;
                $summary['failed_created']++;

                return $summary;
            }

            $external_product_id = (int) ($product_shop->external_product_id ?? 0);
            if ($external_product_id <= 0) {
                $this->createEditApiFailedUpdateItem(
                    $batch_id,
                    $product_id,
                    $shop_id,
                    'External product id is missing for update',
                    $requested_by_user_id,
                    $update_instructions
                );
                $summary['skipped_without_external_id']++;
                $summary['failed_created']++;

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
                    'requested_by_user_id' => $requested_by_user_id,
                    'triggered_from' => 'edit_product_page',
                    'update_instructions' => $update_instructions,
                ],
                'status' => ProductUpdateItemsStatusEnum::PROCESSING->value,
                'error_message' => null,
                'processed_at' => null,
            ]);

            ProcessProductUpdateItemJob::dispatchSync((int) $update_item->id);
            $summary['updates_queued']++;
        } catch (QueryException $exception) {
            $sql_state = (string) ($exception->errorInfo[0] ?? '');

            if ($sql_state === '23505') {
                $summary['already_queued_or_exported']++;

                return $summary;
            }

            Log::channel('stack')->error('Failed to create edit product API update item', [
                'batch_id' => $batch_id,
                'product_id' => $product_id,
                'shop_id' => $shop_id,
                'message' => $exception->getMessage(),
            ]);

            $summary['errors']++;
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to queue edit product API update', [
                'batch_id' => $batch_id,
                'product_id' => $product_id,
                'shop_id' => $shop_id,
                'message' => $exception->getMessage(),
            ]);

            $summary['errors']++;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $update_instructions
     */
    private function createEditApiFailedUpdateItem(
        int $batch_id,
        int $product_id,
        int $shop_id,
        string $error_message,
        ?int $requested_by_user_id = null,
        array $update_instructions = []
    ): void {
        Log::channel('stack')->error('Edit product API update skipped', [
            'batch_id' => $batch_id,
            'product_id' => $product_id,
            'shop_id' => $shop_id,
            'message' => $error_message,
        ]);

        ProductUpdateItem::query()->create([
            'product_update_batch_id' => $batch_id,
            'product_id' => $product_id,
            'payload' => [
                'operation' => 'update',
                'shop_id' => $shop_id,
                'requested_product_id' => $product_id,
                'target_product_id' => $product_id,
                'requested_by_user_id' => $requested_by_user_id,
                'triggered_from' => 'edit_product_page',
                'update_instructions' => $update_instructions,
            ],
            'status' => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at' => now(),
        ]);
    }

    private function syncEditApiBatchStatus(int $batch_id): void
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

        $total_update_items = (int) $status_rows->sum(static fn ($row): int => (int) ($row->status_total ?? 0));
        if ($total_update_items <= 0) {
            return;
        }

        $processing_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::PROCESSING->value)->status_total ?? 0);
        $failed_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::FAILED->value)->status_total ?? 0);
        $updated_count = (int) ($status_rows->firstWhere('status', ProductUpdateItemsStatusEnum::SUCCESSED->value)->status_total ?? 0);

        $final_status = match (true) {
            $processing_count > 0 => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            $failed_count > 0 && $updated_count > 0 => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_count > 0 && $updated_count === 0 => ProductUpdateBatchesStatusEnum::FAILED->value,
            default => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $batch->update([
            'status' => $final_status,
            'total_items' => $total_update_items,
            'processed_items' => max($updated_count + $failed_count, 0),
            'failed_items' => max($failed_count, 0),
            'finished_at' => $processing_count > 0 ? null : now(),
            'options' => [
                ...($batch->options ?? []),
                'update_state' => $processing_count > 0 ? 'processing' : 'finished',
                'update_total_items' => $total_update_items,
                'update_success_items' => $updated_count,
                'update_failed_items' => $failed_count,
                'update_finished_at' => $processing_count > 0 ? null : now()->toDateTimeString(),
            ],
        ]);
    }
}
