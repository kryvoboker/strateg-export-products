<?php

declare(strict_types=1);

namespace App\Models\Products;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDiscount extends Model
{
    protected $fillable = [
        'product_id',
        'user_group_id',
        'quantity',
        'price',
        'priority',
        'date_start',
        'date_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'user_group_id' => 'integer',
            'quantity' => 'integer',
            'price' => 'decimal:4',
            'priority' => 'integer',
            'date_start' => 'datetime',
            'date_end' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
