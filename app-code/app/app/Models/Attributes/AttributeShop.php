<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use App\Models\Shops\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeShop extends Model
{
    protected $table = 'attribute_shop';

    protected $fillable = [
        'attribute_id',
        'shop_id',
        'external_attribute_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_id'          => 'integer',
            'shop_id'               => 'integer',
            'external_attribute_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
