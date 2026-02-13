<?php

declare(strict_types=1);

namespace App\Models\Products\Exports;

use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductExportItem extends Model
{
    protected $fillable = [
        'product_import_batch_id',
        'product_id',
        'payload',
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
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'product_import_batch_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForBatchProductShop(Builder $query, int $batch_id, int $product_id, int $shop_id): Builder
    {
        return $query
            ->where('product_import_batch_id', $batch_id)
            ->where('product_id', $product_id)
            ->whereRaw("(payload->>'shop_id')::int = ?", [$shop_id]);
    }

    /**
     * @return array{total:int, processing:int, failed:int, exported:int}
     */
    public static function getBatchStatusCounters(int $batch_id): array
    {
        if ($batch_id <= 0) {
            return [
                'total' => 0,
                'processing' => 0,
                'failed' => 0,
                'exported' => 0,
            ];
        }

        /** @var Collection<int, object{status:string,status_total:int}> $status_rows */
        $status_rows = self::query()
            ->selectRaw('status, COUNT(*) AS status_total')
            ->where('product_import_batch_id', $batch_id)
            ->groupBy('status')
            ->get();

        $total = (int) $status_rows->sum(static fn ($row): int => (int) ($row->status_total ?? 0));

        return [
            'total' => $total,
            'processing' => (int) ($status_rows->firstWhere('status', \App\Enums\Product\Export\ProductExportItemsStatusEnum::PROCESSING->value)->status_total ?? 0),
            'failed' => (int) ($status_rows->firstWhere('status', \App\Enums\Product\Export\ProductExportItemsStatusEnum::FAILED->value)->status_total ?? 0),
            'exported' => (int) ($status_rows->firstWhere('status', \App\Enums\Product\Export\ProductExportItemsStatusEnum::EXPORTED->value)->status_total ?? 0),
        ];
    }
}
