<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Categories\Category;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;

class ProductEditStateBuilderService
{
    /**
     * Build one authoritative edit-state payload for product editing surfaces.
     *
     * The catalog product edit page and the import-item modal were previously
     * hydrating nearly identical arrays in two different places. That drift
     * already caused bugs in pre-bind label and attribute rendering, so this
     * builder keeps the read-model for product editing in one service.
     *
     * @return array<string, mixed>
     */
    public function buildForProduct(Product $product, bool $include_named_categories = false): array
    {
        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'productToManufacturerBrand',
            'specials',
            'discounts',
        ]);

        $product_resource_options_service = app(ProductResourceOptionsService::class);

        $current_shop_id = (int) (ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->orderBy('id')
            ->value('shop_id') ?? 0);

        $current_external_product_id = $current_shop_id > 0
            ? ProductShop::resolveExternalProductId((int) $product->id, $current_shop_id)
            : 0;

        $seo_urls = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', (int) $product->id)
            ->orderBy('id')
            ->get();

        $current_shop_language_id = $this->resolveCurrentShopLanguageId($current_shop_id, $seo_urls);

        if ($current_shop_id <= 0 && $current_shop_language_id > 0) {
            $current_shop_id = (int) (ShopLanguage::query()
                ->whereKey($current_shop_language_id)
                ->value('shop_id') ?? 0);
        }

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
            ->map(static fn ($rows): array => $rows
                ->map(static fn (ProductToAttribute $attribute): array => [
                    'attribute_id' => $attribute->attribute_id,
                    'text'         => $attribute->text,
                ])->values()->all())
            ->toArray();

        $attributes_selected_by_language = $product->productToAttributes
            ->groupBy(static fn (ProductToAttribute $attribute): int => (int) ($attribute->shop_language_id ?? 0))
            ->map(static fn ($rows): array => $rows
                ->pluck('attribute_id')
                ->filter()
                ->map(static fn ($attribute_id): int => (int) $attribute_id)
                ->unique()
                ->values()
                ->all())
            ->toArray();

        $attributes_custom_by_language = $product->productToAttributes
            ->groupBy(static fn (ProductToAttribute $attribute): int => (int) ($attribute->shop_language_id ?? 0))
            ->map(function ($rows, $shop_language_id) use ($product_resource_options_service): array {
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
                $shop_language_id = $current_shop_language_id;
            }

            if ($shop_language_id <= 0) {
                $shop_language_id = (int) (ShopLanguage::query()
                    ->where('is_active', true)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id') ?? 0);
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

        $state = [
            'bind_shop_id'          => $current_shop_id > 0 ? $current_shop_id : null,
            'bind_shop_language_id' => $current_shop_language_id > 0 ? $current_shop_language_id : null,
            'product_id'            => (int) $product->id,
            'model'                 => $product->model,
            'sku'                   => $product->sku,
            'ean'                   => $product->ean,
            'external_product_id'   => $current_external_product_id > 0 ? $current_external_product_id : null,
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
            'category_source_scope'   => $current_shop_id > 0 ? 'shop' : 'all',
            'categories_existing_ids' => $product->categories
                ->pluck('id')
                ->filter()
                ->map(static fn ($category_id): int => (int) $category_id)
                ->unique()
                ->values()
                ->all(),
            'categories_custom_paths' => '',
            'attributes'              => $product->productToAttributes
                ->map(static fn (ProductToAttribute $attribute): array => [
                    'attribute_id'     => $attribute->attribute_id,
                    'shop_language_id' => $attribute->shop_language_id,
                    'text'             => $attribute->text,
                ])->values()->all(),
            'attribute_source_scope'          => $current_shop_id > 0 ? 'shop' : 'all',
            'attributes_selected_by_language' => $attributes_selected_by_language,
            'attributes_custom_by_language'   => $attributes_custom_by_language,
            'attributes_by_language'          => $attributes_by_language,
            'seo_urls'                        => $seo_urls
                ->map(static fn (SeoUrl $seo_url): array => [
                    'query_key'   => $seo_url->query_key,
                    'query_value' => $seo_url->query_value,
                    'keyword'     => $seo_url->keyword,
                    'sort_order'  => $seo_url->sort_order,
                ])->values()->all(),
            'seo_urls_by_language' => $seo_urls_by_language,
            'specials'             => $product->specials
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

        if ($include_named_categories) {
            $state['categories'] = $product->categories
                ->map(function (Category $category) use ($product_resource_options_service, $current_shop_language_id): array {
                    $category_name_map = $product_resource_options_service->getCategoryLabelsByIds(
                        [(int) $category->id],
                        $current_shop_language_id,
                    );

                    return [
                        'category_id'   => $category->id,
                        'category_name' => (string) ($category_name_map[(int) $category->id] ?? ('#'.$category->id)),
                    ];
                })
                ->values()
                ->all();
        }

        return $state;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, SeoUrl>  $seo_urls
     */
    private function resolveCurrentShopLanguageId(int $current_shop_id, \Illuminate\Database\Eloquent\Collection $seo_urls): int
    {
        $current_shop_language_id = $current_shop_id > 0
            ? ShopLanguage::getDefaultLanguageIdByShopId($current_shop_id)
            : 0;

        if ($current_shop_language_id > 0) {
            return $current_shop_language_id;
        }

        $first_seo_shop_language_id = $seo_urls
            ->pluck('shop_language_id')
            ->filter(static fn ($shop_language_id): bool => is_numeric($shop_language_id) && (int) $shop_language_id > 0)
            ->map(static fn ($shop_language_id): int => (int) $shop_language_id)
            ->first();

        return is_int($first_seo_shop_language_id) ? $first_seo_shop_language_id : 0;
    }
}
