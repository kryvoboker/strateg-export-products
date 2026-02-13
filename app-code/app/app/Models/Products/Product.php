<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Attributes\Attribute;
use App\Models\Categories\Category;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'marked_to_shop',
        'model',
        'sku',
        'ean',
        'quantity',
        'minimum',
        'image',
        'price',
        'is_active',
        'date_available',
        'date_added',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'minimum' => 'integer',
            'price' => 'decimal:4',
            'is_active' => 'boolean',
            'date_available' => 'datetime',
            'date_added' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProductDescription>
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    /**
     * @return HasMany<ProductImage>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * @return HasMany<ProductSpecial>
     */
    public function specials(): HasMany
    {
        return $this->hasMany(ProductSpecial::class);
    }

    /**
     * @return HasMany<ProductDiscount>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(ProductDiscount::class);
    }

    /**
     * @return HasMany<ProductToAttribute>
     */
    public function productToAttributes(): HasMany
    {
        return $this->hasMany(ProductToAttribute::class);
    }

    /**
     * @return HasMany<ProductImportItem>
     */
    public function importItems(): HasMany
    {
        return $this->hasMany(ProductImportItem::class);
    }

    /**
     * @return HasMany<ProductShop>
     */
    public function productShops(): HasMany
    {
        return $this->hasMany(ProductShop::class);
    }

    /**
     * @return HasMany<ProductExportItem>
     */
    public function exportItems(): HasMany
    {
        return $this->hasMany(ProductExportItem::class);
    }

    /**
     * @return HasMany<ProductNameHash>
     */
    public function nameHashes(): HasMany
    {
        return $this->hasMany(ProductNameHash::class);
    }

    /**
     * @return HasMany<ProductDescriptionHash>
     */
    public function descriptionHashes(): HasMany
    {
        return $this->hasMany(ProductDescriptionHash::class);
    }

    /**
     * @return HasMany<ProductAttributeTextHash>
     */
    public function attributeTextHashes(): HasMany
    {
        return $this->hasMany(ProductAttributeTextHash::class);
    }

    /**
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'product_shop')
            ->withPivot('external_product_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Attribute, $this>
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'product_to_attributes', 'product_id', 'attribute_id')
            ->withPivot('shop_language_id', 'text')
            ->withTimestamps();
    }

    public static function isIdExists(int $product_id): bool
    {
        return static::query()->whereKey($product_id)->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createFromImportPayload(array $attributes, ?int $product_id = null): int
    {
        $now_date = get_now_date();
        $attributes['created_at'] = $now_date;
        $attributes['updated_at'] = $now_date;

        if ($product_id !== null) {
            $attributes['id'] = $product_id;

            static::query()->insert($attributes);

            return $product_id;
        }

        return static::query()->insertGetId($attributes);
    }

    public function getProductNameAttribute(): string
    {
        return $this->getNameForFilamentPage();
    }
}
