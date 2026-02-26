<?php

declare(strict_types=1);

namespace App\Services\Products\Payload;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Attributes\AttributeShop;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryShop;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Products\Product;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductPayloadBuilderService
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(Product $product, int $shop_id, array $options = []): array
    {
        $include_product_binding_fields = (bool) ($options['include_product_binding_fields'] ?? true);
        $update_directives              = Arr::get($options, 'update_directives', []);
        if (! is_array($update_directives)) {
            $update_directives = [];
        }

        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes.attribute',
            'productToManufacturerBrand.manufacturer.descriptions',
            'productToManufacturerBrand.brand.descriptions',
            'specials',
            'discounts',
        ]);

        $shop_language_context   = $this->resolveShopLanguageContext($shop_id);
        $shop_language_ids       = $shop_language_context['ids'];
        $shop_language_map_by_id = $shop_language_context['map_by_id'];

        $seo_urls = SeoUrl::getForSeoableAndLanguageIds(Product::class, (int) $product->id, $shop_language_ids)
            ->map(static function (SeoUrl $seo_url) use ($shop_language_map_by_id): array {
                $shop_language_id = $seo_url->shop_language_id !== null ? (int) $seo_url->shop_language_id : null;

                return [
                    'shop_language_id'   => $seo_url->shop_language_id,
                    'shop_language_code' => $shop_language_id !== null
                        ? Arr::get($shop_language_map_by_id, $shop_language_id.'.code')
                        : null,
                    'query_value' => $seo_url->query_value,
                    'keyword'     => $seo_url->keyword,
                    'sort_order'  => $seo_url->sort_order,
                ];
            })
            ->values()
            ->all();

        $filtered_categories = $product->categories
            ->filter(fn ($category): bool => $this->isEntityInShopScope((int) ($category->shop_id ?? 0), $shop_id))
            ->values();

        $filtered_product_attributes = $product->productToAttributes
            ->filter(function ($product_to_attribute) use ($shop_id): bool {
                $attribute = $product_to_attribute->attribute;
                if (! $attribute instanceof Attribute) {
                    return true;
                }

                return $this->isEntityInShopScope((int) ($attribute->shop_id ?? 0), $shop_id);
            })
            ->values();

        $manufacturer = $product->productToManufacturerBrand?->manufacturer;
        if ($manufacturer instanceof Manufacturer
            && ! $this->isEntityInShopScope((int) ($manufacturer->shop_id ?? 0), $shop_id)) {
            $manufacturer = null;
        }

        $brand = $product->productToManufacturerBrand?->brand;
        if ($brand instanceof Brand
            && ! $this->isEntityInShopScope((int) ($brand->shop_id ?? 0), $shop_id)) {
            $brand = null;
        }

        $external_product_id = ProductShop::resolveExternalProductId((int) $product->id, $shop_id);

        $category_external_id_map = CategoryShop::resolveExternalIdMapByCategoryIds(
            $shop_id,
            $filtered_categories->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        );

        $attribute_external_id_map = AttributeShop::resolveExternalIdMapByAttributeIds(
            $shop_id,
            $filtered_product_attributes->pluck('attribute_id')->map(static fn ($id): int => (int) $id)->all()
        );

        $external_manufacturer_id = $manufacturer instanceof Manufacturer
            ? ManufacturerShop::resolveExternalManufacturerId((int) $manufacturer->id, $shop_id)
            : 0;

        $external_brand_id = $brand instanceof Brand
            ? BrandShop::resolveExternalBrandId((int) $brand->id, $shop_id)
            : 0;

        $attribute_pairs = [];
        foreach ($filtered_product_attributes as $product_to_attribute) {
            $attribute_id     = (int) ($product_to_attribute->attribute_id ?? 0);
            $shop_language_id = (int) ($product_to_attribute->shop_language_id ?? 0);
            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $attribute_pairs[] = [
                'attribute_id'     => $attribute_id,
                'shop_language_id' => $shop_language_id,
            ];
        }

        $attribute_description_map = AttributeDescription::getNameMapByAttributeLanguagePairs($attribute_pairs);

        $manufacturer_descriptions = $manufacturer instanceof Manufacturer
            ? $manufacturer->descriptions
                ->filter(static fn ($description): bool => $shop_language_ids === []
                    || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                ->map(static function ($description) use ($shop_language_map_by_id): array {
                    $shop_language_id = (int) ($description->shop_language_id ?? 0);

                    return [
                        'shop_language_id'   => $shop_language_id > 0 ? $shop_language_id : null,
                        'shop_language_code' => $shop_language_id > 0
                            ? Arr::get($shop_language_map_by_id, $shop_language_id.'.code')
                            : null,
                        'name' => $description->name,
                    ];
                })
                ->values()
                ->all()
            : [];

        $brand_descriptions = $brand instanceof Brand
            ? $brand->descriptions
                ->filter(static fn ($description): bool => $shop_language_ids === []
                    || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                ->map(static function ($description) use ($shop_language_map_by_id): array {
                    $shop_language_id = (int) ($description->shop_language_id ?? 0);

                    return [
                        'shop_language_id'   => $shop_language_id > 0 ? $shop_language_id : null,
                        'shop_language_code' => $shop_language_id > 0
                            ? Arr::get($shop_language_map_by_id, $shop_language_id.'.code')
                            : null,
                        'name' => $description->name,
                    ];
                })
                ->values()
                ->all()
            : [];

        $specials_payload = $product->specials
            ->map(fn (ProductSpecial $special): array => [
                'user_group_id' => $special->user_group_id,
                'price'         => $special->price,
                'priority'      => $special->priority,
                'date_start'    => $this->normalizeDateTimeValue($special->date_start),
                'date_end'      => $this->normalizeDateTimeValue($special->date_end),
            ])->values()->all();

        $discounts_payload = $product->discounts
            ->map(fn (ProductDiscount $discount): array => [
                'user_group_id' => $discount->user_group_id,
                'quantity'      => $discount->quantity,
                'price'         => $discount->price,
                'priority'      => $discount->priority,
                'date_start'    => $this->normalizeDateTimeValue($discount->date_start),
                'date_end'      => $this->normalizeDateTimeValue($discount->date_end),
            ])->values()->all();

        $product_payload = [
            'id'                  => (int) $product->id,
            'external_product_id' => $external_product_id > 0 ? $external_product_id : null,
            'model'               => $product->model,
            'sku'                 => $product->sku,
            'ean'                 => $product->ean,
            'quantity'            => $product->quantity,
            'minimum'             => $product->minimum,
            'image'               => $product->image,
            'price'               => $product->price,
            'is_active'           => (bool) $product->is_active,
            'date_available'      => $this->normalizeDateTimeValue($product->date_available),
            'date_added'          => $this->normalizeDateTimeValue($product->date_added),
        ];

        if ($include_product_binding_fields) {
            $product_payload = [
                ...$product_payload,
                'manufacturer_id' => $manufacturer?->id,
                'manufacturer'    => $manufacturer?->manufacturer_name,
                'brand_id'        => $brand?->id,
                'brand'           => $brand?->brand_name,
            ];
        }

        $payload = [
            'shop_id'        => $shop_id,
            'shop_languages' => array_values($shop_language_map_by_id),
            'product'        => $product_payload,
            'descriptions'   => $product->descriptions
                ->filter(static fn ($description): bool => $shop_language_ids === []
                    || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                ->map(static function ($description) use ($shop_language_map_by_id): array {
                    $shop_language_id = (int) $description->shop_language_id;

                    return [
                        'shop_language_id'   => $shop_language_id,
                        'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                        'name'               => $description->name,
                        'description'        => $description->description,
                        'meta_title'         => $description->meta_title,
                        'meta_description'   => $description->meta_description,
                        'meta_keywords'      => $description->meta_keywords,
                    ];
                })->values()->all(),
            'images' => $product->images
                ->map(static fn ($image): array => [
                    'image'      => $image->image,
                    'sort_order' => $image->sort_order,
                ])->values()->all(),
            'categories' => $filtered_categories
                ->map(static function (Category $category) use ($shop_language_ids, $shop_language_map_by_id, $category_external_id_map): array {
                    $descriptions = $category->descriptions
                        ->filter(static fn ($description): bool => $shop_language_ids === []
                            || in_array((int) $description->shop_language_id, $shop_language_ids, true))
                        ->map(static function ($description) use ($shop_language_map_by_id): array {
                            $shop_language_id = (int) $description->shop_language_id;

                            return [
                                'shop_language_id'   => $shop_language_id,
                                'shop_language_code' => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                                'name'               => $description->name,
                                'description'        => $description->description,
                                'h1_title'           => $description->h1_title,
                                'meta_title'         => $description->meta_title,
                                'meta_description'   => $description->meta_description,
                                'meta_keywords'      => $description->meta_keywords,
                            ];
                        })
                        ->values()
                        ->all();

                    return [
                        'id'                   => $category->id,
                        'external_category_id' => $category_external_id_map[(int) $category->id] ?? null,
                        'shop_id'              => $category->shop_id,
                        'family_ulid'          => $category->family_ulid,
                        'parent_id'            => $category->parent_id,
                        'name'                 => Arr::get($descriptions, '0.name'),
                        'descriptions'         => $descriptions,
                    ];
                })->values()->all(),
            'attributes' => $filtered_product_attributes
                ->filter(static fn (ProductToAttribute $attribute): bool => $shop_language_ids === []
                    || in_array((int) $attribute->shop_language_id, $shop_language_ids, true))
                ->map(static function (ProductToAttribute $attribute) use ($attribute_description_map, $shop_language_map_by_id, $attribute_external_id_map): array {
                    $shop_language_id = (int) $attribute->shop_language_id;
                    $attribute_id     = (int) $attribute->attribute_id;
                    $attribute_model  = $attribute->attribute;

                    return [
                        'attribute_id'          => $attribute_id,
                        'external_attribute_id' => $attribute_external_id_map[$attribute_id] ?? null,
                        'attribute_shop_id'     => $attribute_model?->shop_id,
                        'attribute_family_ulid' => $attribute_model?->family_ulid,
                        'attribute_name'        => $attribute_description_map[$attribute_id.':'.$shop_language_id] ?? null,
                        'shop_language_id'      => $shop_language_id,
                        'shop_language_code'    => Arr::get($shop_language_map_by_id, $shop_language_id.'.code'),
                        'text'                  => $attribute->text,
                    ];
                })->values()->all(),
            'manufacturer_brand' => [
                'manufacturer_id'           => $manufacturer?->id,
                'external_manufacturer_id'  => $external_manufacturer_id > 0 ? $external_manufacturer_id : null,
                'manufacturer_shop_id'      => $manufacturer?->shop_id,
                'manufacturer_family_ulid'  => $manufacturer?->family_ulid,
                'manufacturer'              => $manufacturer?->manufacturer_name,
                'manufacturer_descriptions' => $manufacturer_descriptions,
                'brand_id'                  => $brand?->id,
                'external_brand_id'         => $external_brand_id > 0 ? $external_brand_id : null,
                'brand_shop_id'             => $brand?->shop_id,
                'brand_family_ulid'         => $brand?->family_ulid,
                'brand'                     => $brand?->brand_name,
                'brand_descriptions'        => $brand_descriptions,
            ],
            'seo_urls'  => $seo_urls,
            'specials'  => $specials_payload,
            'discounts' => $discounts_payload,
        ];

        if ($update_directives !== []) {
            $payload['update_directives'] = $update_directives;
        }

        return $payload;
    }

    private function isEntityInShopScope(int $entity_shop_id, int $shop_id): bool
    {
        if ($shop_id <= 0) {
            return true;
        }

        return $entity_shop_id === $shop_id;
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return (string) $value->toDateTimeString();
        }

        $clean_value = trim((string) $value);

        return $clean_value !== '' ? $clean_value : null;
    }

    /**
     * @return array{
     *     ids:list<int>,
     *     map_by_id:array<int, array{id:int,code:string,name:string|null}>
     * }
     */
    private function resolveShopLanguageContext(int $shop_id): array
    {
        if ($shop_id <= 0) {
            return [
                'ids'       => [],
                'map_by_id' => [],
            ];
        }

        $cache_key = 'product_payload_builder:shop_languages:'.$shop_id;

        /** @var array{ids:list<int>,map_by_id:array<int, array{id:int,code:string,name:string|null}>} $language_context */
        $language_context = Cache::remember($cache_key, now()->addMinutes(15), static function () use ($shop_id): array {
            $shop_languages = ShopLanguage::getActiveByShopId($shop_id);

            $shop_language_ids = $shop_languages
                ->pluck('id')
                ->map(static fn ($shop_language_id): int => (int) $shop_language_id)
                ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
                ->values()
                ->all();

            $shop_language_map_by_id = $shop_languages
                ->mapWithKeys(static fn (ShopLanguage $shop_language): array => [
                    (int) $shop_language->id => [
                        'id'   => (int) $shop_language->id,
                        'code' => Str::lower(Str::trim((string) $shop_language->code)),
                        'name' => $shop_language->name,
                    ],
                ])
                ->toArray();

            return [
                'ids'       => $shop_language_ids,
                'map_by_id' => $shop_language_map_by_id,
            ];
        });

        return $language_context;
    }
}
