<?php

declare(strict_types=1);

namespace App\Supports\Services\Products\Backup;

use App\Models\Attributes\Attribute;
use App\Models\Categories\Category;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Seo\SeoUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductBackupPayloadRestoreService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function applySnapshotToProduct(Product $product, array $payload): void
    {
        $product_id = (int) $product->id;

        $product->update([
            'product_import_item_id' => ($snapshot_product_id = Arr::get($payload, 'product.product_import_item_id')) !== null
                ? (int) $snapshot_product_id
                : null,
            'marked_to_shop' => Arr::get($payload, 'product.marked_to_shop'),
            'model'          => Arr::get($payload, 'product.model'),
            'sku'            => Arr::get($payload, 'product.sku'),
            'ean'            => Arr::get($payload, 'product.ean'),
            'quantity'       => (int) Arr::get($payload, 'product.quantity', 0),
            'minimum'        => max((int) Arr::get($payload, 'product.minimum', 1), 1),
            'image'          => Arr::get($payload, 'product.image'),
            'price'          => (float) Arr::get($payload, 'product.price', 0),
            'is_active'      => (bool) Arr::get($payload, 'product.is_active', false),
            'date_available' => Arr::get($payload, 'product.date_available'),
            'date_added'     => Arr::get($payload, 'product.date_added'),
        ]);

        $this->syncProductDescriptions($product_id, Arr::get($payload, 'descriptions', []));
        $this->syncProductImages($product_id, Arr::get($payload, 'images', []));
        $this->syncProductCategories($product, Arr::get($payload, 'categories', []));
        $this->syncProductAttributes($product_id, Arr::get($payload, 'attributes', []));
        $this->syncProductSeoUrls($product_id, Arr::get($payload, 'seo_urls', []));
        $this->syncProductSpecials($product_id, Arr::get($payload, 'specials', []));
        $this->syncProductDiscounts($product_id, Arr::get($payload, 'discounts', []));
    }

    /**
     * @param  mixed  $description_rows
     */
    private function syncProductDescriptions(int $product_id, mixed $description_rows): void
    {
        ProductDescription::query()->where('product_id', $product_id)->delete();

        foreach (is_array($description_rows) ? $description_rows : [] as $description_row) {
            if (! is_array($description_row)) {
                continue;
            }

            $shop_language_id = Arr::get($description_row, 'shop_language_id');
            ProductDescription::query()->create([
                'product_id'       => $product_id,
                'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                'name'             => Arr::get($description_row, 'name'),
                'description'      => Arr::get($description_row, 'description'),
                'meta_title'       => Arr::get($description_row, 'meta_title'),
                'meta_description' => Arr::get($description_row, 'meta_description'),
                'meta_keywords'    => Arr::get($description_row, 'meta_keywords'),
            ]);
        }
    }

    /**
     * @param  mixed  $image_rows
     */
    private function syncProductImages(int $product_id, mixed $image_rows): void
    {
        ProductImage::query()->where('product_id', $product_id)->delete();

        foreach (is_array($image_rows) ? $image_rows : [] as $image_row) {
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
                'sort_order' => (int) Arr::get($image_row, 'sort_order', 0),
            ]);
        }
    }

    /**
     * @param  mixed  $category_rows
     */
    private function syncProductCategories(Product $product, mixed $category_rows): void
    {
        $category_ids = collect(is_array($category_rows) ? $category_rows : [])
            ->map(static fn ($category_row): int => is_array($category_row) ? (int) Arr::get($category_row, 'id', 0) : 0)
            ->filter(static fn (int $category_id): bool => $category_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($category_ids === []) {
            $product->categories()->sync([]);

            return;
        }

        $existing_category_ids = Category::query()
            ->whereIn('id', $category_ids)
            ->pluck('id')
            ->map(static fn ($category_id): int => (int) $category_id)
            ->values()
            ->all();

        $product->categories()->sync($existing_category_ids);
    }

    /**
     * @param  mixed  $attribute_rows
     */
    private function syncProductAttributes(int $product_id, mixed $attribute_rows): void
    {
        ProductToAttribute::query()->where('product_id', $product_id)->delete();

        $normalized_attribute_rows = is_array($attribute_rows) ? $attribute_rows : [];

        $attribute_ids = collect($normalized_attribute_rows)
            ->map(static fn ($attribute_row): int => is_array($attribute_row) ? (int) Arr::get($attribute_row, 'attribute_id', 0) : 0)
            ->filter(static fn (int $attribute_id): bool => $attribute_id > 0)
            ->unique()
            ->values()
            ->all();

        $existing_attribute_ids = Attribute::query()
            ->whereIn('id', $attribute_ids)
            ->pluck('id')
            ->map(static fn ($attribute_id): int => (int) $attribute_id)
            ->flip()
            ->all();

        foreach ($normalized_attribute_rows as $attribute_row) {
            if (! is_array($attribute_row)) {
                continue;
            }

            $attribute_id = (int) Arr::get($attribute_row, 'attribute_id', 0);
            if ($attribute_id <= 0 || ! array_key_exists($attribute_id, $existing_attribute_ids)) {
                continue;
            }

            $shop_language_id = Arr::get($attribute_row, 'shop_language_id');
            ProductToAttribute::query()->create([
                'product_id'       => $product_id,
                'attribute_id'     => $attribute_id,
                'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                'text'             => Arr::get($attribute_row, 'text'),
            ]);
        }
    }

    /**
     * @param  mixed  $seo_url_rows
     */
    private function syncProductSeoUrls(int $product_id, mixed $seo_url_rows): void
    {
        SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->delete();

        foreach (is_array($seo_url_rows) ? $seo_url_rows : [] as $seo_url_row) {
            if (! is_array($seo_url_row)) {
                continue;
            }

            $keyword = Str::trim((string) Arr::get($seo_url_row, 'keyword', ''));
            if ($keyword === '') {
                continue;
            }

            $shop_language_id = Arr::get($seo_url_row, 'shop_language_id');
            SeoUrl::query()->create([
                'seoable_type'     => Product::class,
                'seoable_id'       => $product_id,
                'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                'query_key'        => Str::trim((string) Arr::get($seo_url_row, 'query_key', 'product_id')),
                'query_value'      => (string) Arr::get($seo_url_row, 'query_value', (string) $product_id),
                'keyword'          => $keyword,
                'sort_order'       => (int) Arr::get($seo_url_row, 'sort_order', 0),
            ]);
        }
    }

    /**
     * @param  mixed  $special_rows
     */
    private function syncProductSpecials(int $product_id, mixed $special_rows): void
    {
        ProductSpecial::query()->where('product_id', $product_id)->delete();

        foreach (is_array($special_rows) ? $special_rows : [] as $special_row) {
            if (! is_array($special_row)) {
                continue;
            }

            ProductSpecial::query()->create([
                'product_id'    => $product_id,
                'user_group_id' => (int) Arr::get($special_row, 'user_group_id', 0),
                'price'         => (float) Arr::get($special_row, 'price', 0),
                'priority'      => (int) Arr::get($special_row, 'priority', 0),
                'date_start'    => Arr::get($special_row, 'date_start'),
                'date_end'      => Arr::get($special_row, 'date_end'),
            ]);
        }
    }

    /**
     * @param  mixed  $discount_rows
     */
    private function syncProductDiscounts(int $product_id, mixed $discount_rows): void
    {
        ProductDiscount::query()->where('product_id', $product_id)->delete();

        foreach (is_array($discount_rows) ? $discount_rows : [] as $discount_row) {
            if (! is_array($discount_row)) {
                continue;
            }

            ProductDiscount::query()->create([
                'product_id'    => $product_id,
                'user_group_id' => (int) Arr::get($discount_row, 'user_group_id', 0),
                'quantity'      => max((int) Arr::get($discount_row, 'quantity', 1), 1),
                'price'         => (float) Arr::get($discount_row, 'price', 0),
                'priority'      => (int) Arr::get($discount_row, 'priority', 0),
                'date_start'    => Arr::get($discount_row, 'date_start'),
                'date_end'      => Arr::get($discount_row, 'date_end'),
            ]);
        }
    }
}
