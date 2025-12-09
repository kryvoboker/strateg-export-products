<?php

declare(strict_types=1);

namespace App\Models\Products\Imports;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImportItem extends Model
{
    use HasFactory;

    protected $table = 'product_import_items';

    /** @var list<string> */
    protected $fillable = [
        'product_import_batch_id',
        'product_id',
        'raw_payload',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(ProductImportBatch::class, 'product_import_batch_id');
    }
}

