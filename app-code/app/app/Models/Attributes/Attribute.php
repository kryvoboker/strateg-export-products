<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use App\Models\Products\Product;
use App\Models\Products\ProductToAttribute;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $attribute): void {
            $attribute->productToAttributes()->delete();
        });
    }

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
     * @return HasMany<AttributeDescription, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(AttributeDescription::class, 'attribute_id');
    }

    /**
     * @return HasMany<ProductToAttribute, $this>
     */
    public function productToAttributes(): HasMany
    {
        return $this->hasMany(ProductToAttribute::class, 'attribute_id');
    }

    /**
     * @return HasMany<AttributeShop, $this>
     */
    public function attributeShops(): HasMany
    {
        return $this->hasMany(AttributeShop::class, 'attribute_id');
    }

    /**
     * @return HasMany<AttributeNameHash, $this>
     */
    public function nameHashes(): HasMany
    {
        return $this->hasMany(AttributeNameHash::class, 'attribute_id');
    }

    /**
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'attribute_shop', 'attribute_id', 'shop_id')
            ->withPivot('external_attribute_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_to_attributes', 'attribute_id', 'product_id')
            ->withPivot('shop_language_id', 'text')
            ->withTimestamps();
    }

    public function getAttributeNameAttribute(): string
    {
        return $this->getNameForFilamentPage();
    }
}
