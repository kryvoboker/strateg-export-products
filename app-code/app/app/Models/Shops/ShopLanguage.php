<?php

declare(strict_types=1);

namespace App\Models\Shops;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopLanguage extends Model
{
    protected $fillable = [
        'shop_id',
        'code',
        'name',
        'is_active',
    ];

    /**
     * @return string[]
     */
    protected function casts(): array
    {
        return [
            'shop_id'   => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Shop>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
