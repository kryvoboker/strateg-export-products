<?php

declare(strict_types=1);

namespace App\Models\Products\Deletes;

use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Models\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductDeleteBatch extends Model
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
     * @return HasMany<ProductDeleteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProductDeleteItem::class, 'product_delete_batch_id');
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
        return $this->status === ProductDeleteBatchesStatusEnum::PROCESSING->value;
    }

    public function hasBadStatus(): bool
    {
        return in_array($this->status, [
            ProductDeleteBatchesStatusEnum::FAILED->value,
            ProductDeleteBatchesStatusEnum::PARTIAL_FAILED->value,
            ProductDeleteBatchesStatusEnum::CANCELED->value,
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
