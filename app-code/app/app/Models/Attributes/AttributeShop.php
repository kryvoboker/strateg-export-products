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
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @param  list<int>  $attribute_ids
     * @return array<int, int|null>
     */
    public static function resolveExternalIdMapByAttributeIds(int $shop_id, array $attribute_ids): array
    {
        if ($shop_id <= 0 || $attribute_ids === []) {
            return [];
        }

        return self::query()
            ->where('shop_id', $shop_id)
            ->whereIn('attribute_id', $attribute_ids)
            ->pluck('external_attribute_id', 'attribute_id')
            ->mapWithKeys(static fn ($external_attribute_id, $attribute_id): array => [
                (int) $attribute_id => is_numeric($external_attribute_id) ? (int) $external_attribute_id : null,
            ])
            ->toArray();
    }
}
