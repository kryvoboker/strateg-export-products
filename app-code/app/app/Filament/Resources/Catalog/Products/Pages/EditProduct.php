<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Filament\Resources\Catalog\Products\ProductResource;
use App\Jobs\ProcessAttributeNameTranslationJob;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Jobs\ProcessProductTranslationJob;
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
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
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

        $bind_shop_language_id = (int) Arr::get($data, 'bind_shop_language_id', 0);

        DB::transaction(function () use ($record, $data, $bind_shop_language_id): void {
            $this->persistEditedProductData($record, $data, $bind_shop_language_id);
        });

        $this->dispatchAttributeNameTranslationJobs((int) $record->id, $data);
        $this->dispatchCategoryNameTranslationJobs((int) $record->id, $data);
        $this->dispatchProductTranslationJob((int) $record->id);

        return $record->refresh();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchAttributeNameTranslationJobs(int $product_id, array $data): void
    {
        if ($product_id <= 0) {
            return;
        }

        $attribute_ids = ProductToAttribute::getUniqueAttributeIdsByProductId($product_id);
        if ($attribute_ids === []) {
            return;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $bind_shop_id = (int) Arr::get($data, 'bind_shop_id', 0);
        if ($bind_shop_id > 0 && ! in_array($bind_shop_id, $shop_ids, true)) {
            $shop_ids[] = $bind_shop_id;
        }

        foreach ($attribute_ids as $attribute_id) {
            ProcessAttributeNameTranslationJob::dispatch(
                (int) $attribute_id,
                $shop_ids
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dispatchCategoryNameTranslationJobs(int $product_id, array $data): void
    {
        if ($product_id <= 0) {
            return;
        }

        $category_ids = CategoryProduct::getUniqueCategoryIdsByProductId($product_id);
        if ($category_ids === []) {
            return;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $bind_shop_id = (int) Arr::get($data, 'bind_shop_id', 0);
        if ($bind_shop_id > 0 && ! in_array($bind_shop_id, $shop_ids, true)) {
            $shop_ids[] = $bind_shop_id;
        }

        foreach ($category_ids as $category_id) {
            ProcessCategoryNameTranslationJob::dispatch(
                (int) $category_id,
                $shop_ids
            );
        }
    }

    private function dispatchProductTranslationJob(int $product_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        ProcessProductTranslationJob::dispatch($product_id);
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
                        'query_key' => (string) Arr::get($seo_row, 'query_key', ''),
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
                'date_start' => Arr::get($discount_row, 'date_start') ?: get_now_date()->toDateTimeString(),
                'date_end' => Arr::get($discount_row, 'date_end') ?: get_now_date()->toDateTimeString(),
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
                'date_start' => Arr::get($special_row, 'date_start') ?: get_now_date()->toDateTimeString(),
                'date_end' => Arr::get($special_row, 'date_end') ?: get_now_date()->toDateTimeString(),
            ]);
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
}
