<?php

declare(strict_types=1);

namespace App\Models\Products\Updates;

use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductUpdateItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'product_update_batch_id',
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
     * @return BelongsTo<ProductUpdateBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductUpdateBatch::class, 'product_update_batch_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getPayloadValue(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->payload ?? [], $key, $default);
    }

    public function getSourceRowNumber(): ?int
    {
        $row_number = $this->getPayloadValue('source_meta.row_number');

        if (! is_numeric($row_number)) {
            return null;
        }

        return (int) $row_number;
    }

    public function getSourceFilePath(): ?string
    {
        $source_file_path = Str::trim((string) $this->getPayloadValue('source_meta.path', ''));

        return $source_file_path === '' ? null : $source_file_path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function ensureBatchProductItem(
        int $product_update_batch_id,
        int $product_id,
        array $payload = [],
        ?string $status = null
    ): self {
        $existing_item = static::query()
            ->where('product_update_batch_id', $product_update_batch_id)
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->first();

        if ($existing_item instanceof self) {
            return $existing_item;
        }

        return static::query()->create([
            'product_update_batch_id' => $product_update_batch_id,
            'product_id' => $product_id,
            'payload' => $payload,
            'status' => $status ?? ProductUpdateItemsStatusEnum::SUCCESSED->value,
            'error_message' => null,
            'processed_at' => now(),
        ]);
    }
}
