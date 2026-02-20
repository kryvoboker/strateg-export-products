<?php

declare(strict_types=1);

namespace App\Models\Manufacturers;

use App\Models\Products\Product;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Shops\Shop;
use App\Models\Trait\DescriptionsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Manufacturer extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'family_ulid',
        'shop_id',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $manufacturer): void {
            if (! self::hasFamilyUlidColumn()) {
                return;
            }

            if (Str::trim((string) $manufacturer->getAttribute('family_ulid')) === '') {
                $manufacturer->setAttribute('family_ulid', (string) Str::ulid());
            }
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

    public function duplicateForShop(int $shop_id): self
    {
        if ($shop_id <= 0) {
            return $this;
        }

        if (! self::hasFamilyUlidColumn()) {
            return $this;
        }

        $family_ulid = Str::trim((string) $this->getAttribute('family_ulid'));
        if ($family_ulid === '') {
            $family_ulid = (string) Str::ulid();
            $this->update([
                'family_ulid' => $family_ulid,
            ]);
        }

        $existing = self::findByFamilyAndShop($family_ulid, $shop_id);
        if ($existing instanceof self) {
            return $existing;
        }

        $current_shop_id = (int) ($this->getAttribute('shop_id') ?? 0);
        if ($current_shop_id <= 0) {
            $this->update([
                'shop_id' => $shop_id,
            ]);

            return $this->fresh() ?? $this;
        }

        $duplicate = self::query()->create([
            'family_ulid' => $family_ulid,
            'shop_id'     => $shop_id,
            'sort_order'  => (int) $this->sort_order,
            'is_active'   => (bool) $this->is_active,
        ]);

        $this->descriptions()
            ->orderBy('id')
            ->get()
            ->each(function (ManufacturerDescription $description) use ($duplicate): void {
                ManufacturerDescription::query()->updateOrCreate(
                    [
                        'manufacturer_id'  => (int) $duplicate->id,
                        'shop_language_id' => $description->shop_language_id,
                    ],
                    [
                        'name' => $description->name,
                    ]
                );
            });

        return $duplicate;
    }

    private static function hasFamilyUlidColumn(): bool
    {
        try {
            $model = new self();

            return $model->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($model->getTable(), 'family_ulid');
        } catch (\Throwable) {
            return false;
        }
    }
}
