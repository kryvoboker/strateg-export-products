<?php

declare(strict_types=1);

namespace App\Models\Manufacturers;

use App\Models\Shops\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturerShop extends Model
{
    protected $table = 'manufacturer_shop';

    protected $fillable = [
        'manufacturer_id',
        'shop_id',
        'external_manufacturer_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manufacturer_id'          => 'integer',
            'shop_id'                  => 'integer',
            'external_manufacturer_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Manufacturer, $this>
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public static function resolveExternalManufacturerId(int $manufacturer_id, int $shop_id): int
    {
        if ($manufacturer_id <= 0 || $shop_id <= 0) {
            return 0;
        }

        return (int) (self::query()
            ->where('manufacturer_id', $manufacturer_id)
            ->where('shop_id', $shop_id)
            ->value('external_manufacturer_id') ?? 0);
    }
}
