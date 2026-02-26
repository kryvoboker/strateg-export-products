<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Shops\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductShop extends Model
{
    protected $table = 'product_shop';

    protected $fillable = [
        'product_import_batch_id',
        'product_id',
        'shop_id',
        'external_product_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_import_batch_id' => 'integer',
            'product_id'              => 'integer',
            'shop_id'                 => 'integer',
            'external_product_id'     => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'product_import_batch_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public static function isProductBoundToShop(int $product_id, int $shop_id): bool
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return false;
        }

        return self::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->exists();
    }

    public static function updateExternalProductId(int $product_id, int $shop_id, int $external_product_id): int
    {
        if ($product_id <= 0 || $shop_id <= 0 || $external_product_id <= 0) {
            return 0;
        }

        return self::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->update([
                'external_product_id' => $external_product_id,
            ]);
    }

    public static function resolveExternalProductId(int $product_id, int $shop_id): int
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return 0;
        }

        return (int) (self::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->value('external_product_id') ?? 0);
    }

    public function scopeForProductAndShop(Builder $query, int $product_id, int $shop_id): Builder
    {
        return $query
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id);
    }

    public static function resolveLatestByProductAndShop(int $product_id, int $shop_id): ?self
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $product_shop = self::query()
            ->forProductAndShop($product_id, $shop_id)
            ->orderByDesc('id')
            ->first();

        return $product_shop instanceof self ? $product_shop : null;
    }

    public static function resolveLatestBoundProductIdByFamilyUlidAndShop(string $family_ulid, int $shop_id): int
    {
        $normalized_family_ulid = Str::trim($family_ulid);
        if ($shop_id <= 0 || $normalized_family_ulid === '') {
            return 0;
        }

        return (int) (self::query()
            ->join('products', 'products.id', '=', 'product_shop.product_id')
            ->where('products.family_ulid', $normalized_family_ulid)
            ->where('shop_id', $shop_id)
            ->orderByDesc('product_shop.id')
            ->value('product_shop.product_id') ?? 0);
    }

    public static function resolveLatestBatchIdByProductId(int $product_id): int
    {
        if ($product_id <= 0) {
            return 0;
        }

        return (int) (self::query()
            ->where('product_id', $product_id)
            ->orderByDesc('id')
            ->value('product_import_batch_id') ?? 0);
    }
}
