<?php

declare(strict_types=1);

namespace App\Models\Shops;

use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\CategoryDescription;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductToAttribute;
use App\Models\Seo\SeoUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopLanguage extends Model
{
    protected $fillable = [
        'shop_id',
        'code',
        'name',
        'is_active',
        'is_default',
    ];

    /**
     * @return string[]
     */
    protected function casts(): array
    {
        return [
            'shop_id' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $shop_language): void {
            if ((int) $shop_language->shop_id <= 0) {
                return;
            }

            $has_default_language = self::query()
                ->where('shop_id', (int) $shop_language->shop_id)
                ->where('is_default', true)
                ->exists();

            if (! $has_default_language) {
                $shop_language->is_default = true;
            }
        });

        static::saving(function (self $shop_language): void {
            if ((int) $shop_language->shop_id <= 0) {
                return;
            }

            if ((bool) $shop_language->is_default) {
                $shop_language->is_active = true;

                return;
            }

            $has_another_default_language = self::query()
                ->where('shop_id', (int) $shop_language->shop_id)
                ->where('is_default', true)
                ->when(
                    $shop_language->exists,
                    static fn ($query) => $query->where('id', '!=', (int) $shop_language->id),
                )
                ->exists();

            if (! $has_another_default_language) {
                throw ValidationException::withMessages([
                    'is_default' => __('admin/shops/languages.errors.at_least_one_default'),
                ]);
            }
        });

        static::saved(function (self $shop_language): void {
            if (! (bool) $shop_language->is_default) {
                return;
            }

            self::query()
                ->where('shop_id', (int) $shop_language->shop_id)
                ->where('id', '!=', (int) $shop_language->id)
                ->where('is_default', true)
                ->update([
                    'is_default' => false,
                ]);
        });

        static::deleting(function (self $shop_language): void {
            if (! (bool) $shop_language->is_default) {
                return;
            }

            $has_another_default_language = self::query()
                ->where('shop_id', (int) $shop_language->shop_id)
                ->where('id', '!=', (int) $shop_language->id)
                ->where('is_default', true)
                ->exists();

            if (! $has_another_default_language) {
                throw ValidationException::withMessages([
                    'is_default' => __('admin/shops/languages.errors.at_least_one_default'),
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<Shop>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<ProductDescription>
     */
    public function productDescriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    /**
     * @return HasMany<ProductToAttribute>
     */
    public function productToAttributes(): HasMany
    {
        return $this->hasMany(ProductToAttribute::class);
    }

    /**
     * @return HasMany<AttributeDescription>
     */
    public function attributeDescriptions(): HasMany
    {
        return $this->hasMany(AttributeDescription::class);
    }

    /**
     * @return HasMany<CategoryDescription>
     */
    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    /**
     * @return HasMany<SeoUrl>
     */
    public function seoUrls(): HasMany
    {
        return $this->hasMany(SeoUrl::class);
    }

    /**
     * @return list<string>
     */
    public static function getActiveCodes(): array
    {
        return self::query()
            ->where('is_active', true)
            ->pluck('code')
            ->filter()
            ->map(static fn ($code) => Str::lower(Str::trim((string) $code)))
            ->unique()
            ->values()
            ->all();
    }

    public static function getDefaultLanguageIdByShopId(int $shop_id): int
    {
        if ($shop_id <= 0) {
            return 0;
        }

        return (int) (self::query()
            ->where('shop_id', $shop_id)
            ->where('is_default', true)
            ->value('id') ?? 0);
    }

    /**
     * @return Collection<int, self>
     */
    public static function getActiveByShopId(int $shop_id): Collection
    {
        if ($shop_id <= 0) {
            return self::query()->whereRaw('1 = 0')->get();
        }

        return self::query()
            ->where('shop_id', $shop_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'is_default']);
    }

    public static function getCodeById(int $shop_language_id): string
    {
        if ($shop_language_id <= 0) {
            return '';
        }

        return Str::lower(Str::trim((string) (self::query()
            ->whereKey($shop_language_id)
            ->value('code') ?? '')));
    }
}
