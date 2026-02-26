<?php

declare(strict_types=1);

namespace App\Services\Products;

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
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Seo\SeoUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductEditPersistenceService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{
     *      allow_legacy_descriptions?: bool,
     *      allow_legacy_attributes?: bool,
     *      allow_legacy_seo_urls?: bool,
     *      sync_external_product_id?: bool
     *  }  $options
     */
    public function persist(Product $product, array $data, int $default_shop_language_id = 0, array $options = []): void
    {
        $product_id = (int) $product->id;

        $product->update([
            'model'          => Arr::get($data, 'model'),
            'sku'            => Arr::get($data, 'sku'),
            'ean'            => Arr::get($data, 'ean'),
            'quantity'       => (int) Arr::get($data, 'quantity', 0),
            'minimum'        => max((int) Arr::get($data, 'minimum', 1), 1),
            'image'          => Arr::get($data, 'image'),
            'price'          => (float) Arr::get($data, 'price', 0),
            'is_active'      => (bool) Arr::get($data, 'is_active', false),
            'date_available' => Arr::get($data, 'date_available'),
            'date_added'     => Arr::get($data, 'date_added'),
        ]);

        if ((bool) ($options['sync_external_product_id'] ?? false) === true) {
            $this->syncExternalProductIdForSelectedShop($product_id, $data);
        }

        $this->syncProductDescriptions(
            $product_id,
            $data,
            $default_shop_language_id,
            (bool) ($options['allow_legacy_descriptions'] ?? false),
        );

        $this->syncProductImages($product_id, $data);
        $this->syncProductCategoriesFromFormData($product_id, $data, $default_shop_language_id);
        $this->syncProductAttributesFromFormData(
            $product_id,
            $data,
            $default_shop_language_id,
            (bool) ($options['allow_legacy_attributes'] ?? false),
        );
        $this->syncProductManufacturerBrandFromFormData($product_id, $data);
        $this->syncProductSeoUrls(
            $product_id,
            $data,
            $default_shop_language_id,
            (bool) ($options['allow_legacy_seo_urls'] ?? false),
        );
        $this->syncProductDiscounts($product_id, $data);
        $this->syncProductSpecials($product_id, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncExternalProductIdForSelectedShop(int $product_id, array $data): void
    {
        $shop_id = (int) Arr::get($data, 'bind_shop_id', 0);
        if ($product_id <= 0 || $shop_id <= 0) {
            return;
        }

        $product_shop = ProductShop::resolveLatestByProductAndShop($product_id, $shop_id);
        if (! $product_shop instanceof ProductShop) {
            Log::channel('stack')->warning('Product shop binding not found while updating external_product_id', [
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
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
                'product_id'          => $product_id,
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id,
                'error_msg'           => $exception->getMessage(),
                'file'                => $exception->getFile(),
                'line'                => $exception->getLine(),
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductDescriptions(
        int $product_id,
        array $data,
        int $default_shop_language_id,
        bool $allow_legacy_descriptions
    ): void {
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
                    'product_id'       => $product_id,
                    'shop_language_id' => $resolved_shop_language_id,
                    'name'             => Arr::get($description_row, 'name'),
                    'description'      => Arr::get($description_row, 'description'),
                    'meta_title'       => Arr::get($description_row, 'meta_title'),
                    'meta_description' => Arr::get($description_row, 'meta_description'),
                    'meta_keywords'    => Arr::get($description_row, 'meta_keywords'),
                ]);
            }

            return;
        }

        if ($allow_legacy_descriptions === false) {
            return;
        }

        foreach (Arr::get($data, 'descriptions', []) as $description_row) {
            if (! is_array($description_row)) {
                continue;
            }

            $shop_language_id = (int) Arr::get($description_row, 'shop_language_id', 0);
            if ($shop_language_id <= 0) {
                $shop_language_id = $default_shop_language_id;
            }

            if ($shop_language_id <= 0) {
                continue;
            }

            ProductDescription::query()->create([
                'product_id'       => $product_id,
                'shop_language_id' => $shop_language_id,
                'name'             => Arr::get($description_row, 'name'),
                'description'      => Arr::get($description_row, 'description'),
                'meta_title'       => Arr::get($description_row, 'meta_title'),
                'meta_description' => Arr::get($description_row, 'meta_description'),
                'meta_keywords'    => Arr::get($description_row, 'meta_keywords'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductImages(int $product_id, array $data): void
    {
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
                'image'      => $image,
                'sort_order' => (int) Arr::get($image_row, 'sort_order', 1),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductSeoUrls(
        int $product_id,
        array $data,
        int $default_shop_language_id,
        bool $allow_legacy_seo_urls
    ): void {
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
                        'seoable_type'     => Product::class,
                        'seoable_id'       => $product_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'query_key'        => (string) Arr::get($seo_row, 'query_key', ''),
                        'query_value'      => (string) $product_id,
                        'keyword'          => (string) Arr::get($seo_row, 'keyword', ''),
                        'sort_order'       => (int) Arr::get($seo_row, 'sort_order', 1),
                    ]);
                }
            }

            return;
        }

        if ($allow_legacy_seo_urls === false) {
            return;
        }

        foreach (Arr::get($data, 'seo_urls', []) as $seo_row) {
            if (! is_array($seo_row)) {
                continue;
            }

            SeoUrl::query()->create([
                'seoable_type'     => Product::class,
                'seoable_id'       => $product_id,
                'shop_language_id' => null,
                'query_key'        => (string) Arr::get($seo_row, 'query_key', ''),
                'query_value'      => (string) $product_id,
                'keyword'          => (string) Arr::get($seo_row, 'keyword', ''),
                'sort_order'       => (int) Arr::get($seo_row, 'sort_order', 1),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductDiscounts(int $product_id, array $data): void
    {
        ProductDiscount::query()->where('product_id', $product_id)->delete();

        foreach (Arr::get($data, 'discounts', []) as $discount_row) {
            if (! is_array($discount_row)) {
                continue;
            }

            ProductDiscount::query()->create([
                'product_id'    => $product_id,
                'user_group_id' => (int) Arr::get($discount_row, 'user_group_id', 1),
                'quantity'      => max((int) Arr::get($discount_row, 'quantity', 1), 1),
                'price'         => (float) Arr::get($discount_row, 'price', 0),
                'priority'      => (int) Arr::get($discount_row, 'priority', 1),
                'date_start'    => Arr::get($discount_row, 'date_start') ?: now()->toDateTimeString(),
                'date_end'      => Arr::get($discount_row, 'date_end') ?: now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductSpecials(int $product_id, array $data): void
    {
        ProductSpecial::query()->where('product_id', $product_id)->delete();

        foreach (Arr::get($data, 'specials', []) as $special_row) {
            if (! is_array($special_row)) {
                continue;
            }

            ProductSpecial::query()->create([
                'product_id'    => $product_id,
                'user_group_id' => (int) Arr::get($special_row, 'user_group_id', 1),
                'price'         => (float) Arr::get($special_row, 'price', 0),
                'priority'      => (int) Arr::get($special_row, 'priority', 1),
                'date_start'    => Arr::get($special_row, 'date_start') ?: now()->toDateTimeString(),
                'date_end'      => Arr::get($special_row, 'date_end') ?: now()->toDateTimeString(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
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
                'product_id'  => $product_id,
                'category_id' => $category_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductAttributesFromFormData(
        int $product_id,
        array $data,
        int $default_shop_language_id,
        bool $allow_legacy_attributes
    ): void {
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

                    $pair_key = $resolved_shop_language_id.':'.$resolved_attribute_id;
                    if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                        throw ValidationException::withMessages([
                            "attributes_selected_by_language.$shop_language_id" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    $seen_attribute_pairs[$pair_key] = true;
                    $attribute_rows_to_create[] = [
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
                        $pair_key = $resolved_shop_language_id.':'.$attribute_id;

                        if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                            throw ValidationException::withMessages([
                                "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                            ]);
                        }

                        $seen_attribute_pairs[$pair_key] = true;
                        $attribute_rows_to_create[] = [
                            'attribute_id'     => $attribute_id,
                            'shop_language_id' => $resolved_shop_language_id,
                            'text'             => $attribute_text,
                        ];
                    }
                }
            }
        }

        if ($allow_legacy_attributes === true) {
            $this->addLegacyAttributeRows($attribute_rows_to_create, $seen_attribute_pairs, $data, $default_shop_language_id);
        }

        foreach ($attribute_rows_to_create as $attribute_row_to_create) {
            ProductToAttribute::query()->create([
                'product_id'       => $product_id,
                'attribute_id'     => (int) $attribute_row_to_create['attribute_id'],
                'shop_language_id' => (int) $attribute_row_to_create['shop_language_id'],
                'text'             => (string) $attribute_row_to_create['text'],
            ]);
        }
    }

    /**
     * @param  array<int, array{attribute_id:int,shop_language_id:int,text:string}>  &$attribute_rows_to_create
     * @param  array<string, bool>  &$seen_attribute_pairs
     * @param  array<string, mixed>  $data
     */
    private function addLegacyAttributeRows(
        array &$attribute_rows_to_create,
        array &$seen_attribute_pairs,
        array $data,
        int $default_shop_language_id
    ): void {
        $legacy_attributes_by_language = Arr::get($data, 'attributes_by_language', []);
        if (! is_array($legacy_attributes_by_language)) {
            return;
        }

        foreach ($legacy_attributes_by_language as $shop_language_id => $attribute_rows) {
            $resolved_shop_language_id = (int) $shop_language_id;
            if ($resolved_shop_language_id <= 0) {
                $resolved_shop_language_id = $default_shop_language_id;
            }

            if ($resolved_shop_language_id <= 0 || ! is_array($attribute_rows)) {
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
                $attribute_rows_to_create[] = [
                    'attribute_id'     => $attribute_id,
                    'shop_language_id' => $resolved_shop_language_id,
                    'text'             => (string) Arr::get($attribute_row, 'text', ''),
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductManufacturerBrandFromFormData(int $product_id, array $data): void
    {
        $manufacturer_id = (int) Arr::get($data, 'manufacturer_id', 0);
        $brand_id = (int) Arr::get($data, 'brand_id', 0);

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
            $existing_category_id = Category::findCategoryIdByParentAndNameForLanguage(
                $parent_category_id,
                $category_name,
                $shop_language_id,
            );

            if ($existing_category_id === null) {
                $category = Category::query()->create([
                    'parent_id'  => $parent_category_id,
                    'sort_order' => 0,
                    'is_active'  => true,
                ]);

                $existing_category_id = (int) $category->id;
            }

            CategoryDescription::ensureDefaultDescription(
                $existing_category_id,
                $shop_language_id > 0 ? $shop_language_id : null,
                $category_name
            );

            $parent_category_id = $existing_category_id;
        }

        return $parent_category_id;
    }

    /**
     * @param  list<string>  $attribute_path
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

        $resolved_attribute_id = 0;

        foreach ($attribute_path as $attribute_name) {
            $existing_attribute_id = AttributeDescription::findAttributeIdByNameForLanguage($attribute_name, $shop_language_id);
            if ($existing_attribute_id > 0) {
                $resolved_attribute_id = $existing_attribute_id;
                continue;
            }

            $attribute = Attribute::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $resolved_attribute_id = (int) $attribute->id;
            AttributeDescription::upsertName($resolved_attribute_id, $shop_language_id, $attribute_name);
        }

        if ($resolved_attribute_id <= 0) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        return $resolved_attribute_id;
    }
}
