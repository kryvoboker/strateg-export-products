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
use Illuminate\Support\Str;

class Category extends Model
{
    use DescriptionsTrait;

    protected $fillable = [
        'family_ulid',
        'shop_id',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (! self::hasFamilyUlidColumn()) {
                return;
            }

            if (Str::trim((string) $category->getAttribute('family_ulid')) === '') {
                $category->setAttribute('family_ulid', (string) Str::ulid());
            }
        });

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
            'shop_id'    => 'integer',
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
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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

    public function duplicateForShop(int $shop_id, ?int $target_parent_id = null): self
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
            if ($target_parent_id !== null && (int) ($existing->parent_id ?? 0) !== $target_parent_id) {
                $existing->update([
                    'parent_id' => $target_parent_id,
                ]);
            }

            return $existing;
        }

        $current_shop_id = (int) ($this->getAttribute('shop_id') ?? 0);
        if ($current_shop_id <= 0) {
            $updated_data = [
                'shop_id' => $shop_id,
            ];

            if ($target_parent_id !== null) {
                $updated_data['parent_id'] = $target_parent_id;
            }

            $this->update($updated_data);

            return $this->fresh() ?? $this;
        }

        $duplicate = self::query()->create([
            'family_ulid' => $family_ulid,
            'shop_id'     => $shop_id,
            'parent_id'   => $target_parent_id,
            'sort_order'  => (int) $this->sort_order,
            'is_active'   => (bool) $this->is_active,
        ]);

        $this->descriptions()
            ->orderBy('id')
            ->get()
            ->each(function (CategoryDescription $description) use ($duplicate): void {
                CategoryDescription::query()->updateOrCreate(
                    [
                        'category_id'      => (int) $duplicate->id,
                        'shop_language_id' => $description->shop_language_id,
                    ],
                    [
                        'name'             => $description->name,
                        'description'      => $description->description,
                        'h1_title'         => $description->h1_title,
                        'meta_title'       => $description->meta_title,
                        'meta_description' => $description->meta_description,
                        'meta_keywords'    => $description->meta_keywords,
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
