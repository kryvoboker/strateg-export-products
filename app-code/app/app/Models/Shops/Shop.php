<?php

declare(strict_types=1);

namespace App\Models\Shops;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeShop;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryShop;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'type',
        'base_url',
        'api_url',
        'api_token',
        'part_api_url_login',
        'part_api_url_export_prods',
        'part_api_url_update_prods',
        'part_api_url_restore_prods',
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
     * @return HasMany<ShopLanguage, $this>
     */
    public function shopLanguage(): HasMany
    {
        return $this->hasMany(ShopLanguage::class);
    }

    /**
     * @return HasMany<UserGroupToShop, $this>
     */
    public function userGroups(): HasMany
    {
        return $this->hasMany(UserGroupToShop::class);
    }

    /**
     * @return HasMany<ProductShop, $this>
     */
    public function productShops(): HasMany
    {
        return $this->hasMany(ProductShop::class);
    }

    /**
     * @return HasMany<AttributeShop, $this>
     */
    public function attributeShops(): HasMany
    {
        return $this->hasMany(AttributeShop::class);
    }

    /**
     * @return HasMany<CategoryShop, $this>
     */
    public function categoryShops(): HasMany
    {
        return $this->hasMany(CategoryShop::class);
    }

    /**
     * @return HasMany<ManufacturerShop, $this>
     */
    public function manufacturerShops(): HasMany
    {
        return $this->hasMany(ManufacturerShop::class);
    }

    /**
     * @return HasMany<BrandShop, $this>
     */
    public function brandShops(): HasMany
    {
        return $this->hasMany(BrandShop::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_shop')
            ->withPivot('external_product_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'attribute_shop', 'shop_id', 'attribute_id')
            ->withPivot('external_attribute_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_shop')
            ->withPivot('external_category_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Manufacturer, $this>
     */
    public function manufacturers(): BelongsToMany
    {
        return $this->belongsToMany(Manufacturer::class, 'manufacturer_shop', 'shop_id', 'manufacturer_id')
            ->withPivot('external_manufacturer_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_shop', 'shop_id', 'brand_id')
            ->withPivot('external_brand_id')
            ->withTimestamps();
    }
}
