<?php

declare(strict_types=1);

namespace App\Models\Brands;

use App\Models\Products\Product;
use App\Models\Products\ProductToManufacturerBrand;
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

class Brand extends Model
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
        static::creating(function (self $brand): void {
            if (Str::trim((string) $brand->getAttribute('family_ulid')) === '') {
                $brand->setAttribute('family_ulid', (string) Str::ulid());
            }
        });

        static::deleting(function (self $brand) {
            $brand->aiTranslationCaches()->delete();
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

        $target_manufacturer = self::findByFamilyAndShop($source_family_ulid, $target_shop_id);

        if ($target_manufacturer instanceof self) {
            return $target_manufacturer;
        }

        $source_shop_id = (int) ($this->getAttribute('shop_id') ?? 0);

        if ($source_shop_id <= 0) {
            $this->update([
                'shop_id' => $target_shop_id,
            ]);

            return $this->fresh() ?? $this;
        }

        $source_manufacturer_name = $this->descriptions()
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
            throw new RuntimeException('Failed to duplicate brand for target shop.');
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
            'name'             => $source_manufacturer_name,
        ]);

        return $duplicate;
    }
}
