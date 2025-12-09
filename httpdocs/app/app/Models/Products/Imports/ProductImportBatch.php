<?php

declare(strict_types=1);

namespace App\Models\Products\Imports;

use App\Enums\ProductImportBatchesStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductImportBatch extends Model
{
    use HasFactory;

    protected $table = 'product_import_batches';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_type',
        'source_name',
        'source_path',
        'status',
        'total_items',
        'processed_items',
        'failed_items',
        'options',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(ProductImportItem::class, 'product_import_batch_id');
    }
}

