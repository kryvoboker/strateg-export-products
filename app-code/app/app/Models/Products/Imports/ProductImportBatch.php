<?php

declare(strict_types=1);

namespace App\Models\Products\Imports;

use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductImportBatch extends Model
{
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
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductImportItem::class, 'product_import_batch_id');
    }

    public function exportItems(): HasMany
    {
        return $this->hasMany(ProductExportItem::class, 'product_import_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isProcessing(): bool
    {
        return $this->status === ProductImportBatchesStatusEnum::PROCESSING->value;
    }

    public function hasBadStatus(): bool
    {
        return in_array($this->status, [
            ProductImportBatchesStatusEnum::FAILED->value,
            ProductImportBatchesStatusEnum::PARTIAL_FAILED->value,
            ProductImportBatchesStatusEnum::CANCELED->value,
        ], true);
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->options ?? [], $key, $default);
    }

    public function mergeOptions(array $options): bool
    {
        return $this->update([
            'options' => [
                ...($this->options ?? []),
                ...$options,
            ],
        ]);
    }

    public function getErrorLogPath(): ?string
    {
        $path = Str::trim((string) $this->getOption('error_log_path', ''));

        return $path === '' ? null : $path;
    }

    public function hasErrorLog(): bool
    {
        return $this->getErrorLogPath() !== null;
    }
}
