<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use App\Models\Products\Product;
use App\Models\Products\ProductToAttribute;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Models\Trait\AiTranslationCacheRelationTrait;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

class Attribute extends Model
{
    use AiTranslationCacheRelationTrait, DescriptionsTrait;

    protected $fillable = [
        'family_ulid',
        'shop_id',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attribute): void {
            if (Str::trim((string) $attribute->getAttribute('family_ulid')) === '') {
                $attribute->setAttribute('family_ulid', (string) Str::ulid());
            }
        });

        static::deleting(function (self $attribute): void {
            $attribute->aiTranslationCaches()->delete();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shop_id'    => 'integer',
            'sort_order' => 'integer',
            'is_active'  => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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

    public static function findByFamilyAndShop(string $family_ulid, int $shop_id): ?self
    {
        $family_ulid = Str::trim($family_ulid);
        if ($family_ulid === '' || $shop_id <= 0) {
            return null;
        }

        return self::query()
            ->where('family_ulid', $family_ulid)
            ->where('shop_id', $shop_id)
            ->first();
    }

    public function duplicateForShop(int $target_shop_id): self
    {
        if ($target_shop_id <= 0) {
            return $this;
        }

        $source_family_ulid = Str::trim((string) $this->getAttribute('family_ulid'));

        if ($source_family_ulid === '') {
            $source_family_ulid = (string) Str::ulid();
            $this->update([
                'family_ulid' => $source_family_ulid,
            ]);
        }

        $target_existing = self::findByFamilyAndShop($source_family_ulid, $target_shop_id);

        if ($target_existing instanceof self) {
            return $target_existing;
        }

        $source_shop_id = (int) ($this->getAttribute('shop_id') ?? 0);

        if ($source_shop_id <= 0) {
            $this->update([
                'shop_id' => $target_shop_id,
            ]);

            return $this->fresh() ?? $this;
        }

        $source_attribute_name = $this->descriptions()
            ->where('shop_language_id', $source_shop_id)
            ->value('name') ?? '';

        /** @var self $duplicate */
        $duplicate = self::query()->create([
            'family_ulid' => $source_family_ulid,
            'shop_id'     => $target_shop_id,
            'sort_order'  => (int) $this->sort_order,
            'is_active'   => (bool) $this->is_active,
        ]);

        if (! $duplicate instanceof self) {
            throw new RuntimeException('Failed to duplicate attribute for target shop.');
        }

        $target_default_shop_language_id = ShopLanguage::query()
            ->where('shop_id', $target_shop_id)
            ->where('is_default', true)
            ->value('id') ?? 0;

        if ($target_default_shop_language_id <= 0) {
            throw new RuntimeException('Target default shop language not found for shop ID: '.$target_shop_id);
        }

        $duplicate->descriptions()->create([
            'shop_language_id' => $target_default_shop_language_id,
            'name'             => $source_attribute_name,
        ]);

        return $duplicate;
    }
}
