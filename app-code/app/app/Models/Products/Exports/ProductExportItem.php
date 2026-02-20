<?php

declare(strict_types=1);

namespace App\Models\Products\Exports;

use App\Enums\Product\Export\ProductExportItemsStatusEnum;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductExportItem extends Model
{
    protected $fillable = [
        'batchable_type',
        'batchable_id',
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
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function batchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForBatchable(Builder $query, string $batchable_type, int $batchable_id): Builder
    {
        if ($batchable_id <= 0 || Str::trim($batchable_type) === '') {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('batchable_type', $batchable_type)
            ->where('batchable_id', $batchable_id);
    }

    public function scopeForBatchProductShop(Builder $query, int $batch_id, int $product_id, int $shop_id): Builder
    {
        $query = $this->scopeForBatchable($query, ProductImportBatch::class, $batch_id);

        return $query
            ->where('product_id', $product_id)
            ->where('payload->shop_id', $shop_id);
    }

    public function scopeForOperation(Builder $query, string $operation): Builder
    {
        $operation = Str::lower(Str::trim($operation));
        if ($operation === '') {
            return $query;
        }

        return $query->where('payload->operation', $operation);
    }

    /**
     * @return array{total:int, processing:int, failed:int, exported:int}
     */
    public static function getBatchStatusCounters(int $batch_id): array
    {
        return self::getBatchableStatusCounters(ProductImportBatch::class, $batch_id);
    }

    /**
     * @return array{total:int, processing:int, failed:int, exported:int}
     */
    public static function getBatchableStatusCounters(string $batchable_type, int $batchable_id): array
    {
        if ($batchable_id <= 0 || Str::trim($batchable_type) === '') {
            return [
                'total'      => 0,
                'processing' => 0,
                'failed'     => 0,
                'exported'   => 0,
            ];
        }

        /** @var Collection<int, object{status:string,status_total:int}> $status_rows */
        $status_rows = self::query()
            ->selectRaw('status, COUNT(*) AS status_total')
            ->forBatchable($batchable_type, $batchable_id)
            ->groupBy('status')
            ->get();

        $total = (int) $status_rows->sum(static fn ($row): int => (int) ($row->status_total ?? 0));

        return [
            'total'      => $total,
            'processing' => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::PROCESSING->value)->status_total ?? 0),
            'failed'     => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::FAILED->value)->status_total ?? 0),
            'exported'   => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::EXPORTED->value)->status_total ?? 0),
        ];
    }

    /**
     * @return array{total:int, processing:int, failed:int, exported:int}
     */
    public static function getBatchStatusCountersByOperation(int $batch_id, string $operation): array
    {
        return self::getBatchableStatusCountersByOperation(ProductImportBatch::class, $batch_id, $operation);
    }

    /**
     * @return array{total:int, processing:int, failed:int, exported:int}
     */
    public static function getBatchableStatusCountersByOperation(string $batchable_type, int $batchable_id, string $operation): array
    {
        if ($batchable_id <= 0 || Str::trim($batchable_type) === '') {
            return [
                'total'      => 0,
                'processing' => 0,
                'failed'     => 0,
                'exported'   => 0,
            ];
        }

        /** @var Collection<int, object{status:string,status_total:int}> $status_rows */
        $status_rows = self::query()
            ->selectRaw('status, COUNT(*) AS status_total')
            ->forBatchable($batchable_type, $batchable_id)
            ->forOperation($operation)
            ->groupBy('status')
            ->get();

        $total = (int) $status_rows->sum(static fn ($row): int => (int) ($row->status_total ?? 0));

        return [
            'total'      => $total,
            'processing' => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::PROCESSING->value)->status_total ?? 0),
            'failed'     => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::FAILED->value)->status_total ?? 0),
            'exported'   => (int) ($status_rows->firstWhere('status', ProductExportItemsStatusEnum::EXPORTED->value)->status_total ?? 0),
        ];
    }
}
