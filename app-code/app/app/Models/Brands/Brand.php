<?php

declare(strict_types=1);

namespace App\Models\Brands;

use App\Models\Products\Product;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
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
     * @return HasMany<BrandDescription, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(BrandDescription::class, 'brand_id');
    }

    /**
     * @return HasMany<BrandShop, $this>
     */
    public function brandShops(): HasMany
    {
        return $this->hasMany(BrandShop::class, 'brand_id');
    }

    /**
     * @return HasMany<ProductToManufacturerBrand, $this>
     */
    public function productBindings(): HasMany
    {
        return $this->hasMany(ProductToManufacturerBrand::class, 'brand_id');
    }

    /**
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'brand_shop', 'brand_id', 'shop_id')
            ->withPivot('external_brand_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_to_manufacturer_brand', 'brand_id', 'product_id')
            ->withPivot('manufacturer_id')
            ->withTimestamps();
    }

    public function getBrandNameAttribute(): string
    {
        return $this->getNameForFilamentPage();
    }
}
