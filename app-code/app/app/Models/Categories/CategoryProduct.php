<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryProduct extends Model
{
    protected $table = 'category_product';

    protected $fillable = [
        'product_id',
        'category_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return list<int>
     */
    public static function getUniqueCategoryIdsByProductId(int $product_id): array
    {
        if ($product_id <= 0) {
            return [];
        }

        return self::query()
            ->where('product_id', $product_id)
            ->pluck('category_id')
            ->filter()
            ->map(static fn ($category_id): int => (int) $category_id)
            ->unique()
            ->values()
            ->all();
    }
}
