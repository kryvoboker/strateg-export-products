<?php

declare(strict_types=1);

namespace App\Models\Shops;

use App\Models\Attributes\AttributeShop;
use App\Models\Attributes\ProductAttribute;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryShop;
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
            'options' => 'array',
        ];
    }

    /**
     * @return HasMany<ShopLanguage>
     */
    public function shopLanguage(): HasMany
    {
        return $this->hasMany(ShopLanguage::class);
    }

    /**
     * @return HasMany<UserGroupToShop>
     */
    public function userGroups(): HasMany
    {
        return $this->hasMany(UserGroupToShop::class);
    }

    /**
     * @return HasMany<ProductShop>
     */
    public function productShops(): HasMany
    {
        return $this->hasMany(ProductShop::class);
    }

    /**
     * @return HasMany<AttributeShop>
     */
    public function attributeShops(): HasMany
    {
        return $this->hasMany(AttributeShop::class);
    }

    /**
     * @return HasMany<CategoryShop>
     */
    public function categoryShops(): HasMany
    {
        return $this->hasMany(CategoryShop::class);
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
     * @return BelongsToMany<ProductAttribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttribute::class, 'attribute_shop', 'shop_id', 'attribute_id')
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
}
