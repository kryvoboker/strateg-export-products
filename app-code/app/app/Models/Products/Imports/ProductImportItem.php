<?php

declare(strict_types=1);

namespace App\Models\Products\Imports;

use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductImportItem extends Model
{
    /** @var list<string> */
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
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductImportBatch::class, 'product_import_batch_id');
    }

    /**
     * @return HasOne<Product, $this>
     */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'product_import_item_id');
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
     * @param  array<string, mixed>  $payload
     */
    public static function ensureBatchProductItem(
        int $product_import_batch_id,
        int $product_id,
        array $payload = [],
        ?string $status = null
    ): self {
        $existing_item = static::query()
            ->where('product_import_batch_id', $product_import_batch_id)
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->first();

        if ($existing_item instanceof self) {
            $existing_item->update([
                'payload'       => $payload !== [] ? $payload : ($existing_item->payload ?? []),
                'status'        => $status ?? $existing_item->status,
                'error_message' => null,
                'processed_at'  => now(),
            ]);

            return $existing_item;
        }

        $existing_item_by_product_id = static::query()
            ->where('product_id', $product_id)
            ->orderByDesc('id')
            ->first();

        if ($existing_item_by_product_id instanceof self) {
            $existing_item_by_product_id->update([
                'product_import_batch_id' => $product_import_batch_id,
                'payload'                 => $payload !== [] ? $payload : ($existing_item_by_product_id->payload ?? []),
                'status'                  => $status ?? $existing_item_by_product_id->status,
                'error_message'           => null,
                'processed_at'            => now(),
            ]);

            Product::query()
                ->whereKey($product_id)
                ->update([
                    'product_import_item_id' => $existing_item_by_product_id->id,
                ]);

            return $existing_item_by_product_id;
        }

        $created_item = static::query()->create([
            'product_import_batch_id' => $product_import_batch_id,
            'product_id'              => $product_id,
            'payload'                 => $payload,
            'status'                  => $status ?? ProductImportItemsStatusEnum::SUCCESSED->value,
            'error_message'           => null,
            'processed_at'            => now(),
        ]);

        Product::query()
            ->whereKey($product_id)
            ->update([
                'product_import_item_id' => $created_item->id,
            ]);

        return $created_item;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function createDraftItemForBatch(
        int $product_import_batch_id,
        array $payload = [],
        ?string $status = null
    ): self {
        return static::query()->create([
            'product_import_batch_id' => $product_import_batch_id,
            'product_id'              => null,
            'payload'                 => $payload,
            'status'                  => $status ?? ProductImportItemsStatusEnum::SUCCESSED->value,
            'error_message'           => null,
            'processed_at'            => now(),
        ]);
    }

    public function linkProduct(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        $updated = $this->update([
            'product_id'    => $product_id,
            'status'        => ProductImportItemsStatusEnum::SUCCESSED->value,
            'error_message' => null,
            'processed_at'  => now(),
        ]);

        if ($updated) {
            Product::query()
                ->whereKey($product_id)
                ->update([
                    'product_import_item_id' => $this->id,
                ]);
        }

        return $updated;
    }
}
