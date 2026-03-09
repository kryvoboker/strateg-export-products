<?php

declare(strict_types=1);

namespace App\Supports\Services\Products;

use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Attributes\AttributeShop;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Categories\CategoryShop;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
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
use App\Services\Products\ProductResourceOptionsService;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Ai\AiTranslationService;
use App\Supports\Services\Products\Traits\NormalizesProductServiceInput;
use App\Supports\Services\SeoSlug\ProductSeoKeywordService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductShopBindingService
{
    use NormalizesProductServiceInput;

    private const array EMPTY_CATALOG_SYNC_SUMMARY = [
        'categories_relinked'    => 0,
        'categories_assigned'    => 0,
        'categories_created'     => 0,
        'categories_reused'      => 0,
        'attributes_relinked'    => 0,
        'attributes_assigned'    => 0,
        'attributes_created'     => 0,
        'attributes_reused'      => 0,
        'manufacturer_relinked'  => 0,
        'manufacturers_assigned' => 0,
        'manufacturers_created'  => 0,
        'manufacturers_reused'   => 0,
        'brand_relinked'         => 0,
        'brands_assigned'        => 0,
        'brands_created'         => 0,
        'brands_reused'          => 0,
    ];

    /**
     * @return array{
     *     product_id:int,
     *     bound:int,
     *     duplicated:int,
     *     catalog_sync_summary:array{
     *         categories_relinked:int,
     *         attributes_relinked:int,
     *         manufacturer_relinked:int,
     *         brand_relinked:int
     *     }
     * }
     *
     * @throws Throwable
     */
    public function bindProductToShopAndReturnTargetProduct(
        int $product_id,
        int $target_shop_id,
        int $product_import_batch_id = 0,
        array $source_payload = []
    ): array {
        $source_product = Product::query()->find($product_id);
        if ($source_product === null || $target_shop_id <= 0) {
            return [
                'product_id'           => 0,
                'bound'                => 0,
                'duplicated'           => 0,
                'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
            ];
        }

        $bind_result       = $this->bindProductToShop($source_product, $target_shop_id, $product_import_batch_id);
        $target_product_id = $bind_result['product_id'];
        if ($product_import_batch_id > 0 && $target_product_id > 0) {
            ProductImportItem::ensureBatchProductItem(
                $product_import_batch_id,
                $target_product_id,
                $source_payload,
                ProductImportItemsStatusEnum::SUCCESSED->value
            );
        }

        if ($target_product_id > 0) {
            $this->synchronizeProductTranslationsForShop($target_product_id, $target_shop_id);
            $this->invalidateProductResourceOptionCaches($target_shop_id, $target_product_id);
        }

        return [
            ...$bind_result,
            'product_id' => $target_product_id,
        ];
    }

    /**
     * @param  list<int>  $shop_ids
     * @param  array<string, mixed>  $source_payload
     * @return array{
     *     product_id:int,
     *     shops:list<array{
     *         shop_id:int,
     *         product_id:int,
     *         bound:int,
     *         duplicated:int,
     *         catalog_sync_summary:array<string, int>
     *     }>
     * }
     */
    public function bindProductToShopsAndReturnTargets(
        int $product_id,
        array $shop_ids,
        int $product_import_batch_id = 0,
        array $source_payload = []
    ): array {
        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);
        if ($product_id <= 0 || $normalized_shop_ids === []) {
            return [
                'product_id' => 0,
                'shops'      => [],
            ];
        }

        $shop_results = [];

        foreach ($normalized_shop_ids as $shop_id) {
            Log::channel('daily')->info('Product shop binding stage started', [
                'stage'      => 'core_binding',
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
            ]);

            try {
                $shop_result = $this->bindProductToShopAndReturnTargetProduct(
                    $product_id,
                    $shop_id,
                    $product_import_batch_id,
                    $source_payload
                );

                $shop_results[] = [
                    'shop_id'              => $shop_id,
                    'product_id'           => (int) ($shop_result['product_id'] ?? 0),
                    'bound'                => (int) ($shop_result['bound'] ?? 0),
                    'duplicated'           => (int) ($shop_result['duplicated'] ?? 0),
                    'catalog_sync_summary' => is_array($shop_result['catalog_sync_summary'] ?? null)
                        ? $shop_result['catalog_sync_summary']
                        : self::EMPTY_CATALOG_SYNC_SUMMARY,
                ];

                Log::channel('daily')->info('Product shop binding stage completed', [
                    'stage'      => 'core_binding',
                    'product_id' => $product_id,
                    'shop_id'    => $shop_id,
                    'result'     => $shop_results[array_key_last($shop_results)],
                ]);
            } catch (Throwable $exception) {
                Log::channel('stack')->error('Product shop binding failed for shop', [
                    'product_id' => $product_id,
                    'shop_id'    => $shop_id,
                    'error_msg'  => $exception->getMessage(),
                    'file'       => $exception->getFile(),
                    'line'       => $exception->getLine(),
                ]);
            }
        }

        return [
            'product_id' => (int) ($shop_results[array_key_last($shop_results)]['product_id'] ?? 0),
            'shops'      => $shop_results,
        ];
    }

    /**
     * @param  list<int>  $product_ids
     * @param  list<int>  $shop_ids
     * @return array<string, int>
     */
    public function bindProductsToShops(array $product_ids, array $shop_ids, int $product_import_batch_id = 0): array
    {
        $normalized_product_ids = collect($product_ids)
            ->map(static fn ($product_id): int => (int) $product_id)
            ->filter(static fn (int $product_id): bool => $product_id > 0)
            ->unique()
            ->values()
            ->all();

        $normalized_shop_ids = $this->normalizeShopIds($shop_ids);

        $summary = [
            'products_total'      => count($normalized_product_ids),
            'products_bound'      => 0,
            'products_duplicated' => 0,
            'products_skipped'    => 0,
            'errors'              => 0,
            ...self::EMPTY_CATALOG_SYNC_SUMMARY,
        ];

        foreach ($normalized_product_ids as $product_id) {
            try {
                $source_product = Product::query()->find($product_id);
                if ($source_product === null) {
                    $summary['products_skipped']++;

                    continue;
                }

                $bind_results = $this->bindProductToShopsAndReturnTargets(
                    $product_id,
                    $normalized_shop_ids,
                    $product_import_batch_id
                );

                foreach ($bind_results['shops'] as $bind_result) {
                    $summary['products_bound'] += (int) ($bind_result['bound'] ?? 0);
                    $summary['products_duplicated'] += (int) ($bind_result['duplicated'] ?? 0);
                    foreach (self::EMPTY_CATALOG_SYNC_SUMMARY as $summary_key => $default_value) {
                        $summary[$summary_key] += (int) (($bind_result['catalog_sync_summary'][$summary_key] ?? $default_value));
                    }
                }
            } catch (Throwable) {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *     product_id:int,
     *     bound:int,
     *     duplicated:int,
     *     catalog_sync_summary:array{
     *         categories_relinked:int,
     *         attributes_relinked:int,
     *         manufacturer_relinked:int,
     *         brand_relinked:int
     *     }
     * }
     *
     * @throws Throwable
     */
    private function bindProductToShop(Product $source_product, int $target_shop_id, int $product_import_batch_id = 0): array
    {
        if ($target_shop_id <= 0) {
            return [
                'product_id'           => (int) $source_product->id,
                'bound'                => 0,
                'duplicated'           => 0,
                'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
            ];
        }

        return $this->runBindingWithFamilyLock(
            $source_product,
            $target_shop_id,
            fn (): array => DB::transaction(function () use ($source_product, $target_shop_id, $product_import_batch_id): array {
                $shop_name         = (string) (Shop::query()->whereKey($target_shop_id)->value('name') ?? $target_shop_id);
                $resolved_batch_id = $this->resolveProductImportBatchId($source_product, $product_import_batch_id);
                if ($resolved_batch_id <= 0) {
                    return [
                        'product_id'           => (int) $source_product->id,
                        'bound'                => 0,
                        'duplicated'           => 0,
                        'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
                    ];
                }

                $family_ulid                     = $this->ensureProductFamilyUlid($source_product);
                $already_bound_family_product_id = $this->resolveAlreadyBoundProductIdByFamilyUlid($family_ulid, $target_shop_id);
                if ($already_bound_family_product_id > 0) {
                    $already_bound_product = Product::query()->find($already_bound_family_product_id);
                    if ($already_bound_product instanceof Product && (string) ($already_bound_product->marked_to_shop ?? '') !== $shop_name) {
                        $already_bound_product->update([
                            'marked_to_shop' => $shop_name,
                        ]);
                    }

                    ProductShop::query()
                        ->where('product_id', $already_bound_family_product_id)
                        ->where('shop_id', $target_shop_id)
                        ->update(['product_import_batch_id' => $resolved_batch_id]);

                    return [
                        'product_id'           => $already_bound_family_product_id,
                        'bound'                => 0,
                        'duplicated'           => 0,
                        'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
                    ];
                }

                $already_bound = ProductShop::query()
                    ->where('product_id', $source_product->id)
                    ->where('shop_id', $target_shop_id)
                    ->first();

                if ($already_bound instanceof ProductShop) {
                    if ((int) $already_bound->product_import_batch_id !== $resolved_batch_id) {
                        $already_bound->update([
                            'product_import_batch_id' => $resolved_batch_id,
                        ]);
                    }

                    if ((string) ($source_product->marked_to_shop ?? '') !== $shop_name) {
                        $source_product->update([
                            'marked_to_shop' => $shop_name,
                        ]);
                    }

                    return [
                        'product_id'           => (int) $source_product->id,
                        'bound'                => 0,
                        'duplicated'           => 0,
                        'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
                    ];
                }

                $has_any_shop_binding = ProductShop::query()
                    ->where('product_id', $source_product->id)
                    ->exists();

                if (! $has_any_shop_binding) {
                    ProductShop::query()->firstOrCreate([
                        'product_id' => $source_product->id,
                        'shop_id'    => $target_shop_id,
                    ], [
                        'product_import_batch_id' => $resolved_batch_id,
                        'external_product_id'     => null,
                    ]);

                    $source_product->update([
                        'marked_to_shop' => $shop_name,
                    ]);

                    $catalog_sync_summary = $this->ensureShopScopedCatalogEntitiesForProduct($source_product->id, $target_shop_id);
                    $this->ensureShopLinksForProduct($source_product->id, $target_shop_id);

                    return [
                        'product_id'           => (int) $source_product->id,
                        'bound'                => 1,
                        'duplicated'           => 0,
                        'catalog_sync_summary' => $catalog_sync_summary,
                    ];
                }

                $duplicated_product   = $this->duplicateProductWithRelationsForShop($source_product, $target_shop_id, $shop_name, $resolved_batch_id);
                $catalog_sync_summary = $this->ensureShopScopedCatalogEntitiesForProduct((int) $duplicated_product->id, $target_shop_id);
                $this->ensureShopLinksForProduct($duplicated_product->id, $target_shop_id);

                return [
                    'product_id'           => (int) $duplicated_product->id,
                    'bound'                => 1,
                    'duplicated'           => 1,
                    'catalog_sync_summary' => $catalog_sync_summary,
                ];
            }),
        );
    }

    /**
     * @param callable():array{
     *     product_id:int,
     *     bound:int,
     *     duplicated:int,
     *     catalog_sync_summary:array{
     *         categories_relinked:int,
     *         categories_created:int,
     *         categories_reused:int,
     *         attributes_relinked:int,
     *         attributes_created:int,
     *         attributes_reused:int,
     *         manufacturer_relinked:int,
     *         manufacturers_created:int,
     *         manufacturers_reused:int,
     *         brand_relinked:int,
     *         brands_created:int,
     *         brands_reused:int
     *     }
     * } $binding_callback
     * @return array{
     *     product_id:int,
     *     bound:int,
     *     duplicated:int,
     *     catalog_sync_summary:array{
     *         categories_relinked:int,
     *         categories_created:int,
     *         categories_reused:int,
     *         attributes_relinked:int,
     *         attributes_created:int,
     *         attributes_reused:int,
     *         manufacturer_relinked:int,
     *         manufacturers_created:int,
     *         manufacturers_reused:int,
     *         brand_relinked:int,
     *         brands_created:int,
     *         brands_reused:int
     *     }
     * }
     */
    private function runBindingWithFamilyLock(Product $source_product, int $target_shop_id, callable $binding_callback): array
    {
        $binding_lock_key = $this->resolveBindingLockKey($source_product, $target_shop_id);
        if ($binding_lock_key === null) {
            return $binding_callback();
        }

        try {
            return cache()
                ->lock($binding_lock_key, 15)
                ->block(10, function () use ($binding_callback): array {
                    return $binding_callback();
                });
        } catch (LockTimeoutException $exception) {
            Log::channel('stack')->error($exception->getMessage(), [
                'product_id'           => $source_product->id,
                'shop_id'              => $target_shop_id,
                'bound'                => 0,
                'duplicated'           => 0,
                'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
                'line'                 => $exception->getLine(),
                'file'                 => $exception->getFile(),
            ]);

            return [
                'product_id'           => (int) $source_product->id,
                'bound'                => 0,
                'duplicated'           => 0,
                'catalog_sync_summary' => self::EMPTY_CATALOG_SYNC_SUMMARY,
            ];
        }
    }

    private function resolveBindingLockKey(Product $source_product, int $shop_id): ?string
    {
        if ($shop_id <= 0) {
            return null;
        }

        $app_container = Container::getInstance();
        if (! $app_container instanceof Container || ! $app_container->bound('cache')) {
            return null;
        }

        $family_ulid = $this->ensureProductFamilyUlid($source_product);
        if ($family_ulid === '') {
            return null;
        }

        return 'product-shop-binding:shop:'.$shop_id.':family:'.$family_ulid;
    }

    private function resolveAlreadyBoundProductIdByFamilyUlid(string $family_ulid, int $shop_id): int
    {
        return ProductShop::resolveLatestBoundProductIdByFamilyUlidAndShop($family_ulid, $shop_id);
    }

    private function ensureProductFamilyUlid(Product $product): string
    {
        $family_ulid = Str::trim((string) ($product->family_ulid ?? ''));
        if ($family_ulid !== '') {
            return $family_ulid;
        }

        $source_ulid            = '';
        $product_import_item_id = (int) ($product->product_import_item_id ?? 0);

        if ($product_import_item_id > 0) {
            $source_ulid = ProductImportItem::resolveUlidById($product_import_item_id);
        }

        $family_ulid = $source_ulid !== '' ? $source_ulid : (string) Str::ulid();
        $product->update([
            'family_ulid' => $family_ulid,
        ]);

        return $family_ulid;
    }

    private function duplicateProductWithRelationsForShop(
        Product $source_product,
        int $target_shop_id,
        string $shop_name,
        int $product_import_batch_id
    ): Product {
        if ($product_import_batch_id <= 0) {
            throw new RuntimeException('Invalid product_import_batch_id for product duplication');
        }

        $duplicated_import_item = ProductImportItem::createDraftItemForBatch($product_import_batch_id, [
            'source_product_id' => (int) $source_product->id,
            'source_type'       => 'shop_binding_duplication',
            'shop_id'           => $target_shop_id,
        ]);

        $family_ulid = $this->ensureProductFamilyUlid($source_product);

        $duplicated_product = Product::query()->create([
            'product_import_item_id' => (int) $duplicated_import_item->id,
            'family_ulid'            => $family_ulid,
            'marked_to_shop'         => $shop_name,
            'model'                  => $source_product->model,
            'sku'                    => $source_product->sku,
            'ean'                    => $source_product->ean,
            'quantity'               => $source_product->quantity,
            'minimum'                => $source_product->minimum,
            'image'                  => $source_product->image,
            'price'                  => $source_product->price,
            'is_active'              => (bool) $source_product->is_active,
            'date_available'         => $source_product->date_available,
            'date_added'             => $source_product->date_added,
        ]);

        $duplicated_import_item->linkProduct((int) $duplicated_product->id);

        $target_shop = Shop::query()->find($target_shop_id);

        if (! $target_shop instanceof Shop) {
            throw new RuntimeException('Target shop not found for ID: '.$target_shop_id);
        }

        $source_shop = Shop::query()->find((int) ($source_product->productShops()->value('shop_id') ?? 0));

        if (! $source_shop instanceof Shop) {
            throw new RuntimeException('Source shop not found for product ID: '.$source_product->id);
        }

        $source_default_shop_language_id = $source_shop
            ->shopLanguage()
            ->where('shop_id', $source_shop->id)
            ->where('is_default', true)
            ->value('id') ?? 0;

        if ($source_default_shop_language_id <= 0) {
            throw new RuntimeException('Source default language not found for source shop ID: '.$source_shop->id);
        }

        $source_product_description = $source_product->descriptions()->where('shop_language_id', $source_default_shop_language_id)->first();

        $target_shop->shopLanguage()->each(function (ShopLanguage $shop_language) use ($duplicated_product, $source_product_description) {
            // Texts must be NULL if we want that the app translating them
            $is_default_language = $shop_language->is_default === true;

            ProductDescription::create([
                'product_id'       => (int) $duplicated_product->id,
                'shop_language_id' => $shop_language->id,
                'name'             => $is_default_language ? $source_product_description->name : null,
                'description'      => $is_default_language ? $source_product_description->description : null,
                'meta_title'       => $is_default_language ? $source_product_description->meta_title : null,
                'meta_description' => $is_default_language ? $source_product_description->meta_description : null,
                'meta_keywords'    => $is_default_language ? $source_product_description->meta_keywords : null,
            ]);

            return true;
        });

        ProductImage::query()
            ->where('product_id', $source_product->id)
            ->orderBy('id')
            ->get()
            ->each(function (ProductImage $image) use ($duplicated_product): void {
                ProductImage::query()->create([
                    'product_id' => $duplicated_product->id,
                    'image'      => $image->image,
                    'sort_order' => $image->sort_order,
                ]);
            });

        CategoryProduct::query()
            ->where('product_id', $source_product->id)
            ->orderBy('id')
            ->get()
            ->each(function (CategoryProduct $category_product) use ($duplicated_product): void {
                CategoryProduct::query()->create([
                    'product_id'  => $duplicated_product->id,
                    'category_id' => $category_product->category_id,
                ]);
            });

        $source_product_to_attributes = $source_product->productToAttributes()->where('shop_language_id', $source_default_shop_language_id)->get();

        $target_shop->shopLanguage()->each(function (ShopLanguage $shop_language) use ($duplicated_product, $source_product_to_attributes) {
            /**
             * Keep source attribute text only for target default language.
             * For non-default languages we store NULL and let translation pipeline fill values.
             */
            $is_default_language = $shop_language->is_default === true;

            $source_product_to_attributes->each(function (ProductToAttribute $product_to_attribute) use ($duplicated_product, $shop_language, $is_default_language) {
                ProductToAttribute::create([
                    'product_id'       => (int) $duplicated_product->id,
                    'attribute_id'     => (int) $product_to_attribute->attribute_id,
                    'shop_language_id' => (int) $shop_language->id,
                    'text'             => $is_default_language ? (string) $product_to_attribute->text : null,
                ]);

                return true;
            });
        });

        $source_manufacturer_brand_binding = ProductToManufacturerBrand::query()
            ->where('product_id', $source_product->id)
            ->first();

        if ($source_manufacturer_brand_binding instanceof ProductToManufacturerBrand) {
            ProductToManufacturerBrand::query()->create([
                'product_id'      => (int) $duplicated_product->id,
                'manufacturer_id' => $source_manufacturer_brand_binding->manufacturer_id,
                'brand_id'        => $source_manufacturer_brand_binding->brand_id,
            ]);
        }

        $source_product_name  = (string) ($source_product_description->name ?? '');
        $source_language_code = $source_shop->shopLanguage()
            ->where('id', $source_default_shop_language_id)
            ->value('code') ?? '';

        if ($source_product_name !== '' && $source_language_code !== '') {
            $sort_order = 1;

            $target_shop->shopLanguage()->each(function (ShopLanguage $shop_language) use ($duplicated_product, &$sort_order, $source_product_name, $source_language_code) {
                $source_product_name = $this->translateText(
                    (int) $duplicated_product->id,
                    $source_product_name,
                    $source_language_code,
                    $shop_language->code,
                    '',
                    'productName'
                );

                $keyword = $this->generateSeoKeywordForLanguage($source_product_name, $shop_language->code);

                $duplicated_product->seoUrl()->updateOrCreate(
                    [
                        'shop_language_id' => (int) $shop_language->id,
                        'query_value'      => (string) $duplicated_product->id,
                    ],
                    [
                        'query_key'   => null,
                        'query_value' => (string) $duplicated_product->id,
                        'keyword'     => $keyword,
                        'sort_order'  => $sort_order,
                    ]
                );

                /*SeoUrl::query()->updateOrCreate([
                    'seoable_type'     => Product::class,
                    'seoable_id'       => (int)$duplicated_product->id,
                    'shop_language_id' => (int)$shop_language->id,
                    'query_value'      => (string)$duplicated_product->id,
                ], [
                    'query_key'  => null,
                    'keyword'    => $keyword,
                    'sort_order' => $sort_order,
                ]);*/

                $sort_order++;

                return true;
            });
        }

        ProductDiscount::query()
            ->where('product_id', $source_product->id)
            ->orderBy('id')
            ->get()
            ->each(function (ProductDiscount $discount) use ($duplicated_product): void {
                ProductDiscount::query()->create([
                    'product_id'    => $duplicated_product->id,
                    'user_group_id' => $discount->user_group_id,
                    'quantity'      => $discount->quantity,
                    'price'         => $discount->price,
                    'priority'      => $discount->priority,
                    'date_start'    => $discount->date_start,
                    'date_end'      => $discount->date_end,
                ]);
            });

        ProductSpecial::query()
            ->where('product_id', $source_product->id)
            ->orderBy('id')
            ->get()
            ->each(function (ProductSpecial $special) use ($duplicated_product): void {
                ProductSpecial::query()->create([
                    'product_id'    => $duplicated_product->id,
                    'user_group_id' => $special->user_group_id,
                    'price'         => $special->price,
                    'priority'      => $special->priority,
                    'date_start'    => $special->date_start,
                    'date_end'      => $special->date_end,
                ]);
            });

        ProductShop::query()->create([
            'product_import_batch_id' => $product_import_batch_id,
            'product_id'              => $duplicated_product->id,
            'shop_id'                 => $target_shop_id,
            'external_product_id'     => null,
        ]);

        return $duplicated_product;
    }

    private function ensureShopScopedCatalogEntitiesForProduct(int $product_id, int $target_shop_id): array
    {
        if ($product_id <= 0 || $target_shop_id <= 0) {
            return self::EMPTY_CATALOG_SYNC_SUMMARY;
        }

        if (! $this->hasProductShopBinding($product_id, $target_shop_id)) {
            Log::channel('stack')->warning('Catalog entity relink skipped because product is not bound to shop', [
                'product_id' => $product_id,
                'shop_id'    => $target_shop_id,
            ]);

            return self::EMPTY_CATALOG_SYNC_SUMMARY;
        }

        $category_summary           = $this->relinkProductCategoriesToShopScope($product_id, $target_shop_id);
        $attribute_summary          = $this->relinkProductAttributesToShopScope($product_id, $target_shop_id);
        $manufacturer_brand_summary = $this->relinkProductManufacturerBrandToShopScope($product_id, $target_shop_id);

        return [
            'categories_relinked'    => $category_summary['relinked'],
            'categories_assigned'    => $category_summary['assigned'],
            'categories_created'     => $category_summary['created'],
            'categories_reused'      => $category_summary['reused'],
            'attributes_relinked'    => $attribute_summary['relinked'],
            'attributes_assigned'    => $attribute_summary['assigned'],
            'attributes_created'     => $attribute_summary['created'],
            'attributes_reused'      => $attribute_summary['reused'],
            'manufacturer_relinked'  => $manufacturer_brand_summary['manufacturer_relinked'],
            'manufacturers_assigned' => $manufacturer_brand_summary['manufacturers_assigned'],
            'manufacturers_created'  => $manufacturer_brand_summary['manufacturers_created'],
            'manufacturers_reused'   => $manufacturer_brand_summary['manufacturers_reused'],
            'brand_relinked'         => $manufacturer_brand_summary['brand_relinked'],
            'brands_assigned'        => $manufacturer_brand_summary['brands_assigned'],
            'brands_created'         => $manufacturer_brand_summary['brands_created'],
            'brands_reused'          => $manufacturer_brand_summary['brands_reused'],
        ];
    }

    /**
     * @return array{relinked:int,assigned:int,created:int,reused:int}
     */
    private function relinkProductCategoriesToShopScope(int $product_id, int $target_shop_id): array
    {
        $category_rows = CategoryProduct::query()
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->get();

        $category_map = [];

        $summary = [
            'relinked' => 0,
            'assigned' => 0,
            'created'  => 0,
            'reused'   => 0,
        ];

        foreach ($category_rows as $category_row) {
            $source_category_id = (int) ($category_row->category_id ?? 0);
            if ($source_category_id <= 0) {
                continue;
            }

            $source_category = Category::query()->find($source_category_id);
            if (! $source_category instanceof Category) {
                continue;
            }
            $source_shop_id_before = (int) ($source_category->shop_id ?? 0);

            $source_family_ulid       = Str::trim((string) ($source_category->family_ulid ?? ''));
            $existing_target_category = $source_family_ulid !== ''
                ? Category::findByFamilyAndShop($source_family_ulid, $target_shop_id)
                : null;

            $target_category_id = $this->resolveShopScopedCategoryId($source_category_id, $target_shop_id, $category_map);
            if ($target_category_id <= 0 || $target_category_id === $source_category_id) {
                $assigned_to_shop = $source_shop_id_before <= 0
                    && (int) (Category::query()->whereKey($source_category_id)->value('shop_id') ?? 0) === $target_shop_id;
                if ($assigned_to_shop) {
                    $summary['assigned']++;
                    Log::channel('daily')->info('Catalog binding strategy resolved', [
                        'strategy'       => 'assign',
                        'entity_type'    => 'category',
                        'entity_id'      => $source_category_id,
                        'target_shop_id' => $target_shop_id,
                    ]);
                }

                continue;
            }

            $existing_target_row = CategoryProduct::query()
                ->where('product_id', $product_id)
                ->where('category_id', $target_category_id)
                ->first();

            if ($existing_target_row instanceof CategoryProduct) {
                $category_row->delete();

                continue;
            }

            $category_row->update([
                'category_id' => $target_category_id,
            ]);
            $summary['relinked']++;

            if ($existing_target_category instanceof Category && (int) $existing_target_category->id === $target_category_id) {
                $summary['reused']++;
                Log::channel('daily')->info('Catalog binding strategy resolved', [
                    'strategy'       => 'reuse',
                    'entity_type'    => 'category',
                    'entity_id'      => $source_category_id,
                    'target_shop_id' => $target_shop_id,
                    'target_id'      => $target_category_id,
                ]);
            } else {
                $summary['created']++;
                Log::channel('daily')->info('Catalog binding strategy resolved', [
                    'strategy'       => 'duplicate',
                    'entity_type'    => 'category',
                    'entity_id'      => $source_category_id,
                    'target_shop_id' => $target_shop_id,
                    'target_id'      => $target_category_id,
                ]);
            }

            Log::channel('daily')->info('Product category relinked to shop-scoped category', [
                'product_id'         => $product_id,
                'shop_id'            => $target_shop_id,
                'source_category_id' => $source_category_id,
                'target_category_id' => $target_category_id,
                'is_reused'          => $existing_target_category instanceof Category && (int) $existing_target_category->id === $target_category_id,
            ]);
        }

        return $summary;
    }

    /**
     * @param  array<int, int>  $category_map
     */
    private function resolveShopScopedCategoryId(int $category_id, int $target_shop_id, array &$category_map): int
    {
        if (array_key_exists($category_id, $category_map)) {
            return $category_map[$category_id];
        }

        $category = Category::query()
            ->with('descriptions')
            ->find($category_id);

        if (! $category instanceof Category) {
            return 0;
        }

        $source_shop_id = (int) ($category->shop_id ?? 0);

        if ($source_shop_id === $target_shop_id) {
            $category_map[$category_id] = (int) $category->id;

            return (int) $category->id;
        }

        $target_parent_id = null;
        $parent_id        = (int) ($category->parent_id ?? 0);
        if ($parent_id > 0) {
            $resolved_parent_id = $this->resolveShopScopedCategoryId($parent_id, $target_shop_id, $category_map);
            $target_parent_id   = $resolved_parent_id > 0 ? $resolved_parent_id : null;
        }

        if ($source_shop_id <= 0) {
            $category->update([
                'shop_id'   => $target_shop_id,
                'parent_id' => $target_parent_id,
            ]);

            $assigned_category_id       = (int) ($category->fresh()?->id ?? $category->id);
            $category_map[$category_id] = $assigned_category_id;

            return $assigned_category_id;
        }

        $target_category            = $category->duplicateForShop($target_shop_id, $target_parent_id);
        $category_map[$category_id] = (int) $target_category->id;

        return (int) $target_category->id;
    }

    /**
     * @return array{relinked:int,assigned:int,created:int,reused:int}
     */
    private function relinkProductAttributesToShopScope(int $product_id, int $shop_id): array
    {
        /** @var Collection<int, ProductToAttribute> $attribute_rows */
        $attribute_rows = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->get();

        $summary = [
            'relinked' => 0,
            'assigned' => 0,
            'created'  => 0,
            'reused'   => 0,
        ];

        foreach ($attribute_rows as $attribute_row) {
            $source_attribute_id = (int) ($attribute_row->attribute_id ?? 0);
            if ($source_attribute_id <= 0) {
                continue;
            }

            $source_attribute = Attribute::query()->find($source_attribute_id);
            if (! $source_attribute instanceof Attribute) {
                continue;
            }
            $source_shop_id_before = (int) ($source_attribute->shop_id ?? 0);

            $source_family_ulid        = Str::trim((string) ($source_attribute->family_ulid ?? ''));
            $existing_target_attribute = $source_family_ulid !== ''
                ? Attribute::findByFamilyAndShop($source_family_ulid, $shop_id)
                : null;

            $target_attribute_id = $this->resolveShopScopedAttributeId($source_attribute, $shop_id);

            if ($target_attribute_id <= 0 || $target_attribute_id === $source_attribute_id) {
                $assigned_to_shop = $source_shop_id_before <= 0
                    && (int) (Attribute::query()->whereKey($source_attribute_id)->value('shop_id') ?? 0) === $shop_id;
                if ($assigned_to_shop) {
                    $summary['assigned']++;
                    Log::channel('daily')->info('Catalog binding strategy resolved', [
                        'strategy'       => 'assign',
                        'entity_type'    => 'attribute',
                        'entity_id'      => $source_attribute_id,
                        'target_shop_id' => $shop_id,
                    ]);
                }

                continue;
            }

            $existing_target_row = ProductToAttribute::query()
                ->where('product_id', $product_id)
                ->where('attribute_id', $target_attribute_id)
                ->where(function (Builder $query) use ($attribute_row): void {
                    if ($attribute_row->shop_language_id === null) {
                        $query->whereNull('shop_language_id');

                        return;
                    }

                    $query->where('shop_language_id', (int) $attribute_row->shop_language_id);
                })
                ->first();

            if ($existing_target_row instanceof ProductToAttribute) {
                if (Str::trim((string) ($existing_target_row->text ?? '')) === '' && Str::trim((string) ($attribute_row->text ?? '')) !== '') {
                    $existing_target_row->update([
                        'text' => $attribute_row->text,
                    ]);
                }

                $attribute_row->delete();

                continue;
            }

            $attribute_row->update([
                'attribute_id' => $target_attribute_id,
            ]);
            $summary['relinked']++;

            if ($existing_target_attribute instanceof Attribute && (int) $existing_target_attribute->id === $target_attribute_id) {
                $summary['reused']++;
                Log::channel('daily')->info('Catalog binding strategy resolved', [
                    'strategy'       => 'reuse',
                    'entity_type'    => 'attribute',
                    'entity_id'      => $source_attribute_id,
                    'target_shop_id' => $shop_id,
                    'target_id'      => $target_attribute_id,
                ]);
            } else {
                $summary['created']++;
                Log::channel('daily')->info('Catalog binding strategy resolved', [
                    'strategy'       => 'duplicate',
                    'entity_type'    => 'attribute',
                    'entity_id'      => $source_attribute_id,
                    'target_shop_id' => $shop_id,
                    'target_id'      => $target_attribute_id,
                ]);
            }

            Log::channel('daily')->info('Product attribute relinked to shop-scoped attribute', [
                'product_id'          => $product_id,
                'shop_id'             => $shop_id,
                'source_attribute_id' => $source_attribute_id,
                'target_attribute_id' => $target_attribute_id,
                'is_reused'           => $existing_target_attribute instanceof Attribute && (int) $existing_target_attribute->id === $target_attribute_id,
            ]);
        }

        return $summary;
    }

    private function relinkProductManufacturerBrandToShopScope(int $product_id, int $shop_id): array
    {
        $binding = ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->first();

        if (! $binding instanceof ProductToManufacturerBrand) {
            return [
                'manufacturer_relinked'  => 0,
                'manufacturers_assigned' => 0,
                'manufacturers_created'  => 0,
                'manufacturers_reused'   => 0,
                'brand_relinked'         => 0,
                'brands_assigned'        => 0,
                'brands_created'         => 0,
                'brands_reused'          => 0,
            ];
        }

        $updates                = [];
        $manufacturer_relinked  = 0;
        $manufacturers_assigned = 0;
        $manufacturers_created  = 0;
        $manufacturers_reused   = 0;
        $brand_relinked         = 0;
        $brands_assigned        = 0;
        $brands_created         = 0;
        $brands_reused          = 0;

        $manufacturer_id = (int) ($binding->manufacturer_id ?? 0);
        if ($manufacturer_id > 0) {
            $manufacturer = Manufacturer::query()->find($manufacturer_id);
            if ($manufacturer instanceof Manufacturer) {
                $source_shop_id_before        = (int) ($manufacturer->shop_id ?? 0);
                $source_family_ulid           = Str::trim((string) ($manufacturer->family_ulid ?? ''));
                $existing_target_manufacturer = $source_family_ulid !== ''
                    ? Manufacturer::findByFamilyAndShop($source_family_ulid, $shop_id)
                    : null;

                $target_manufacturer_id     = $this->resolveShopScopedManufacturerId($manufacturer, $shop_id);
                $updates['manufacturer_id'] = $target_manufacturer_id;
                if ($target_manufacturer_id > 0 && $target_manufacturer_id !== $manufacturer_id) {
                    $manufacturer_relinked++;
                    if ($existing_target_manufacturer instanceof Manufacturer && (int) $existing_target_manufacturer->id === $target_manufacturer_id) {
                        $manufacturers_reused++;
                        Log::channel('daily')->info('Catalog binding strategy resolved', [
                            'strategy'       => 'reuse',
                            'entity_type'    => 'manufacturer',
                            'entity_id'      => $manufacturer_id,
                            'target_shop_id' => $shop_id,
                            'target_id'      => $target_manufacturer_id,
                        ]);
                    } else {
                        $manufacturers_created++;
                        Log::channel('daily')->info('Catalog binding strategy resolved', [
                            'strategy'       => 'duplicate',
                            'entity_type'    => 'manufacturer',
                            'entity_id'      => $manufacturer_id,
                            'target_shop_id' => $shop_id,
                            'target_id'      => $target_manufacturer_id,
                        ]);
                    }
                } elseif ($target_manufacturer_id > 0 && $source_shop_id_before <= 0) {
                    $manufacturers_assigned++;
                    Log::channel('daily')->info('Catalog binding strategy resolved', [
                        'strategy'       => 'assign',
                        'entity_type'    => 'manufacturer',
                        'entity_id'      => $manufacturer_id,
                        'target_shop_id' => $shop_id,
                    ]);
                }
            }
        }

        $brand_id = (int) ($binding->brand_id ?? 0);
        if ($brand_id > 0) {
            $brand = Brand::query()->find($brand_id);
            if ($brand instanceof Brand) {
                $source_shop_id_before = (int) ($brand->shop_id ?? 0);
                $source_family_ulid    = Str::trim((string) ($brand->family_ulid ?? ''));
                $existing_target_brand = $source_family_ulid !== ''
                    ? Brand::findByFamilyAndShop($source_family_ulid, $shop_id)
                    : null;

                $target_brand_id     = $this->resolveShopScopedBrandId($brand, $shop_id);
                $updates['brand_id'] = $target_brand_id;
                if ($target_brand_id > 0 && $target_brand_id !== $brand_id) {
                    $brand_relinked++;
                    if ($existing_target_brand instanceof Brand && (int) $existing_target_brand->id === $target_brand_id) {
                        $brands_reused++;
                        Log::channel('daily')->info('Catalog binding strategy resolved', [
                            'strategy'       => 'reuse',
                            'entity_type'    => 'brand',
                            'entity_id'      => $brand_id,
                            'target_shop_id' => $shop_id,
                            'target_id'      => $target_brand_id,
                        ]);
                    } else {
                        $brands_created++;
                        Log::channel('daily')->info('Catalog binding strategy resolved', [
                            'strategy'       => 'duplicate',
                            'entity_type'    => 'brand',
                            'entity_id'      => $brand_id,
                            'target_shop_id' => $shop_id,
                            'target_id'      => $target_brand_id,
                        ]);
                    }
                } elseif ($target_brand_id > 0 && $source_shop_id_before <= 0) {
                    $brands_assigned++;
                    Log::channel('daily')->info('Catalog binding strategy resolved', [
                        'strategy'       => 'assign',
                        'entity_type'    => 'brand',
                        'entity_id'      => $brand_id,
                        'target_shop_id' => $shop_id,
                    ]);
                }
            }
        }

        if ($updates !== []) {
            $binding->update($updates);

            Log::channel('daily')->info('Product manufacturer/brand relinked to shop-scoped entities', [
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
                'updates'    => $updates,
            ]);
        }

        return [
            'manufacturer_relinked'  => $manufacturer_relinked,
            'manufacturers_assigned' => $manufacturers_assigned,
            'manufacturers_created'  => $manufacturers_created,
            'manufacturers_reused'   => $manufacturers_reused,
            'brand_relinked'         => $brand_relinked,
            'brands_assigned'        => $brands_assigned,
            'brands_created'         => $brands_created,
            'brands_reused'          => $brands_reused,
        ];
    }

    private function resolveShopScopedAttributeId(Attribute $attribute, int $target_shop_id): int
    {
        if ($target_shop_id <= 0) {
            return 0;
        }

        $source_shop_id = (int) ($attribute->shop_id ?? 0);

        if ($source_shop_id === $target_shop_id) {
            return (int) $attribute->id;
        }

        if ($source_shop_id <= 0) {
            $attribute->update([
                'shop_id' => $target_shop_id,
            ]);

            return (int) ($attribute->fresh()?->id ?? $attribute->id);
        }

        return (int) $attribute->duplicateForShop($target_shop_id)->id;
    }

    private function resolveShopScopedManufacturerId(Manufacturer $manufacturer, int $target_shop_id): int
    {
        if ($target_shop_id <= 0) {
            return 0;
        }

        $source_shop_id = (int) ($manufacturer->shop_id ?? 0);

        if ($source_shop_id === $target_shop_id) {
            return (int) $manufacturer->id;
        }

        if ($source_shop_id <= 0) {
            $manufacturer->update([
                'shop_id' => $target_shop_id,
            ]);

            return (int) ($manufacturer->fresh()?->id ?? $manufacturer->id);
        }

        return (int) $manufacturer->duplicateForShop($target_shop_id)->id;
    }

    private function resolveShopScopedBrandId(Brand $brand, int $target_shop_id): int
    {
        if ($target_shop_id <= 0) {
            return 0;
        }

        $source_shop_id = (int) ($brand->shop_id ?? 0);

        if ($source_shop_id === $target_shop_id) {
            return (int) $brand->id;
        }

        if ($source_shop_id <= 0) {
            $brand->update([
                'shop_id' => $target_shop_id,
            ]);

            return (int) ($brand->fresh()?->id ?? $brand->id);
        }

        return (int) $brand->duplicateForShop($target_shop_id)->id;
    }

    private function ensureShopLinksForProduct(int $product_id, int $shop_id): void
    {
        if (! $this->hasProductShopBinding($product_id, $shop_id)) {
            Log::channel('stack')->warning('Catalog external mapping skipped because product is not bound to shop', [
                'product_id' => $product_id,
                'shop_id'    => $shop_id,
            ]);

            return;
        }

        $category_ids = CategoryProduct::getUniqueCategoryIdsByProductId($product_id);

        foreach ($category_ids as $category_id) {
            $category = Category::query()->find($category_id);
            if (! $category instanceof Category || ! $this->isEntityOwnedByShop($category->shop_id, $shop_id, 'categories')) {
                Log::channel('daily')->warning('Skipping category external mapping due to shop scope mismatch', [
                    'product_id'       => $product_id,
                    'shop_id'          => $shop_id,
                    'category_id'      => $category_id,
                    'category_shop_id' => $category?->shop_id,
                ]);

                continue;
            }

            $category_shop = CategoryShop::query()->firstOrCreate([
                'category_id' => $category_id,
                'shop_id'     => $shop_id,
            ], [
                'external_category_id' => null,
            ]);

            Log::channel('daily')->info('Category external mapping row ensured', [
                'product_id'           => $product_id,
                'shop_id'              => $shop_id,
                'category_id'          => $category_id,
                'mapping_created'      => $category_shop->wasRecentlyCreated,
                'external_category_id' => $category_shop->external_category_id,
            ]);
        }

        $attribute_ids = ProductToAttribute::getUniqueAttributeIdsByProductId($product_id);

        foreach ($attribute_ids as $attribute_id) {
            $attribute = Attribute::query()->find($attribute_id);
            if (! $attribute instanceof Attribute || ! $this->isEntityOwnedByShop($attribute->shop_id, $shop_id, 'attributes')) {
                Log::channel('daily')->warning('Skipping attribute external mapping due to shop scope mismatch', [
                    'product_id'        => $product_id,
                    'shop_id'           => $shop_id,
                    'attribute_id'      => $attribute_id,
                    'attribute_shop_id' => $attribute?->shop_id,
                ]);

                continue;
            }

            $attribute_shop = AttributeShop::query()->firstOrCreate([
                'attribute_id' => $attribute_id,
                'shop_id'      => $shop_id,
            ], [
                'external_attribute_id' => null,
            ]);

            Log::channel('daily')->info('Attribute external mapping row ensured', [
                'product_id'            => $product_id,
                'shop_id'               => $shop_id,
                'attribute_id'          => $attribute_id,
                'mapping_created'       => $attribute_shop->wasRecentlyCreated,
                'external_attribute_id' => $attribute_shop->external_attribute_id,
            ]);
        }

        $manufacturer_brand_binding = ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->first();

        $manufacturer_id = (int) ($manufacturer_brand_binding?->manufacturer_id ?? 0);
        if ($manufacturer_id > 0) {
            $manufacturer = Manufacturer::query()->find($manufacturer_id);
            if (! $manufacturer instanceof Manufacturer || ! $this->isEntityOwnedByShop($manufacturer->shop_id, $shop_id, 'manufacturers')) {
                Log::channel('daily')->warning('Skipping manufacturer external mapping due to shop scope mismatch', [
                    'product_id'           => $product_id,
                    'shop_id'              => $shop_id,
                    'manufacturer_id'      => $manufacturer_id,
                    'manufacturer_shop_id' => $manufacturer?->shop_id,
                ]);
            } else {
                $manufacturer_shop = ManufacturerShop::query()->firstOrCreate(
                    [
                        'manufacturer_id' => $manufacturer_id,
                        'shop_id'         => $shop_id,
                    ],
                    [
                        'external_manufacturer_id' => null,
                    ]
                );

                Log::channel('daily')->info('Manufacturer external mapping row ensured', [
                    'product_id'               => $product_id,
                    'shop_id'                  => $shop_id,
                    'manufacturer_id'          => $manufacturer_id,
                    'mapping_created'          => $manufacturer_shop->wasRecentlyCreated,
                    'external_manufacturer_id' => $manufacturer_shop->external_manufacturer_id,
                ]);
            }
        }

        $brand_id = (int) ($manufacturer_brand_binding?->brand_id ?? 0);
        if ($brand_id > 0) {
            $brand = Brand::query()->find($brand_id);
            if (! $brand instanceof Brand || ! $this->isEntityOwnedByShop($brand->shop_id, $shop_id, 'brands')) {
                Log::channel('daily')->warning('Skipping brand external mapping due to shop scope mismatch', [
                    'product_id'    => $product_id,
                    'shop_id'       => $shop_id,
                    'brand_id'      => $brand_id,
                    'brand_shop_id' => $brand?->shop_id,
                ]);
            } else {
                $brand_shop = BrandShop::query()->firstOrCreate(
                    [
                        'brand_id' => $brand_id,
                        'shop_id'  => $shop_id,
                    ],
                    [
                        'external_brand_id' => null,
                    ]
                );

                Log::channel('daily')->info('Brand external mapping row ensured', [
                    'product_id'        => $product_id,
                    'shop_id'           => $shop_id,
                    'brand_id'          => $brand_id,
                    'mapping_created'   => $brand_shop->wasRecentlyCreated,
                    'external_brand_id' => $brand_shop->external_brand_id,
                ]);
            }
        }
    }

    private function isEntityOwnedByShop(?int $entity_shop_id, int $shop_id, string $table_name): bool
    {
        if ($shop_id <= 0) {
            return false;
        }

        return (int) $entity_shop_id === $shop_id;
    }

    private function hasProductShopBinding(int $product_id, int $target_shop_id): bool
    {
        return ProductShop::isProductBoundToShop($product_id, $target_shop_id);
    }

    private function resolveProductImportBatchId(Product $source_product, int $product_import_batch_id): int
    {
        if ($product_import_batch_id > 0) {
            return $product_import_batch_id;
        }

        $batch_id_from_product_import_item = ProductImportItem::resolveBatchIdByProductImportItemId(
            (int) ($source_product->product_import_item_id ?? 0)
        );

        if ($batch_id_from_product_import_item > 0) {
            return $batch_id_from_product_import_item;
        }

        $batch_id_from_items = ProductImportItem::resolveLatestBatchIdByProductId((int) $source_product->id);

        if ($batch_id_from_items > 0) {
            return $batch_id_from_items;
        }

        return ProductShop::resolveLatestBatchIdByProductId((int) $source_product->id);
    }

    private function invalidateProductResourceOptionCaches(int $target_shop_id, int $product_id): void
    {
        if ($target_shop_id <= 0) {
            return;
        }

        /**
         * The cache must be cleared only after the binding transaction has been committed.
         * Otherwise another request can repopulate the same keys from the pre-commit state
         * and Filament will continue showing stale manufacturer / brand / category / attribute options.
         */
        app(ProductResourceOptionsService::class)->forgetShopScopedCatalogOptionCaches($target_shop_id);

        Log::channel('daily')->info('Product resource option caches invalidated after shop binding', [
            'product_id' => $product_id,
            'shop_id'    => $target_shop_id,
        ]);
    }

    public function applyDefaultLanguageToProductTranslations(int $product_id, int $target_shop_id): void
    {
        if ($product_id <= 0 || $target_shop_id <= 0) {
            return;
        }

        $default_shop_language_id = ShopLanguage::getDefaultLanguageIdByShopId($target_shop_id);

        if ($default_shop_language_id <= 0) {
            return;
        }

        $this->syncProductDescriptionsLanguage($product_id, $default_shop_language_id);
        $this->syncProductAttributesLanguage($product_id, $default_shop_language_id);
        $this->syncSeoUrlsLanguage($product_id, $default_shop_language_id);
        $this->syncCategoryDescriptionsLanguage($product_id, $default_shop_language_id);
        $this->syncAttributeDescriptionsLanguage($product_id, $default_shop_language_id);
        $this->syncManufacturerDescriptionsLanguage($product_id, $default_shop_language_id);
        $this->syncBrandDescriptionsLanguage($product_id, $default_shop_language_id);
    }

    /**
     * @throws Throwable
     */
    public function synchronizeProductTranslations(int $product_id): void
    {
        if ($product_id <= 0) {
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

        if ($shop_ids === []) {
            return;
        }

        foreach ($shop_ids as $shop_id) {
            try {
                $this->synchronizeProductTranslationsForShop($product_id, (int) $shop_id);
            } catch (Throwable $exception) {
                Log::channel('stack')->warning('Product translation synchronization failed for shop', [
                    'product_id' => $product_id,
                    'shop_id'    => (int) $shop_id,
                    'message'    => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @throws Throwable
     */
    public function synchronizeProductTranslationsForShop(int $product_id, int $target_shop_id): void
    {
        $this->applyDefaultLanguageToProductTranslations($product_id, $target_shop_id);
        $this->translateProductTextsForShopLanguages($product_id, $target_shop_id);
        $this->synchronizeManufacturerDescriptionsForShopLanguages($product_id, $target_shop_id);
        $this->synchronizeBrandDescriptionsForShopLanguages($product_id, $target_shop_id);
        $this->synchronizeSeoUrlsForShopLanguages($product_id, $target_shop_id);
    }

    private function synchronizeSeoUrlsForShopLanguages(int $product_id, int $target_shop_id): void
    {
        if ($product_id <= 0 || $target_shop_id <= 0) {
            return;
        }

        $shop_languages = ShopLanguage::getActiveByShopIdCached($target_shop_id);
        if ($shop_languages->isEmpty()) {
            Log::channel('stack')->warning('SEO language synchronization skipped: no active shop languages', [
                'product_id' => $product_id,
                'shop_id'    => $target_shop_id,
            ]);

            return;
        }

        $shop_language_ids = $shop_languages
            ->pluck('id')
            ->map(static fn ($language_id): int => (int) $language_id)
            ->filter(static fn (int $language_id): bool => $language_id > 0)
            ->values()
            ->all();

        $default_shop_language = $shop_languages->first(static fn (ShopLanguage $shop_language): bool => (bool) $shop_language->is_default)
            ?? $shop_languages->first();

        $default_shop_language_id = $default_shop_language instanceof ShopLanguage
            ? (int) $default_shop_language->id
            : 0;

        $seo_rows = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->orderBy('id')
            ->get();

        $source_seo_row = $seo_rows->first(static fn (SeoUrl $seo_url): bool => (int) ($seo_url->shop_language_id ?? 0) === $default_shop_language_id)
            ?? $seo_rows->first(static fn (SeoUrl $seo_url): bool => (int) ($seo_url->shop_language_id ?? 0) > 0)
            ?? $seo_rows->first(static fn (SeoUrl $seo_url): bool => $seo_url->shop_language_id === null)
            ?? $seo_rows->first();

        $source_query_key  = Str::trim((string) ($source_seo_row?->query_key ?? ''));
        $source_sort_order = (int) ($source_seo_row?->sort_order ?? 1);

        $default_description_name = Str::trim((string) (ProductDescription::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', $default_shop_language_id > 0 ? $default_shop_language_id : null)
            ->value('name') ?? ''));

        if ($default_description_name === '') {
            $default_description_name = Str::trim((string) (ProductDescription::query()
                ->where('product_id', $product_id)
                ->orderBy('id')
                ->value('name') ?? ''));
        }

        $product       = Product::query()->find($product_id);
        $product_model = Str::trim((string) ($product?->model ?? ''));
        $product_sku   = Str::trim((string) ($product?->sku ?? ''));

        $created_count = 0;
        $updated_count = 0;
        $skipped_count = 0;

        foreach ($shop_languages as $shop_language) {
            $shop_language_id = (int) ($shop_language->id ?? 0);
            $language_code    = $this->normalizeLanguageCode((string) ($shop_language->code ?? ''));

            if ($shop_language_id <= 0 || $language_code === '') {
                $skipped_count++;

                continue;
            }

            $existing_seo_row = SeoUrl::query()
                ->where('seoable_type', Product::class)
                ->where('seoable_id', $product_id)
                ->where('shop_language_id', $shop_language_id)
                ->where('query_value', (string) $product_id)
                ->first();

            $keyword = Str::trim((string) ($existing_seo_row?->keyword ?? ''));
            if ($keyword === '') {
                $language_description_name = Str::trim((string) (ProductDescription::query()
                    ->where('product_id', $product_id)
                    ->where('shop_language_id', $shop_language_id)
                    ->value('name') ?? ''));

                $base_text = $language_description_name !== ''
                    ? $language_description_name
                    : ($default_description_name !== '' ? $default_description_name : ($product_model !== '' ? $product_model : ($product_sku !== '' ? $product_sku : 'product-'.$product_id)));

                $keyword = $this->generateSeoKeywordForLanguage($base_text, $language_code);
            }

            if ($keyword === '') {
                $skipped_count++;
                Log::channel('daily')->warning('SEO language synchronization skipped empty keyword', [
                    'product_id'       => $product_id,
                    'shop_id'          => $target_shop_id,
                    'shop_language_id' => $shop_language_id,
                    'language_code'    => $language_code,
                ]);

                continue;
            }

            $upserted_row = $product->seoUrl()->updateOrCreate(
                [
                    'shop_language_id' => $shop_language_id,
                    'query_value'      => (string) $product_id,
                ],
                [
                    'query_key'  => $source_query_key !== '' ? $source_query_key : (string) ($existing_seo_row?->query_key ?? ''),
                    'keyword'    => $keyword,
                    'sort_order' => (int) ($existing_seo_row?->sort_order ?? $source_sort_order),
                ]
            );

            /*$upserted_row = SeoUrl::query()->updateOrCreate(
                [
                    'seoable_type'     => Product::class,
                    'seoable_id'       => $product_id,
                    'shop_language_id' => $shop_language_id,
                    'query_value'      => (string)$product_id,
                ],
                [
                    'query_key'  => $source_query_key !== '' ? $source_query_key : (string)($existing_seo_row?->query_key ?? ''),
                    'keyword'    => $keyword,
                    'sort_order' => (int)($existing_seo_row?->sort_order ?? $source_sort_order),
                ]
            );*/

            if ($upserted_row->wasRecentlyCreated) {
                $created_count++;
            } else {
                $updated_count++;
            }
        }

        $null_language_rows = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->whereNull('shop_language_id')
            ->where('query_value', (string) $product_id)
            ->get();

        foreach ($null_language_rows as $null_language_row) {
            $default_exists = SeoUrl::query()
                ->where('seoable_type', Product::class)
                ->where('seoable_id', $product_id)
                ->where('shop_language_id', $default_shop_language_id > 0 ? $default_shop_language_id : null)
                ->where('query_value', (string) $product_id)
                ->exists();

            if ($default_exists) {
                $null_language_row->delete();
            }
        }

        Log::channel('daily')->info('SEO language synchronization completed', [
            'product_id'      => $product_id,
            'shop_id'         => $target_shop_id,
            'languages_total' => count($shop_language_ids),
            'created_count'   => $created_count,
            'updated_count'   => $updated_count,
            'skipped_count'   => $skipped_count,
        ]);
    }

    private function syncProductDescriptionsLanguage(int $product_id, int $default_shop_language_id): void
    {
        /** @var Collection<int, ProductDescription> $null_language_rows */
        $null_language_rows = ProductDescription::query()
            ->where('product_id', $product_id)
            ->whereNull('shop_language_id')
            ->orderBy('id')
            ->get();

        foreach ($null_language_rows as $null_language_row) {
            $existing_default_row = ProductDescription::query()
                ->where('product_id', $product_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof ProductDescription) {
                $existing_default_row->update([
                    'name'             => $existing_default_row->name ?? $null_language_row->name,
                    'description'      => $existing_default_row->description ?? $null_language_row->description,
                    'meta_title'       => $existing_default_row->meta_title ?? $null_language_row->meta_title,
                    'meta_description' => $existing_default_row->meta_description ?? $null_language_row->meta_description,
                    'meta_keywords'    => $existing_default_row->meta_keywords ?? $null_language_row->meta_keywords,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    private function syncProductAttributesLanguage(int $product_id, int $default_shop_language_id): void
    {
        /** @var Collection<int, ProductToAttribute> $null_language_rows */
        $null_language_rows = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->whereNull('shop_language_id')
            ->orderBy('id')
            ->get();

        foreach ($null_language_rows as $null_language_row) {
            $attribute_id = (int) ($null_language_row->attribute_id ?? 0);
            if ($attribute_id <= 0) {
                continue;
            }

            $existing_default_row = ProductToAttribute::query()
                ->where('product_id', $product_id)
                ->where('attribute_id', $attribute_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof ProductToAttribute) {
                $existing_default_row->update([
                    'text' => $existing_default_row->text ?? $null_language_row->text,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    private function syncSeoUrlsLanguage(int $product_id, int $default_shop_language_id): void
    {
        SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->whereNull('shop_language_id')
            ->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
    }

    private function syncCategoryDescriptionsLanguage(int $product_id, int $default_shop_language_id): void
    {
        $category_ids = CategoryProduct::getUniqueCategoryIdsByProductId($product_id);

        if ($category_ids === []) {
            return;
        }

        /** @var Collection<int, CategoryDescription> $null_language_rows */
        $null_language_rows = CategoryDescription::query()
            ->whereIn('category_id', $category_ids)
            ->whereNull('shop_language_id')
            ->orderBy('id')
            ->get();

        foreach ($null_language_rows as $null_language_row) {
            $category_id = (int) ($null_language_row->category_id ?? 0);
            if ($category_id <= 0) {
                continue;
            }

            $existing_default_row = CategoryDescription::query()
                ->where('category_id', $category_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof CategoryDescription) {
                $existing_default_row->update([
                    'name'             => $existing_default_row->name ?? $null_language_row->name,
                    'description'      => $existing_default_row->description ?? $null_language_row->description,
                    'h1_title'         => $existing_default_row->h1_title ?? $null_language_row->h1_title,
                    'meta_title'       => $existing_default_row->meta_title ?? $null_language_row->meta_title,
                    'meta_description' => $existing_default_row->meta_description ?? $null_language_row->meta_description,
                    'meta_keywords'    => $existing_default_row->meta_keywords ?? $null_language_row->meta_keywords,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    private function syncAttributeDescriptionsLanguage(int $product_id, int $default_shop_language_id): void
    {
        $attribute_ids = ProductToAttribute::getUniqueAttributeIdsByProductId($product_id);

        if ($attribute_ids === []) {
            return;
        }

        /** @var Collection<int, AttributeDescription> $null_language_rows */
        $null_language_rows = AttributeDescription::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->whereNull('shop_language_id')
            ->orderBy('id')
            ->get();

        foreach ($null_language_rows as $null_language_row) {
            $attribute_id = (int) ($null_language_row->attribute_id ?? 0);
            if ($attribute_id <= 0) {
                continue;
            }

            $existing_default_row = AttributeDescription::query()
                ->where('attribute_id', $attribute_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof AttributeDescription) {
                $existing_default_row->update([
                    'name' => $existing_default_row->name ?? $null_language_row->name,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    private function syncManufacturerDescriptionsLanguage(int $product_id, int $default_shop_language_id): void
    {
        try {
            $manufacturer_id = (int) (ProductToManufacturerBrand::query()
                ->where('product_id', $product_id)
                ->value('manufacturer_id') ?? 0);
        } catch (Throwable) {
            return;
        }

        if ($manufacturer_id <= 0) {
            return;
        }

        try {
            /** @var Collection<int, ManufacturerDescription> $null_language_rows */
            $null_language_rows = ManufacturerDescription::query()
                ->where('manufacturer_id', $manufacturer_id)
                ->whereNull('shop_language_id')
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return;
        }

        foreach ($null_language_rows as $null_language_row) {
            $existing_default_row = ManufacturerDescription::query()
                ->where('manufacturer_id', $manufacturer_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof ManufacturerDescription) {
                $existing_default_row->update([
                    'name' => $existing_default_row->name ?? $null_language_row->name,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    private function syncBrandDescriptionsLanguage(int $product_id, int $default_shop_language_id): void
    {
        try {
            $brand_id = (int) (ProductToManufacturerBrand::query()
                ->where('product_id', $product_id)
                ->value('brand_id') ?? 0);
        } catch (Throwable) {
            return;
        }

        if ($brand_id <= 0) {
            return;
        }

        try {
            /** @var Collection<int, BrandDescription> $null_language_rows */
            $null_language_rows = BrandDescription::query()
                ->where('brand_id', $brand_id)
                ->whereNull('shop_language_id')
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return;
        }

        foreach ($null_language_rows as $null_language_row) {
            $existing_default_row = BrandDescription::query()
                ->where('brand_id', $brand_id)
                ->where('shop_language_id', $default_shop_language_id)
                ->first();

            if ($existing_default_row instanceof BrandDescription) {
                $existing_default_row->update([
                    'name' => $existing_default_row->name ?? $null_language_row->name,
                ]);

                $null_language_row->delete();

                continue;
            }

            $null_language_row->update([
                'shop_language_id' => $default_shop_language_id,
            ]);
        }
    }

    /**
     * @throws Throwable
     */
    private function synchronizeManufacturerDescriptionsForShopLanguages(int $product_id, int $target_shop_id): void
    {
        $target_shop_languages = ShopLanguage::getActiveByShopIdCached($target_shop_id);

        if ($target_shop_languages->isEmpty()) {
            Log::channel('daily')->warning('Manufacturer language synchronization skipped: no active shop languages', [
                'product_id' => $product_id,
                'shop_id'    => $target_shop_id,
            ]);

            return;
        }

        $manufacturer_id = (int) (ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->value('manufacturer_id') ?? 0);

        if ($manufacturer_id <= 0) {
            return;
        }

        $target_default_shop_language = $target_shop_languages->first(static fn (ShopLanguage $shop_language): bool => (bool) $shop_language->is_default)
            ?? $target_shop_languages->first();

        if (! $target_default_shop_language instanceof ShopLanguage) {
            return;
        }

        $target_default_shop_language_id = (int) $target_default_shop_language->id;
        $target_language_code            = $this->normalizeLanguageCode((string) ($target_default_shop_language->code ?? 'uk')) ?: 'uk';
        $source_manufacturer_description = $this->resolveManufacturerSourceDescription(
            $manufacturer_id,
            $target_language_code,
            $target_shop_id,
            $target_default_shop_language_id
        );

        $source_name = Str::trim((string) ($source_manufacturer_description->name ?? ''));

        if ($source_name === '') {
            return;
        }

        $source_language_code = $this->resolveSourceLanguageCodeByShopLanguageId(
            (int) ($source_manufacturer_description->shop_language_id ?? 0),
            $target_language_code
        );

        foreach ($target_shop_languages as $shop_language) {
            $shop_language_id     = (int) ($shop_language->id ?? 0);
            $target_language_code = $this->normalizeLanguageCode((string) ($shop_language->code ?? ''));

            if ($shop_language_id <= 0 || $target_language_code === '') {
                continue;
            }

            $target_manufacturer_description = ManufacturerDescription::query()
                ->where('manufacturer_id', $manufacturer_id)
                ->where('shop_language_id', $shop_language_id)
                ->first();

            ManufacturerDescription::query()->updateOrCreate(
                [
                    'manufacturer_id'  => $manufacturer_id,
                    'shop_language_id' => $shop_language_id,
                ],
                [
                    'name' => $this->translateText(
                        $product_id,
                        $source_name,
                        $source_language_code,
                        $target_language_code,
                        (string) ($target_manufacturer_description->name ?? ''),
                        'manufacturerName',
                        0,
                        0,
                        0,
                        $manufacturer_id
                    ),
                ]
            );
        }
    }

    /**
     * @throws Throwable
     */
    private function synchronizeBrandDescriptionsForShopLanguages(int $product_id, int $target_shop_id): void
    {
        $target_shop_languages = ShopLanguage::getActiveByShopIdCached($target_shop_id);

        if ($target_shop_languages->isEmpty()) {
            Log::channel('daily')->warning('Brand language synchronization skipped: no active shop languages', [
                'product_id' => $product_id,
                'shop_id'    => $target_shop_id,
            ]);

            return;
        }

        $brand_id = (int) (ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->value('brand_id') ?? 0);

        if ($brand_id <= 0) {
            return;
        }

        $target_default_shop_language = $target_shop_languages->first(static fn (ShopLanguage $shop_language): bool => (bool) $shop_language->is_default)
            ?? $target_shop_languages->first();

        if (! $target_default_shop_language instanceof ShopLanguage) {
            return;
        }

        $target_default_shop_language_id = (int) $target_default_shop_language->id;
        $target_language_code            = $this->normalizeLanguageCode((string) ($target_default_shop_language->code ?? 'uk')) ?: 'uk';
        $source_brand_description        = $this->resolveBrandSourceDescription(
            $brand_id,
            $target_language_code,
            $target_shop_id,
            $target_default_shop_language_id
        );

        $source_name = Str::trim((string) ($source_brand_description->name ?? ''));

        if ($source_name === '') {
            return;
        }

        $source_language_code = $this->resolveSourceLanguageCodeByShopLanguageId(
            (int) ($source_brand_description->shop_language_id ?? 0),
            $target_language_code
        );

        foreach ($target_shop_languages as $shop_language) {
            $shop_language_id     = (int) ($shop_language->id ?? 0);
            $target_language_code = $this->normalizeLanguageCode((string) ($shop_language->code ?? ''));

            if ($shop_language_id <= 0 || $target_language_code === '') {
                continue;
            }

            $target_brand_description = BrandDescription::query()
                ->where('brand_id', $brand_id)
                ->where('shop_language_id', $shop_language_id)
                ->first();

            BrandDescription::query()->updateOrCreate(
                [
                    'brand_id'         => $brand_id,
                    'shop_language_id' => $shop_language_id,
                ],
                [
                    'name' => $this->translateText(
                        $product_id,
                        $source_name,
                        $source_language_code,
                        $target_language_code,
                        (string) ($target_brand_description->name ?? ''),
                        'brandName',
                        0,
                        0,
                        $brand_id
                    ),
                ]
            );
        }
    }

    private function resolveManufacturerSourceDescription(
        int $manufacturer_id,
        string $target_language_code,
        int $target_shop_id,
        int $target_default_shop_language_id
    ): ?ManufacturerDescription {
        $source_shop_language_ids = $this->resolveSourceShopLanguageIdsByCode($target_language_code, $target_shop_id);
        if (! in_array($target_default_shop_language_id, $source_shop_language_ids, true)) {
            array_unshift($source_shop_language_ids, $target_default_shop_language_id);
        }

        foreach ($source_shop_language_ids as $source_shop_language_id) {
            $description = ManufacturerDescription::query()
                ->where('manufacturer_id', $manufacturer_id)
                ->where('shop_language_id', $source_shop_language_id)
                ->first();

            if ($description instanceof ManufacturerDescription) {
                return $description;
            }
        }

        return ManufacturerDescription::query()
            ->where('manufacturer_id', $manufacturer_id)
            ->whereNull('shop_language_id')
            ->first()
            ?? ManufacturerDescription::query()
                ->where('manufacturer_id', $manufacturer_id)
                ->orderBy('id')
                ->first();
    }

    private function resolveBrandSourceDescription(
        int $brand_id,
        string $target_language_code,
        int $target_shop_id,
        int $target_default_shop_language_id
    ): ?BrandDescription {
        $source_shop_language_ids = $this->resolveSourceShopLanguageIdsByCode($target_language_code, $target_shop_id);
        if (! in_array($target_default_shop_language_id, $source_shop_language_ids, true)) {
            array_unshift($source_shop_language_ids, $target_default_shop_language_id);
        }

        foreach ($source_shop_language_ids as $source_shop_language_id) {
            $description = BrandDescription::query()
                ->where('brand_id', $brand_id)
                ->where('shop_language_id', $source_shop_language_id)
                ->first();

            if ($description instanceof BrandDescription) {
                return $description;
            }
        }

        return BrandDescription::query()
            ->where('brand_id', $brand_id)
            ->whereNull('shop_language_id')
            ->first()
            ?? BrandDescription::query()
                ->where('brand_id', $brand_id)
                ->orderBy('id')
                ->first();
    }

    /**
     * @return list<int>
     */
    private function resolveSourceShopLanguageIdsByCode(string $language_code, int $shop_id): array
    {
        return ShopLanguage::resolveOrderedLanguageIdsByCodeCached($language_code, $shop_id);
    }

    /**
     * @throws Throwable
     */
    private function translateProductTextsForShopLanguages(int $product_id, int $target_shop_id): void
    {
        $target_shop_languages = ShopLanguage::getActiveByShopIdCached($target_shop_id);

        if ($target_shop_languages->isEmpty()) {
            return;
        }

        $target_default_shop_language = $target_shop_languages->first(static fn (ShopLanguage $shop_language): bool => (bool) $shop_language->is_default);

        if (! $target_default_shop_language instanceof ShopLanguage) {
            throw new RuntimeException('Default shop language not found for shop id: '.$target_shop_id);
        }

        $target_default_shop_language_id = (int) $target_default_shop_language->id;
        $target_default_language_code    = $this->normalizeLanguageCode((string) ($target_default_shop_language->code ?? 'uk'));
        if ($target_default_language_code === '') {
            $target_default_language_code = 'uk';
        }

        $product_descriptions = ProductDescription::getByProductId($product_id);

        $source_description_row = $product_descriptions->first(function (ProductDescription $description) use ($target_shop_languages, $target_default_language_code): bool {
            $shop_language = $target_shop_languages->firstWhere('id', (int) ($description->shop_language_id ?? 0));
            if (! $shop_language instanceof ShopLanguage) {
                return false;
            }

            return $this->normalizeLanguageCode((string) $shop_language->code) === $target_default_language_code;
        });

        if (! $source_description_row instanceof ProductDescription) {
            $source_description_row = $product_descriptions->first();
        }

        if (! $source_description_row instanceof ProductDescription) {
            return;
        }

        $target_default_language_code = $this->resolveSourceLanguageCodeByShopLanguageId(
            (int) ($source_description_row->shop_language_id ?? 0),
            $target_default_language_code
        );

        $source_name             = Str::trim((string) ($source_description_row->name ?? ''));
        $source_description      = Str::trim((string) ($source_description_row->description ?? ''));
        $source_meta_title       = Str::trim((string) ($source_description_row->meta_title ?? $source_name));
        $source_meta_description = Str::trim((string) ($source_description_row->meta_description ?? $source_description));
        $source_meta_keywords    = Str::trim((string) ($source_description_row->meta_keywords ?? ''));

        foreach ($target_shop_languages as $shop_language) {
            $shop_language_id     = (int) $shop_language->id;
            $target_language_code = $this->normalizeLanguageCode((string) $shop_language->code);
            if ($shop_language_id <= 0 || $target_language_code === '') {
                continue;
            }

            $existing_description = ProductDescription::query()
                ->where('product_id', $product_id)
                ->where('shop_language_id', $shop_language_id)
                ->first();

            ProductDescription::query()->updateOrCreate(
                [
                    'product_id'       => $product_id,
                    'shop_language_id' => $shop_language_id,
                ],
                [
                    'name' => $this->translateText(
                        $product_id,
                        $source_name,
                        $target_default_language_code,
                        $target_language_code,
                        (string) ($existing_description->name ?? ''),
                        'productName'
                    ),
                    'description' => $this->translateText(
                        $product_id,
                        $source_description,
                        $target_default_language_code,
                        $target_language_code,
                        (string) ($existing_description->description ?? ''),
                        'productDescription'
                    ),
                    'meta_title' => $this->translateText(
                        $product_id,
                        $source_meta_title,
                        $target_default_language_code,
                        $target_language_code,
                        (string) ($existing_description->meta_title ?? ''),
                        'productName'
                    ),
                    'meta_description' => $this->translateText(
                        $product_id,
                        $source_meta_description,
                        $target_default_language_code,
                        $target_language_code,
                        (string) ($existing_description->meta_description ?? ''),
                        'productDescription'
                    ),
                    'meta_keywords' => $this->translateText(
                        $product_id,
                        $source_meta_keywords,
                        $target_default_language_code,
                        $target_language_code,
                        (string) ($existing_description->meta_keywords ?? ''),
                        'productName'
                    ),
                ]
            );
        }

        $this->synchronizeCategoryDescriptionsForShopLanguages(
            $product_id,
            $target_shop_languages->all(),
            $target_default_language_code,
            $target_default_shop_language_id
        );

        $source_attributes = $this->normalizeProductAttributesByDelimiterForShop(
            $product_id,
            $target_default_shop_language_id,
        );

        foreach ($source_attributes as $source_attribute) {
            $attribute_id = (int) ($source_attribute['attribute_id'] ?? 0);
            if ($attribute_id <= 0) {
                continue;
            }

            $source_attribute_name          = Str::trim((string) ($source_attribute['source_attribute_name'] ?? ''));
            $source_attribute_text          = Str::trim((string) ($source_attribute['source_attribute_text'] ?? ''));
            $source_attribute_language_code = $this->resolveSourceLanguageCodeByShopLanguageId(
                (int) ($source_attribute['source_shop_language_id'] ?? 0),
                $target_default_language_code
            );

            foreach ($target_shop_languages as $shop_language) {
                $shop_language_id     = (int) $shop_language->id;
                $target_language_code = $this->normalizeLanguageCode((string) $shop_language->code);
                if ($shop_language_id <= 0 || $target_language_code === '') {
                    continue;
                }

                $existing_attribute_description = AttributeDescription::query()
                    ->where('attribute_id', $attribute_id)
                    ->where('shop_language_id', $shop_language_id)
                    ->first();

                AttributeDescription::query()->updateOrCreate(
                    [
                        'attribute_id'     => $attribute_id,
                        'shop_language_id' => $shop_language_id,
                    ],
                    [
                        'name' => $this->translateText(
                            $product_id,
                            $source_attribute_name,
                            $source_attribute_language_code,
                            $target_language_code,
                            (string) ($existing_attribute_description->name ?? ''),
                            'attributeName',
                            $attribute_id
                        ),
                    ]
                );

                $existing_attribute_row = ProductToAttribute::query()
                    ->where('product_id', $product_id)
                    ->where('attribute_id', $attribute_id)
                    ->where('shop_language_id', $shop_language_id)
                    ->first();

                ProductToAttribute::query()->updateOrCreate(
                    [
                        'product_id'       => $product_id,
                        'attribute_id'     => $attribute_id,
                        'shop_language_id' => $shop_language_id,
                    ],
                    [
                        'text' => $this->translateText(
                            $product_id,
                            $source_attribute_text,
                            $source_attribute_language_code,
                            $target_language_code,
                            $this->resolveExistingTextForTranslation(
                                $source_attribute_text,
                                (string) ($existing_attribute_row->text ?? ''),
                                $source_attribute_language_code,
                                $target_language_code
                            ),
                            'productAttributeText',
                            $attribute_id
                        ),
                    ]
                );
            }
        }
    }

    /**
     * @return list<array{
     *     attribute_id:int,
     *     source_attribute_name:string,
     *     source_attribute_text:string,
     *     source_shop_language_id:int
     * }>
     */
    private function normalizeProductAttributesByDelimiterForShop(
        int $product_id,
        int $default_shop_language_id
    ): array {
        $source_rows = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', $default_shop_language_id)
            ->orderBy('id')
            ->get();

        if ($source_rows->isEmpty()) {
            $source_rows = ProductToAttribute::query()
                ->where('product_id', $product_id)
                ->whereNull('shop_language_id')
                ->orderBy('id')
                ->get();
        }

        $source_attributes_map = [];

        foreach ($source_rows as $source_row) {
            $source_attribute_id = (int) ($source_row->attribute_id ?? 0);
            if ($source_attribute_id <= 0) {
                continue;
            }

            $source_attribute_name = $this->resolveAttributeNameForLanguage(
                $source_attribute_id,
                $default_shop_language_id
            );

            $source_attribute_name_parts = $this->splitAttributePartsByPipe($source_attribute_name, true);
            $source_attribute_text_parts = $this->splitAttributePartsByPipe(
                (string) ($source_row->text ?? ''),
                false
            );

            if ($source_attribute_name_parts === []) {
                continue;
            }

            $created_attribute_ids = [];

            foreach ($source_attribute_name_parts as $part_index => $source_attribute_name_part) {
                $resolved_attribute_id = $this->resolveOrCreateAttributeIdByName(
                    $source_attribute_name_part,
                    $default_shop_language_id
                );

                $source_attribute_text_part = Str::trim(
                    $source_attribute_text_parts[$part_index] ?? $source_attribute_text_parts[0] ?? ''
                );

                ProductToAttribute::query()->updateOrCreate(
                    [
                        'product_id'       => $product_id,
                        'attribute_id'     => $resolved_attribute_id,
                        'shop_language_id' => $default_shop_language_id,
                    ],
                    [
                        'text' => $source_attribute_text_part,
                    ]
                );

                $source_attributes_map[$resolved_attribute_id] = [
                    'attribute_id'            => $resolved_attribute_id,
                    'source_attribute_name'   => $source_attribute_name_part,
                    'source_attribute_text'   => $source_attribute_text_part,
                    'source_shop_language_id' => $default_shop_language_id,
                ];

                $created_attribute_ids[] = $resolved_attribute_id;
            }

            $should_delete_source_attribute_rows = count(array_unique($created_attribute_ids)) > 1
                || ! in_array($source_attribute_id, $created_attribute_ids, true);

            if ($should_delete_source_attribute_rows) {
                ProductToAttribute::query()
                    ->where('product_id', $product_id)
                    ->where('attribute_id', $source_attribute_id)
                    ->delete();
            }
        }

        $default_language_rows = ProductToAttribute::query()
            ->where('product_id', $product_id)
            ->where('shop_language_id', $default_shop_language_id)
            ->orderBy('id')
            ->get();

        foreach ($default_language_rows as $default_language_row) {
            $attribute_id = (int) ($default_language_row->attribute_id ?? 0);
            if ($attribute_id <= 0 || array_key_exists($attribute_id, $source_attributes_map)) {
                continue;
            }

            $source_attributes_map[$attribute_id] = [
                'attribute_id'            => $attribute_id,
                'source_attribute_name'   => $this->resolveAttributeNameForLanguage($attribute_id, $default_shop_language_id),
                'source_attribute_text'   => Str::trim((string) ($default_language_row->text ?? '')),
                'source_shop_language_id' => $default_shop_language_id,
            ];
        }

        if ($source_attributes_map === []) {
            $fallback_rows = ProductToAttribute::query()
                ->where('product_id', $product_id)
                ->orderBy('id')
                ->get();

            foreach ($fallback_rows as $fallback_row) {
                $attribute_id = (int) ($fallback_row->attribute_id ?? 0);
                if ($attribute_id <= 0 || array_key_exists($attribute_id, $source_attributes_map)) {
                    continue;
                }

                $fallback_shop_language_id = (int) ($fallback_row->shop_language_id ?? 0);

                $source_attributes_map[$attribute_id] = [
                    'attribute_id'            => $attribute_id,
                    'source_attribute_name'   => $this->resolveAttributeNameForLanguage($attribute_id, $fallback_shop_language_id),
                    'source_attribute_text'   => Str::trim((string) ($fallback_row->text ?? '')),
                    'source_shop_language_id' => $fallback_shop_language_id,
                ];
            }
        }

        return array_values($source_attributes_map);
    }

    /**
     * @param  list<ShopLanguage>  $shop_languages
     *
     * @throws Throwable
     */
    private function synchronizeCategoryDescriptionsForShopLanguages(
        int $product_id,
        array $shop_languages,
        string $fallback_source_language_code,
        int $default_shop_language_id
    ): void {
        $category_ids = CategoryProduct::getUniqueCategoryIdsByProductId($product_id);

        if ($category_ids === []) {
            return;
        }

        foreach ($category_ids as $category_id) {
            $source_category_description = CategoryDescription::findSourceForCategory($category_id, $default_shop_language_id);

            if (! $source_category_description instanceof CategoryDescription) {
                continue;
            }

            $source_language_code = $this->resolveSourceLanguageCodeByShopLanguageId(
                (int) ($source_category_description->shop_language_id ?? 0),
                $fallback_source_language_code
            );

            $source_name             = Str::trim((string) ($source_category_description->name ?? ''));
            $source_description      = Str::trim((string) ($source_category_description->description ?? ''));
            $source_h1_title         = Str::trim((string) ($source_category_description->h1_title ?? $source_name));
            $source_meta_title       = Str::trim((string) ($source_category_description->meta_title ?? $source_name));
            $source_meta_description = Str::trim((string) ($source_category_description->meta_description ?? $source_description));
            $source_meta_keywords    = Str::trim((string) ($source_category_description->meta_keywords ?? ''));

            foreach ($shop_languages as $shop_language) {
                $shop_language_id     = (int) ($shop_language->id ?? 0);
                $target_language_code = $this->normalizeLanguageCode((string) ($shop_language->code ?? ''));
                if ($shop_language_id <= 0 || $target_language_code === '') {
                    continue;
                }

                $existing_category_description = CategoryDescription::query()
                    ->where('category_id', $category_id)
                    ->where('shop_language_id', $shop_language_id)
                    ->first();

                CategoryDescription::upsertByCategoryAndLanguage($category_id, $shop_language_id, [
                    'name' => $this->translateText(
                        $product_id,
                        $source_name,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->name ?? ''),
                        'categoryName',
                        $category_id
                    ),
                    'description' => $this->translateText(
                        $product_id,
                        $source_description,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->description ?? ''),
                        'categoryDescription',
                        0,
                        $category_id
                    ),
                    'h1_title' => $this->translateText(
                        $product_id,
                        $source_h1_title,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->h1_title ?? ''),
                        'productName'
                    ),
                    'meta_title' => $this->translateText(
                        $product_id,
                        $source_meta_title,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->meta_title ?? ''),
                        'productName'
                    ),
                    'meta_description' => $this->translateText(
                        $product_id,
                        $source_meta_description,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->meta_description ?? ''),
                        'categoryDescription',
                        0,
                        $category_id
                    ),
                    'meta_keywords' => $this->translateText(
                        $product_id,
                        $source_meta_keywords,
                        $source_language_code,
                        $target_language_code,
                        (string) ($existing_category_description->meta_keywords ?? ''),
                        'productName'
                    ),
                ]);
            }
        }
    }

    private function resolveSourceLanguageCodeByShopLanguageId(int $shop_language_id, string $fallback_language_code): string
    {
        if ($shop_language_id <= 0) {
            return $fallback_language_code;
        }

        $resolved_language_code = $this->normalizeLanguageCode(ShopLanguage::getCodeById($shop_language_id));

        return $resolved_language_code !== '' ? $resolved_language_code : $fallback_language_code;
    }

    private function resolveAttributeNameForLanguage(int $attribute_id, int $shop_language_id): string
    {
        return AttributeDescription::resolveNameByAttributeAndLanguage($attribute_id, $shop_language_id);
    }

    private function resolveExistingTextForTranslation(
        string $source_text,
        string $existing_text,
        string $source_language_code,
        string $target_language_code
    ): string {
        $source_text   = Str::trim($source_text);
        $existing_text = Str::trim($existing_text);

        if ($existing_text === '') {
            return '';
        }

        if ($source_text === '') {
            return $existing_text;
        }

        /**
         * During duplicated product binding old logic copied source text to every target language row.
         * If source and target languages differ and existing text still equals source text,
         * treat it as non-translated data and force translation.
         */
        $normalized_source_text   = $this->normalizeTextForComparison($source_text);
        $normalized_existing_text = $this->normalizeTextForComparison($existing_text);

        if (
            $source_language_code !== $target_language_code
            && $normalized_existing_text !== ''
            && $normalized_source_text !== ''
            && Str::lower($normalized_existing_text) === Str::lower($normalized_source_text)
        ) {
            Log::channel('stack')->debug('[FIX] Forcing attribute text re-translation for copied source value', [
                'source_language_code' => $source_language_code,
                'target_language_code' => $target_language_code,
            ]);

            return '';
        }

        return $existing_text;
    }

    private function normalizeTextForComparison(string $value): string
    {
        if (function_exists('normalize_str')) {
            return normalize_str($value);
        }

        $normalized = html_entity_decode(Str::trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $normalized = Str::replace("\u{00a0}", ' ', $normalized);

        return Str::replace('&nbsp;', ' ', $normalized);
    }

    /**
     * @return list<string>
     */
    private function splitAttributePartsByPipe(string $raw_value, bool $deduplicate): array
    {
        $raw_value = Str::trim($raw_value);

        if ($raw_value === '') {
            return [];
        }

        $parts = preg_split('/\s*\|\s*/u', $raw_value) ?: [];
        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => Str::trim($part), $parts),
            static fn (string $part): bool => $part !== ''
        ));

        if (! $deduplicate) {
            return $parts;
        }

        $unique_parts = [];
        $seen_parts   = [];

        foreach ($parts as $part) {
            $normalized_part = Str::lower($part);

            if (array_key_exists($normalized_part, $seen_parts)) {
                continue;
            }

            $seen_parts[$normalized_part] = true;
            $unique_parts[]               = $part;
        }

        return $unique_parts;
    }

    private function resolveOrCreateAttributeIdByName(string $attribute_name, int $default_shop_language_id): int
    {
        $clean_attribute_name = Str::trim($attribute_name);
        if ($clean_attribute_name === '') {
            return 0;
        }

        $attribute_id = AttributeDescription::findAttributeIdByNameForLanguage(
            $clean_attribute_name,
            $default_shop_language_id
        );

        if ($attribute_id <= 0) {
            $attribute = Attribute::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $attribute_id = (int) $attribute->id;
        }

        AttributeDescription::upsertName($attribute_id, $default_shop_language_id, $clean_attribute_name);

        return $attribute_id;
    }

    /**
     * @throws Throwable
     */
    private function translateText(
        int $product_id,
        string $source_text,
        string $source_language_code,
        string $target_language_code,
        string $existing_text,
        string $translation_method,
        int $attribute_id = 0,
        int $category_id = 0,
        int $brand_id = 0,
        int $manufacturer_id = 0
    ): string {
        $existing_text = Str::trim($existing_text);
        if ($existing_text !== '') {
            return $existing_text;
        }

        $source_text = Str::trim($source_text);
        if ($source_text === '') {
            return '';
        }

        if ($source_language_code === $target_language_code) {
            return $source_text;
        }

        $prompt = app(AiTranslationPromptBuilderService::class)
            ->buildTranslatePrompt($source_text, $source_language_code, $target_language_code);

        try {
            $ai_translation_service = app(AiTranslationService::class);

            return match ($translation_method) {
                'attributeName'           => $ai_translation_service->attributeName(max($attribute_id, 1), $prompt),
                'attributeDescription'    => $ai_translation_service->attributeDescription(max($attribute_id, 1), $prompt),
                'productName'             => $ai_translation_service->productName($product_id, $prompt),
                'productDescription'      => $ai_translation_service->productDescription($product_id, $prompt),
                'productAttributeText'    => $ai_translation_service->productAttributeText($product_id, max($attribute_id, 1), $prompt),
                'categoryName'            => $ai_translation_service->categoryName(max(($category_id > 0 ? $category_id : $attribute_id), 1), $prompt),
                'categoryDescription'     => $ai_translation_service->categoryDescription(max(($category_id > 0 ? $category_id : $attribute_id), 1), $prompt),
                'brandName'               => $ai_translation_service->brandName(max($brand_id, 1), $prompt),
                'brandDescription'        => $ai_translation_service->brandDescription(max($brand_id, 1), $prompt),
                'manufacturerName'        => $ai_translation_service->manufacturerName(max($manufacturer_id, 1), $prompt),
                'manufacturerDescription' => $ai_translation_service->manufacturerDescription(max($manufacturer_id, 1), $prompt),
                default                   => $source_text,
            };
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Product bind translation failed', [
                'product_id'           => $product_id,
                'source_language_code' => $source_language_code,
                'target_language_code' => $target_language_code,
                'method'               => $translation_method,
                'message'              => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function generateSeoKeywordForLanguage(string $base_text, string $language_code): string
    {
        return app(ProductSeoKeywordService::class)->make($base_text, $language_code);
    }

    /**
     * @param  list<int>  $shop_ids
     * @return list<int>
     */
    private function normalizeShopIds(array $shop_ids): array
    {
        return $this->normalizePositiveIntList($shop_ids, true);
    }
}
