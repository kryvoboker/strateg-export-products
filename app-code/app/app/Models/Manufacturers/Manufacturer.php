<?php

declare(strict_types=1);

namespace App\Models\Manufacturers;

use App\Models\Products\Product;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manufacturer extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /**
     * @return HasMany<ManufacturerDescription, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ManufacturerDescription::class, 'manufacturer_id');
    }

    /**
     * @return HasMany<ManufacturerShop, $this>
     */
    public function manufacturerShops(): HasMany
    {
        return $this->hasMany(ManufacturerShop::class, 'manufacturer_id');
    }

    /**
     * @return HasMany<ProductToManufacturerBrand, $this>
     */
    public function productBindings(): HasMany
    {
        return $this->hasMany(ProductToManufacturerBrand::class, 'manufacturer_id');
    }

    /**
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'manufacturer_shop', 'manufacturer_id', 'shop_id')
            ->withPivot('external_manufacturer_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_to_manufacturer_brand', 'manufacturer_id', 'product_id')
            ->withPivot('brand_id')
            ->withTimestamps();
    }

    public function getManufacturerNameAttribute(): string
    {
        return $this->getNameForFilamentPage();
    }
}
