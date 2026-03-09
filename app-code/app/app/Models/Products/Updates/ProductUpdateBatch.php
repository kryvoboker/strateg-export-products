<?php

declare(strict_types=1);

namespace App\Models\Products\Updates;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Models\Products\Exports\ProductExportItem;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductUpdateBatch extends Model
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
            'options'     => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProductUpdateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductUpdateItem::class, 'product_update_batch_id');
    }

    /**
     * @return MorphMany<ProductExportItem, $this>
     */
    public function exportItems(): MorphMany
    {
        return $this->morphMany(ProductExportItem::class, 'batchable');
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
        return $this->status === ProductUpdateBatchesStatusEnum::PROCESSING->value;
    }

    public function hasBadStatus(): bool
    {
        return in_array($this->status, [
            ProductUpdateBatchesStatusEnum::FAILED->value,
            ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            ProductUpdateBatchesStatusEnum::CANCELED->value,
        ], true);
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->options ?? [], $key, $default);
    }

    /**
     * @param  array<string, mixed>  $options
     */
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
        $path = $this->getErrorLogPath();

        return $path !== null && Storage::disk('public')->exists($path);
    }

    public function getErrorLogFileName(): ?string
    {
        $path = $this->getErrorLogPath();

        return $path !== null ? basename($path) : null;
    }

    public function getErrorLogUrl(): ?string
    {
        $path = $this->getErrorLogPath();

        return $path !== null ? Storage::disk('public')->url($path) : null;
    }
}
