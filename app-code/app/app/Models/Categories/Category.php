<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Products\Product;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Models\Trait\AiTranslationCacheRelationTrait;
use App\Models\Trait\DescriptionsTrait;
use App\Models\Trait\SeoUrlRelationTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

class Category extends Model
{
    use AiTranslationCacheRelationTrait, DescriptionsTrait, SeoUrlRelationTrait;

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
            if (Str::trim((string) $category->getAttribute('family_ulid')) === '') {
                $category->setAttribute('family_ulid', (string) Str::ulid());
            }
        });

        static::deleting(function (self $category): void {
            $category->aiTranslationCaches()->delete();

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

    public static function findCategoryIdByParentAndNameForLanguage(
        ?int $parent_category_id,
        string $category_name,
        int $shop_language_id
    ): ?int {
        $normalized_category_name = Str::lower(Str::trim($category_name));
        if ($normalized_category_name === '') {
            return null;
        }

        $query = self::query()
            ->select('categories.id')
            ->join('category_descriptions', 'category_descriptions.category_id', '=', 'categories.id')
            ->whereRaw('LOWER(category_descriptions.name) = ?', [$normalized_category_name])
            ->when(
                $parent_category_id === null,
                static fn (Builder $builder): Builder => $builder->whereNull('categories.parent_id'),
                static fn (Builder $builder): Builder => $builder->where('categories.parent_id', $parent_category_id),
            )
            ->when(
                $shop_language_id > 0,
                static fn (Builder $builder): Builder => $builder
                    ->orderByRaw(
                        'CASE WHEN category_descriptions.shop_language_id = ? THEN 0 ELSE 1 END',
                        [$shop_language_id]
                    )
            )
            ->orderBy('categories.id');

        $category_id = $query->value('categories.id');

        return $category_id !== null ? (int) $category_id : null;
    }

    public function duplicateForShop(int $target_shop_id, ?int $target_parent_id = null): self
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

        $target_category = self::findByFamilyAndShop($source_family_ulid, $target_shop_id);

        if ($target_category instanceof self) {
            if ($target_parent_id !== null && (int) ($target_category->parent_id ?? 0) !== $target_parent_id) {
                $target_category->update([
                    'parent_id' => $target_parent_id,
                ]);
            }

            return $target_category;
        }

        $source_shop_id = (int) ($this->getAttribute('shop_id') ?? 0);

        if ($source_shop_id <= 0) {
            $updated_data = [
                'shop_id' => $target_shop_id,
            ];

            if ($target_parent_id !== null) {
                $updated_data['parent_id'] = $target_parent_id;
            }

            $this->update($updated_data);

            return $this->fresh() ?? $this;
        }

        $source_descriptions             = $this->descriptions()->get();
        $source_default_shop_language_id = ShopLanguage::query()
            ->where('shop_id', $source_shop_id)
            ->where('is_default', true)
            ->value('id') ?? 0;

        if ($source_default_shop_language_id <= 0) {
            throw new RuntimeException('Source shop language not found for shop ID: '.$source_shop_id);
        }

        $source_description = $source_descriptions
            ->where('shop_language_id', $source_default_shop_language_id)
            ->first();

        if (! $source_description instanceof CategoryDescription) {
            $source_descriptions
                ->whereNull('shop_language_id')
                ->first()
                ?->update([
                    'shop_language_id' => $source_default_shop_language_id,
                ]);

            $source_description = $this->descriptions()
                ->where('shop_language_id', $source_default_shop_language_id)
                ->first();
        }

        /** @var self $duplicate */
        $duplicate = self::query()->create([
            'family_ulid' => $source_family_ulid,
            'shop_id'     => $target_shop_id,
            'parent_id'   => $target_parent_id,
            'sort_order'  => (int) $this->sort_order,
            'is_active'   => (bool) $this->is_active,
        ]);

        if (! $duplicate instanceof self) {
            throw new RuntimeException('Failed to duplicate category for target shop.');
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
            'name'             => $source_description->name,
            'description'      => $source_description->description,
            'h1_title'         => $source_description->h1_title,
            'meta_title'       => $source_description->meta_title,
            'meta_description' => $source_description->meta_description,
            'meta_keywords'    => $source_description->meta_keywords,
        ]);

        /*$this->descriptions()
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
            });*/

        return $duplicate;
    }
}
