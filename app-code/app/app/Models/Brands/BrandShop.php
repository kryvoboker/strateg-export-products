<?php

declare(strict_types=1);

namespace App\Models\Brands;

use App\Models\Shops\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandShop extends Model
{
    protected $table = 'brand_shop';

    protected $fillable = [
        'brand_id',
        'shop_id',
        'external_brand_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brand_id'          => 'integer',
            'shop_id'           => 'integer',
            'external_brand_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public static function resolveExternalBrandId(int $brand_id, int $shop_id): int
    {
        if ($brand_id <= 0 || $shop_id <= 0) {
            return 0;
        }

        return (int) (self::query()
            ->where('brand_id', $brand_id)
            ->where('shop_id', $shop_id)
            ->value('external_brand_id') ?? 0);
    }
}
