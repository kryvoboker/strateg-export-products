<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Shops\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryShop extends Model
{
    protected $table = 'category_shop';

    protected $fillable = [
        'category_id',
        'shop_id',
        'external_category_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id'          => 'integer',
            'shop_id'              => 'integer',
            'external_category_id' => 'integer',
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
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
