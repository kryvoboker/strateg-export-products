<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Products\Product;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $category): void {
            $category->children()->get()->each(static function (self $child_category): void {
                $child_category->delete();
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id'  => 'integer',
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /**
     * @return HasMany<CategoryDescription, $this>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    /**
     * @return HasMany<CategoryShop, $this>
     */
    public function categoryShops(): HasMany
    {
        return $this->hasMany(CategoryShop::class);
    }

    /**
     * @return HasMany<CategoryProduct, $this>
     */
    public function categoryProducts(): HasMany
    {
        return $this->hasMany(CategoryProduct::class);
    }

    /**
     * @return HasMany<CategoryNameHash, $this>
     */
    public function nameHashes(): HasMany
    {
        return $this->hasMany(CategoryNameHash::class, 'category_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'category_shop')
            ->withPivot('external_category_id')
            ->withTimestamps();
    }

    public function getCategoryNameAttribute(): string
    {
        return $this->getNameForFilamentPage();
    }
}
