<?php

declare(strict_types=1);

namespace App\Models\Shops;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'type',
        'base_url',
        'is_active',
        'options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'options'   => 'array',
        ];
    }

    /**
     * @return HasMany<ShopLanguage>
     */
    public function shopLanguage(): HasMany
    {
        return $this->hasMany(ShopLanguage::class);
    }
}
