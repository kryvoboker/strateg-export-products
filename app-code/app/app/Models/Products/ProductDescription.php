<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDescription extends Model
{
    protected $fillable = [
        'product_id',
        'shop_language_id',
        'name',
        'description',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id'       => 'integer',
            'shop_language_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    /**
     * @return Collection<int, self>
     */
    public static function getByProductId(int $product_id): Collection
    {
        if ($product_id <= 0) {
            return new Collection();
        }

        return self::query()
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->get();
    }
}
