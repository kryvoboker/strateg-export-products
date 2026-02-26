<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Attributes\AttributeShop;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryShop;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Shops\ShopLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductResourceOptionsService
{
    private const int CACHE_TTL_SECONDS = 600;

    /**
     * @return array<int, string>
     */
    public function getCategoryOptionsByScope(string $scope, int $shop_id = 0, int $shop_language_id = 0): array
    {
        $cache_key = "products:options:categories:{$scope}:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($scope, $shop_id, $shop_language_id): array {
            $default_language_id = $shop_language_id > 0
                ? $shop_language_id
                : (int) (ShopLanguage::query()->orderBy('id')->value('id') ?? 0);

            $descriptions_query = CategoryDescription::query()->orderBy('name')->select('category_id', 'name');

            if ($scope === 'shop' && $shop_id > 0) {
                $shop_category_ids = CategoryShop::query()
                    ->where('shop_id', $shop_id)
                    ->pluck('category_id')
                    ->map(static fn ($category_id): int => (int) $category_id)
                    ->all();

                if ($shop_category_ids === []) {
                    return [];
                }

                $descriptions_query->whereIn('category_id', $shop_category_ids);
            }

            if ($default_language_id > 0) {
                $descriptions_query->where('shop_language_id', $default_language_id);
            }

            $options = $descriptions_query
                ->pluck('name', 'category_id')
                ->toArray();

            if ($options !== []) {
                return $options;
            }

            return Category::query()
                ->orderBy('id')
                ->get()
                ->mapWithKeys(static fn (Category $category): array => [(int) $category->id => '#'.(int) $category->id])
                ->toArray();
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getAttributeOptionsByScope(string $scope, int $shop_id = 0, int $shop_language_id = 0): array
    {
        $cache_key = "products:options:attributes:{$scope}:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($scope, $shop_id, $shop_language_id): array {
            $default_language_id = $shop_language_id > 0
                ? $shop_language_id
                : (int) (ShopLanguage::query()->orderBy('id')->value('id') ?? 0);

            $query = Attribute::query()
                ->with([
                    'descriptions' => function ($query) use ($default_language_id): void {
                        if ($default_language_id > 0) {
                            $query->where('shop_language_id', $default_language_id);
                        }
                    },
                ]);

            if ($scope === 'shop' && $shop_id > 0) {
                $shop_attribute_ids = AttributeShop::query()
                    ->where('shop_id', $shop_id)
                    ->pluck('attribute_id')
                    ->map(static fn ($attribute_id): int => (int) $attribute_id)
                    ->all();

                if ($shop_attribute_ids === []) {
                    return [];
                }

                $query->whereIn('id', $shop_attribute_ids);
            }

            /** @var Collection<int, Attribute> $attributes */
            $attributes = $query
                ->orderBy('id')
                ->get();

            return $attributes
                ->mapWithKeys(static function (Attribute $attribute): array {
                    $name = $attribute->descriptions->first()?->name ?? ('#'.(int) $attribute->id);

                    return [(int) $attribute->id => $name];
                })
                ->toArray();
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getManufacturerOptionsByScope(int $shop_id = 0, int $shop_language_id = 0): array
    {
        $cache_key = "products:options:manufacturers:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($shop_id, $shop_language_id): array {
            $default_language_id = $shop_language_id > 0
                ? $shop_language_id
                : (int) (ShopLanguage::query()
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->limit(1)
                    ->value('id') ?? 0);

            $query = Manufacturer::query()->with([
                'descriptions' => static function ($query) use ($default_language_id): void {
                    if ($default_language_id > 0) {
                        $query->where('shop_language_id', $default_language_id);
                    }
                },
            ]);

            if ($shop_id > 0) {
                $shop_manufacturer_ids = ManufacturerShop::query()
                    ->where('shop_id', $shop_id)
                    ->pluck('manufacturer_id')
                    ->map(static fn ($manufacturer_id): int => (int) $manufacturer_id)
                    ->all();

                if ($shop_manufacturer_ids === []) {
                    return [];
                }

                $query->whereIn('id', $shop_manufacturer_ids);
            }

            return $query->orderBy('id')
                ->get()
                ->mapWithKeys(static function (Manufacturer $manufacturer): array {
                    $name = Str::trim((string) ($manufacturer->descriptions->first()?->name ?? ''));
                    if ($name === '') {
                        $name = '#'.(int) $manufacturer->id;
                    }

                    return [(int) $manufacturer->id => $name];
                })
                ->toArray();
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getBrandOptionsByScope(int $shop_id = 0, int $shop_language_id = 0): array
    {
        $cache_key = "products:options:brands:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($shop_id, $shop_language_id): array {
            $default_language_id = $shop_language_id > 0
                ? $shop_language_id
                : (int) (ShopLanguage::query()->orderBy('id')->value('id') ?? 0);

            $query = Brand::query()->with([
                'descriptions' => static function ($query) use ($default_language_id): void {
                    if ($default_language_id > 0) {
                        $query->where('shop_language_id', $default_language_id);
                    }
                },
            ]);

            if ($shop_id > 0) {
                $shop_brand_ids = BrandShop::query()
                    ->where('shop_id', $shop_id)
                    ->pluck('brand_id')
                    ->map(static fn ($brand_id): int => (int) $brand_id)
                    ->all();

                if ($shop_brand_ids === []) {
                    return [];
                }

                $query->whereIn('id', $shop_brand_ids);
            }

            return $query->orderBy('id')
                ->get()
                ->mapWithKeys(static function (Brand $brand): array {
                    $name = Str::trim((string) ($brand->descriptions->first()?->name ?? ''));
                    if ($name === '') {
                        $name = '#'.(int) $brand->id;
                    }

                    return [(int) $brand->id => $name];
                })
                ->toArray();
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getCategoryFilterOptions(): array
    {
        $cache_key = 'products:options:filters:categories';

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function (): array {
            $categories = Category::query()
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                ])
                ->orderBy('id')
                ->get(['id']);

            $options = [];
            foreach ($categories as $category) {
                $name = Str::trim((string) ($category->descriptions->first()?->name ?? ''));
                if ($name === '') {
                    $name = Str::trim((string) (CategoryDescription::query()
                        ->where('category_id', (int) $category->id)
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id')
                        ->value('name') ?? ''));
                }

                $options[(int) $category->id] = $name !== '' ? $name : '#'.(int) $category->id;
            }

            asort($options);

            return $options;
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getAttributeFilterOptions(): array
    {
        $cache_key = 'products:options:filters:attributes';

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function (): array {
            $attributes = Attribute::query()
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                ])
                ->orderBy('id')
                ->get(['id']);

            $options = [];
            foreach ($attributes as $attribute) {
                $name = Str::trim((string) ($attribute->descriptions->first()?->name ?? ''));
                if ($name === '') {
                    $name = Str::trim((string) (AttributeDescription::query()
                        ->where('attribute_id', (int) $attribute->id)
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id')
                        ->value('name') ?? ''));
                }

                $options[(int) $attribute->id] = $name !== '' ? $name : '#'.(int) $attribute->id;
            }

            asort($options);

            return $options;
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getManufacturerFilterOptions(): array
    {
        $cache_key = 'products:options:filters:manufacturers';

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function (): array {
            $manufacturers = Manufacturer::query()
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                ])
                ->orderBy('id')
                ->get(['id']);

            $options = [];
            foreach ($manufacturers as $manufacturer) {
                $name = Str::trim((string) ($manufacturer->descriptions->first()?->name ?? ''));
                if ($name === '') {
                    $name = Str::trim((string) (ManufacturerDescription::query()
                        ->where('manufacturer_id', (int) $manufacturer->id)
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id')
                        ->value('name') ?? ''));
                }

                $options[(int) $manufacturer->id] = $name !== '' ? $name : '#'.(int) $manufacturer->id;
            }

            asort($options);

            return $options;
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getBrandFilterOptions(): array
    {
        $cache_key = 'products:options:filters:brands';

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function (): array {
            $brands = Brand::query()
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                ])
                ->orderBy('id')
                ->get(['id']);

            $options = [];
            foreach ($brands as $brand) {
                $name = Str::trim((string) ($brand->descriptions->first()?->name ?? ''));
                if ($name === '') {
                    $name = Str::trim((string) (BrandDescription::query()
                        ->where('brand_id', (int) $brand->id)
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id')
                        ->value('name') ?? ''));
                }

                $options[(int) $brand->id] = $name !== '' ? $name : '#'.(int) $brand->id;
            }

            asort($options);

            return $options;
        });

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public function getShopLanguageOptions(int $shop_id): array
    {
        if ($shop_id <= 0) {
            return [];
        }

        $cache_key = "products:options:shop_languages:{$shop_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, static function () use ($shop_id): array {
            return ShopLanguage::query()
                ->where('shop_id', $shop_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        });

        return $cached;
    }

    /**
     * @return Collection<int, ShopLanguage>
     */
    public function getShopLanguages(int $shop_id): Collection
    {
        if ($shop_id <= 0) {
            return collect();
        }

        $cache_key = "products:options:shop_languages:rows:{$shop_id}";

        /** @var Collection<int, ShopLanguage> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, static function () use ($shop_id): Collection {
            return ShopLanguage::query()
                ->where('shop_id', $shop_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });

        return $cached;
    }
}
