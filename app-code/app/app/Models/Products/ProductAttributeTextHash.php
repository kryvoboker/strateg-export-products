<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Attributes\ProductAttribute;
use App\Models\Trait\OpenAiRelationsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeTextHash extends Model
{
    use OpenAiRelationsTrait;

    protected $fillable = [
        'product_id',
        'attribute_id',
        'hash',
    ];

    /**
     * @return string[]
     */
    protected function casts(): array
    {
        return [
            'product_id'   => 'integer',
            'attribute_id' => 'integer',
            'hash'         => 'string',
        ];
    }

    /**
     * @return BelongsTo<Product>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductAttribute>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    public static function getAttributeTextHash(int $product_id, int $attribute_id, string $hash): ?self
    {
        return self::query()
            ->where('product_id', $product_id)
            ->where('attribute_id', $attribute_id)
            ->where('hash', $hash)
            ->first();
    }
}
