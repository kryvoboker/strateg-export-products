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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProductResourceOptionsService
{
    private const int CACHE_TTL_SECONDS = 600;

    /**
     * @return array<int, string>
     */
    public function getCategoryOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_category_ids = []
    ): array
    {
        $cache_key = "products:options:categories:{$scope}:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($scope, $shop_id, $shop_language_id): array {
            return $this->buildOptionsByIds(
                $this->resolveCategoryIdsByScope($scope, $shop_id),
                $this->getCategoryLabelsByIds($this->resolveCategoryIdsByScope($scope, $shop_id), $shop_language_id),
            );
        });

        return $this->mergeSelectedOptions(
            $cached,
            $this->getCategoryLabelsByIds($selected_category_ids, $shop_language_id),
        );
    }

    /**
     * @return array<int, string>
     */
    public function getAttributeOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_attribute_ids = []
    ): array
    {
        $cache_key = "products:options:attributes:{$scope}:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($scope, $shop_id, $shop_language_id): array {
            return $this->buildOptionsByIds(
                $this->resolveAttributeIdsByScope($scope, $shop_id),
                $this->getAttributeLabelsByIds($this->resolveAttributeIdsByScope($scope, $shop_id), $shop_language_id),
            );
        });

        return $this->mergeSelectedOptions(
            $cached,
            $this->getAttributeLabelsByIds($selected_attribute_ids, $shop_language_id),
        );
    }

    /**
     * @return array<int, string>
     */
    public function getManufacturerOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_manufacturer_ids = []
    ): array
    {
        $cache_key = "products:options:manufacturers:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($shop_id, $shop_language_id): array {
            return $this->buildOptionsByIds(
                $this->resolveManufacturerIdsByShop($shop_id),
                $this->getManufacturerLabelsByIds($this->resolveManufacturerIdsByShop($shop_id), $shop_language_id),
                true,
            );
        });

        return $this->mergeSelectedOptions(
            $cached,
            $this->getManufacturerLabelsByIds($selected_manufacturer_ids, $shop_language_id),
        );
    }

    /**
     * @return array<int, string>
     */
    public function getBrandOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_brand_ids = []
    ): array
    {
        $cache_key = "products:options:brands:shop:{$shop_id}:lang:{$shop_language_id}";

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function () use ($shop_id, $shop_language_id): array {
            return $this->buildOptionsByIds(
                $this->resolveBrandIdsByShop($shop_id),
                $this->getBrandLabelsByIds($this->resolveBrandIdsByShop($shop_id), $shop_language_id),
                true,
            );
        });

        return $this->mergeSelectedOptions(
            $cached,
            $this->getBrandLabelsByIds($selected_brand_ids, $shop_language_id),
        );
    }

    /**
     * @param  list<int>  $category_ids
     * @return array<int, string>
     */
    public function getCategoryLabelsByIds(array $category_ids, int $shop_language_id = 0): array
    {
        return $this->buildOptionsByIds(
            $category_ids,
            $this->resolveReadableDescriptionNames($category_ids, CategoryDescription::class, 'category_id', $shop_language_id),
        );
    }

    /**
     * @param  list<int>  $attribute_ids
     * @return array<int, string>
     */
    public function getAttributeLabelsByIds(array $attribute_ids, int $shop_language_id = 0): array
    {
        return $this->buildOptionsByIds(
            $attribute_ids,
            $this->resolveReadableDescriptionNames($attribute_ids, AttributeDescription::class, 'attribute_id', $shop_language_id),
        );
    }

    /**
     * @param  list<int>  $manufacturer_ids
     * @return array<int, string>
     */
    public function getManufacturerLabelsByIds(array $manufacturer_ids, int $shop_language_id = 0): array
    {
        return $this->buildOptionsByIds(
            $manufacturer_ids,
            $this->resolveReadableDescriptionNames($manufacturer_ids, ManufacturerDescription::class, 'manufacturer_id', $shop_language_id),
            true,
            $manufacturer_ids,
        );
    }

    /**
     * @param  list<int>  $brand_ids
     * @return array<int, string>
     */
    public function getBrandLabelsByIds(array $brand_ids, int $shop_language_id = 0): array
    {
        return $this->buildOptionsByIds(
            $brand_ids,
            $this->resolveReadableDescriptionNames($brand_ids, BrandDescription::class, 'brand_id', $shop_language_id),
            true,
            $brand_ids,
        );
    }

    public function forgetShopScopedCatalogOptionCaches(int $shop_id): void
    {
        if ($shop_id <= 0) {
            return;
        }

        foreach ($this->resolveCatalogOptionCacheKeysForShop($shop_id) as $cache_key) {
            Cache::forget($cache_key);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getCategoryFilterOptions(): array
    {
        $cache_key = 'products:options:filters:categories';

        /** @var array<int, string> $cached */
        $cached = Cache::remember($cache_key, self::CACHE_TTL_SECONDS, function (): array {
            $options = $this->getCategoryLabelsByIds(
                Category::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            );
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
            $options = $this->getAttributeLabelsByIds(
                Attribute::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            );
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
            $options = $this->getManufacturerLabelsByIds(
                Manufacturer::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            );
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
            $options = $this->getBrandLabelsByIds(
                Brand::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            );
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
                ->orderBy('id')
                ->orderBy('is_default')
                ->get();
        });

        return $cached;
    }

    /**
     * @param  list<int>  $attribute_language_ids
     * @return Collection<int, array{id:int, name:string, code:string}>
     */
    public function getAttributeTabContexts(int $shop_id, array $attribute_language_ids = []): Collection
    {
        $shop_languages = $this->getShopLanguages($shop_id);
        if ($shop_languages->isNotEmpty()) {
            return $shop_languages
                ->map(static fn (ShopLanguage $shop_language): array => [
                    'id'   => (int) $shop_language->id,
                    'name' => (string) $shop_language->name,
                    'code' => (string) $shop_language->code,
                ])
                ->values();
        }

        $normalized_language_ids = collect($attribute_language_ids)
            ->map(static fn (int $shop_language_id): int => $shop_language_id)
            ->filter(static fn (int $shop_language_id): bool => $shop_language_id >= 0)
            ->unique()
            ->values()
            ->all();

        $persisted_language_ids = collect($normalized_language_ids)
            ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
            ->values()
            ->all();

        if ($persisted_language_ids !== []) {
            $persisted_shop_languages = ShopLanguage::query()
                ->whereIn('id', $persisted_language_ids)
                ->orderBy('name')
                ->get();

            if ($persisted_shop_languages->isNotEmpty()) {
                return $persisted_shop_languages
                    ->map(static fn (ShopLanguage $shop_language): array => [
                        'id'   => (int) $shop_language->id,
                        'name' => (string) $shop_language->name,
                        'code' => (string) $shop_language->code,
                    ])
                    ->values();
            }
        }

        if (in_array(0, $normalized_language_ids, true)) {
            return collect([[
                'id'   => 0,
                'name' => (string) __('admin/product_imports/batches.product_edit.tabs.attributes'),
                'code' => '',
            ]]);
        }

        return collect();
    }

    /**
     * @return list<string>
     */
    private function resolveCatalogOptionCacheKeysForShop(int $shop_id): array
    {
        $shop_language_ids = ShopLanguage::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($shop_language_id): int => (int) $shop_language_id)
            ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
            ->values()
            ->all();

        $cache_keys = [
            'products:options:filters:categories',
            'products:options:filters:attributes',
            'products:options:filters:manufacturers',
            'products:options:filters:brands',
            "products:options:categories:all:shop:0:lang:0",
            "products:options:categories:shop:shop:{$shop_id}:lang:0",
            'products:options:attributes:all:shop:0:lang:0',
            "products:options:attributes:shop:shop:{$shop_id}:lang:0",
            'products:options:manufacturers:shop:0:lang:0',
            "products:options:manufacturers:shop:{$shop_id}:lang:0",
            'products:options:brands:shop:0:lang:0',
            "products:options:brands:shop:{$shop_id}:lang:0",
        ];

        foreach ($shop_language_ids as $shop_language_id) {
            $cache_keys[] = "products:options:categories:all:shop:0:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:categories:shop:shop:{$shop_id}:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:attributes:all:shop:0:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:attributes:shop:shop:{$shop_id}:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:manufacturers:shop:0:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:manufacturers:shop:{$shop_id}:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:brands:shop:0:lang:{$shop_language_id}";
            $cache_keys[] = "products:options:brands:shop:{$shop_id}:lang:{$shop_language_id}";
        }

        return array_values(array_unique($cache_keys));
    }

    /**
     * @param  list<int>  $entity_ids
     * @return array<int, string>
     */
    private function buildOptionsByIds(
        array $entity_ids,
        array $label_map,
        bool $deduplicate_labels = false,
        array $preserve_entity_ids = []
    ): array {
        $normalized_entity_ids = $this->normalizeEntityIds($entity_ids);
        $preserved_entity_ids  = $this->normalizeEntityIds($preserve_entity_ids);

        $options      = [];
        $seen_labels  = [];
        $preserve_map = array_fill_keys($preserved_entity_ids, true);

        foreach ($normalized_entity_ids as $entity_id) {
            $label = Str::trim((string) ($label_map[$entity_id] ?? ''));
            if ($label === '') {
                $label = '#'.$entity_id;
            }

            $normalized_label = Str::lower($label);
            if (
                $deduplicate_labels === true &&
                $label !== '#'.$entity_id &&
                array_key_exists($normalized_label, $seen_labels) &&
                ! array_key_exists($entity_id, $preserve_map)
            ) {
                continue;
            }

            $options[$entity_id] = $label;
            $seen_labels[$normalized_label] = true;
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $cached_options
     * @param  array<int, string>  $selected_options
     * @return array<int, string>
     */
    private function mergeSelectedOptions(array $cached_options, array $selected_options): array
    {
        if ($selected_options === []) {
            return $cached_options;
        }

        foreach ($selected_options as $entity_id => $label) {
            $cached_options[(int) $entity_id] = $label;
        }

        return $cached_options;
    }

    /**
     * @param  list<int>  $entity_ids
     * @return array<int, string>
     */
    private function resolveReadableDescriptionNames(
        array $entity_ids,
        string $description_model_class,
        string $entity_key,
        int $shop_language_id
    ): array {
        $normalized_entity_ids = $this->normalizeEntityIds($entity_ids);
        if ($normalized_entity_ids === []) {
            return [];
        }

        /*
         * Fallback precedence for pre-bind and partially translated catalog entities:
         * 1. exact requested shop_language_id,
         * 2. import-time NULL language row,
         * 3. any other readable row for the same entity,
         * 4. raw #id only when no readable row exists at all.
         */
        /** @var Collection<int, Model> $description_rows */
        $description_rows = $description_model_class::query()
            ->whereIn($entity_key, $normalized_entity_ids)
            ->select([$entity_key, 'shop_language_id', 'name', 'id'])
            ->orderByRaw(
                $shop_language_id > 0
                    ? 'CASE WHEN shop_language_id = ? THEN 0 WHEN shop_language_id IS NULL THEN 1 ELSE 2 END'
                    : 'CASE WHEN shop_language_id IS NULL THEN 0 ELSE 1 END',
                $shop_language_id > 0 ? [$shop_language_id] : []
            )
            ->orderBy('id')
            ->get();

        $label_map = [];

        foreach ($description_rows as $description_row) {
            $entity_id = (int) ($description_row->getAttribute($entity_key) ?? 0);
            if ($entity_id <= 0 || array_key_exists($entity_id, $label_map)) {
                continue;
            }

            $label = Str::trim((string) ($description_row->getAttribute('name') ?? ''));
            if ($label === '') {
                continue;
            }

            $label_map[$entity_id] = $label;
        }

        return $label_map;
    }

    /**
     * @param  list<int>  $entity_ids
     * @return list<int>
     */
    private function normalizeEntityIds(array $entity_ids): array
    {
        return collect($entity_ids)
            ->map(static fn ($entity_id): int => (int) $entity_id)
            ->filter(static fn (int $entity_id): bool => $entity_id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveCategoryIdsByScope(string $scope, int $shop_id): array
    {
        if ($scope === 'shop' && $shop_id > 0) {
            return CategoryShop::query()
                ->where('shop_id', $shop_id)
                ->pluck('category_id')
                ->map(static fn ($category_id): int => (int) $category_id)
                ->filter(static fn (int $category_id): bool => $category_id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return Category::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($category_id): int => (int) $category_id)
            ->filter(static fn (int $category_id): bool => $category_id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveAttributeIdsByScope(string $scope, int $shop_id): array
    {
        if ($scope === 'shop' && $shop_id > 0) {
            return AttributeShop::query()
                ->where('shop_id', $shop_id)
                ->pluck('attribute_id')
                ->map(static fn ($attribute_id): int => (int) $attribute_id)
                ->filter(static fn (int $attribute_id): bool => $attribute_id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return Attribute::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($attribute_id): int => (int) $attribute_id)
            ->filter(static fn (int $attribute_id): bool => $attribute_id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveManufacturerIdsByShop(int $shop_id): array
    {
        if ($shop_id > 0) {
            return ManufacturerShop::query()
                ->where('shop_id', $shop_id)
                ->pluck('manufacturer_id')
                ->map(static fn ($manufacturer_id): int => (int) $manufacturer_id)
                ->filter(static fn (int $manufacturer_id): bool => $manufacturer_id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return Manufacturer::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($manufacturer_id): int => (int) $manufacturer_id)
            ->filter(static fn (int $manufacturer_id): bool => $manufacturer_id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function resolveBrandIdsByShop(int $shop_id): array
    {
        if ($shop_id > 0) {
            return BrandShop::query()
                ->where('shop_id', $shop_id)
                ->pluck('brand_id')
                ->map(static fn ($brand_id): int => (int) $brand_id)
                ->filter(static fn (int $brand_id): bool => $brand_id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return Brand::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($brand_id): int => (int) $brand_id)
            ->filter(static fn (int $brand_id): bool => $brand_id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
