<?php

declare(strict_types=1);

namespace App\Models\Products;

use App\Models\Brands\Brand;
use App\Models\Manufacturers\Manufacturer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductToManufacturerBrand extends Model
{
    protected $table = 'product_to_manufacturer_brand';

    protected $fillable = [
        'product_id',
        'manufacturer_id',
        'brand_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id'      => 'integer',
            'manufacturer_id' => 'integer',
            'brand_id'        => 'integer',
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
     * @return BelongsTo<Manufacturer, $this>
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
