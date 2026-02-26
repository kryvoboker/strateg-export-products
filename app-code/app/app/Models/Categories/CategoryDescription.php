<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CategoryDescription extends Model
{
    protected $fillable = [
        'category_id',
        'shop_language_id',
        'name',
        'description',
        'h1_title',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id'      => 'integer',
            'shop_language_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    public static function findSourceForCategory(int $category_id, int $default_shop_language_id): ?self
    {
        if ($category_id <= 0) {
            return null;
        }

        $source_description = self::query()
            ->where('category_id', $category_id)
            ->when(
                $default_shop_language_id > 0,
                static fn ($query) => $query->where('shop_language_id', $default_shop_language_id)
            )
            ->first();

        if ($source_description instanceof self) {
            return $source_description;
        }

        $source_description = self::query()
            ->where('category_id', $category_id)
            ->whereNull('shop_language_id')
            ->first();

        if ($source_description instanceof self) {
            return $source_description;
        }

        return self::query()
            ->where('category_id', $category_id)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function upsertByCategoryAndLanguage(int $category_id, int $shop_language_id, array $values): self|Model
    {
        return self::query()->updateOrCreate(
            [
                'category_id'      => $category_id,
                'shop_language_id' => $shop_language_id > 0 ? $shop_language_id : null,
            ],
            [
                'name'             => Str::trim((string) ($values['name'] ?? '')),
                'description'      => $values['description'] ?? null,
                'h1_title'         => $values['h1_title'] ?? null,
                'meta_title'       => $values['meta_title'] ?? null,
                'meta_description' => $values['meta_description'] ?? null,
                'meta_keywords'    => $values['meta_keywords'] ?? null,
            ]
        );
    }

    public static function findCategoryIdByParentAndName(?int $parent_category_id, string $category_name): ?int
    {
        $normalized_category_name = Str::lower(Str::trim($category_name));
        if ($normalized_category_name === '') {
            return null;
        }

        $query = Category::query()
            ->select('categories.id')
            ->join('category_descriptions', 'category_descriptions.category_id', '=', 'categories.id')
            ->whereRaw('LOWER('.config('database.db_prefix').'category_descriptions.name) = ?', [$normalized_category_name])
            ->when(
                $parent_category_id === null,
                static fn ($builder) => $builder->whereNull('categories.parent_id'),
                static fn ($builder) => $builder->where('categories.parent_id', $parent_category_id),
            )
            ->orderByRaw(
                'CASE WHEN '.config('database.db_prefix').'category_descriptions.shop_language_id IS NULL THEN 0 ELSE 1 END'
            )
            ->orderBy('categories.id');

        $category_id = $query->value('categories.id');

        return $category_id !== null ? (int) $category_id : null;
    }

    public static function ensureDefaultDescription(int $category_id, ?int $shop_language_id, string $category_name): void
    {
        $clean_category_name = Str::trim($category_name);
        if ($clean_category_name === '' || $category_id <= 0) {
            return;
        }

        self::query()->firstOrCreate(
            [
                'category_id'      => $category_id,
                'shop_language_id' => $shop_language_id,
            ],
            [
                'name'             => $clean_category_name,
                'description'      => null,
                'h1_title'         => $clean_category_name,
                'meta_title'       => $clean_category_name,
                'meta_description' => null,
                'meta_keywords'    => null,
            ]
        );
    }
}
