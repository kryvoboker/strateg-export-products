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
use Illuminate\Support\Facades\Cache;
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
            'shop_id'    => 'integer',
            'is_active'  => 'boolean',
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
            self::flushLanguageCacheForShop((int) $shop_language->shop_id, (string) $shop_language->code);

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
            self::flushLanguageCacheForShop((int) $shop_language->shop_id, (string) $shop_language->code);

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
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasMany<ProductDescription, $this>
     */
    public function productDescriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    /**
     * @return HasMany<ProductToAttribute, $this>
     */
    public function productToAttributes(): HasMany
    {
        return $this->hasMany(ProductToAttribute::class);
    }

    /**
     * @return HasMany<AttributeDescription, $this>
     */
    public function attributeDescriptions(): HasMany
    {
        return $this->hasMany(AttributeDescription::class);
    }

    /**
     * @return HasMany<CategoryDescription, $this>
     */
    public function categoryDescriptions(): HasMany
    {
        return $this->hasMany(CategoryDescription::class);
    }

    /**
     * @return HasMany<SeoUrl, $this>
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

    /**
     * @return Collection<int, self>
     */
    public static function getActiveByShopIdCached(int $shop_id): Collection
    {
        if ($shop_id <= 0) {
            return self::query()->whereRaw('1 = 0')->get();
        }

        if (! app()->bound('cache')) {
            return self::getActiveByShopId($shop_id);
        }

        $cache_key = 'shop-language:active-by-shop-id:'.$shop_id;

        /** @var array<int, array{id:int,code:string,name:string,is_default:bool,is_active:bool,shop_id:int}> $cached_rows */
        $cached_rows = Cache::remember($cache_key, now()->addMinutes(15), static function () use ($shop_id): array {
            return self::query()
                ->where('shop_id', $shop_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'shop_id', 'code', 'name', 'is_default', 'is_active'])
                ->map(static fn (self $shop_language): array => [
                    'id'         => (int) $shop_language->id,
                    'shop_id'    => (int) $shop_language->shop_id,
                    'code'       => (string) $shop_language->code,
                    'name'       => (string) $shop_language->name,
                    'is_default' => (bool) $shop_language->is_default,
                    'is_active'  => (bool) $shop_language->is_active,
                ])
                ->all();
        });

        return (new self())->newCollection(
            collect($cached_rows)
                ->map(static fn (array $row): self => new self($row))
                ->all()
        );
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

    /**
     * @return list<int>
     */
    public static function resolveOrderedLanguageIdsByCode(string $language_code, int $shop_id): array
    {
        $normalized_language_code = Str::lower(Str::trim($language_code));
        if ($normalized_language_code === '') {
            return [];
        }

        return self::query()
            ->whereRaw('LOWER(code) = ?', [$normalized_language_code])
            ->orderByRaw('CASE WHEN shop_id = ? THEN 0 ELSE 1 END', [$shop_id])
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $shop_language_id): int => (int) $shop_language_id)
            ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public static function resolveOrderedLanguageIdsByCodeCached(string $language_code, int $shop_id): array
    {
        $normalized_language_code = Str::lower(Str::trim($language_code));
        if ($normalized_language_code === '') {
            return [];
        }

        if (! app()->bound('cache')) {
            return self::resolveOrderedLanguageIdsByCode($normalized_language_code, $shop_id);
        }

        $cache_key = sprintf(
            'shop-language:ordered-language-ids:code:%s:shop:%d',
            $normalized_language_code,
            $shop_id
        );

        /** @var list<int> $language_ids */
        $language_ids = Cache::remember($cache_key, now()->addMinutes(15), static fn (): array => self::resolveOrderedLanguageIdsByCode($normalized_language_code, $shop_id));

        return $language_ids;
    }

    private static function flushLanguageCacheForShop(int $shop_id, string $language_code): void
    {
        if ($shop_id <= 0) {
            return;
        }

        if (! app()->bound('cache')) {
            return;
        }

        Cache::forget('shop-language:active-by-shop-id:'.$shop_id);

        $normalized_language_code = Str::lower(Str::trim($language_code));
        if ($normalized_language_code !== '') {
            Cache::forget(sprintf(
                'shop-language:ordered-language-ids:code:%s:shop:%d',
                $normalized_language_code,
                $shop_id
            ));
        }
    }
}
