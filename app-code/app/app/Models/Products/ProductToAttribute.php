<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Attributes\ProductAttribute;
use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductToAttribute extends Model
{
    protected $table = 'product_to_attributes';

    protected $fillable = [
        'product_id',
        'attribute_id',
        'shop_language_id',
        'text',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'attribute_id' => 'integer',
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
     * @return BelongsTo<ProductAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class);
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    /**
     * @return list<int>
     */
    public static function getUniqueAttributeIdsByProductId(int $product_id): array
    {
        if ($product_id <= 0) {
            return [];
        }

        return self::query()
            ->where('product_id', $product_id)
            ->pluck('attribute_id')
            ->filter()
            ->map(static fn ($attribute_id): int => (int) $attribute_id)
            ->unique()
            ->values()
            ->all();
    }
}
