<?php

declare(strict_types=1);

namespace App\Models\Products\Updates;

use App\Models\Products\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use RuntimeException;

class ProductBackups extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'backupable_type',
        'backupable_id',
        'backup_source',
        'backup_kind',
        'shop_id',
        'external_product_id',
        'payload',
        'is_used',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'backupable_id' => 'integer',
            'shop_id' => 'integer',
            'payload' => 'array',
            'is_used' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function backupable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function createUsingBackupForProduct(
        int $product_id,
        array $payload,
        ?int $shop_id = null,
        ?string $external_product_id = null
    ): self {
        return static::createOrUpdateProductBackup(
            product_id: $product_id,
            payload: $payload,
            backup_source: 'external_api',
            backup_kind: 'external_product_snapshot',
            shop_id: $shop_id,
            external_product_id: $external_product_id
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function createOrUpdateProductBackup(
        int $product_id,
        array $payload,
        string $backup_source,
        string $backup_kind,
        ?int $shop_id = null,
        ?string $external_product_id = null
    ): self {
        if ($product_id <= 0) {
            throw new RuntimeException('Invalid product id for backup');
        }

        $normalized_backup_source = Str::lower(Str::trim($backup_source));
        $normalized_backup_kind = Str::lower(Str::trim($backup_kind));

        if ($normalized_backup_source === '') {
            throw new RuntimeException('backup_source is required');
        }

        if ($normalized_backup_kind === '') {
            throw new RuntimeException('backup_kind is required');
        }

        if ($normalized_backup_source === 'external_api' && $normalized_backup_kind !== 'external_product_snapshot') {
            throw new RuntimeException('For external backups backup_kind must be external_product_snapshot');
        }

        $normalized_shop_id = $shop_id !== null && $shop_id > 0 ? $shop_id : null;
        $normalized_external_product_id = Str::trim((string) ($external_product_id ?? ''));

        if ($normalized_backup_source === 'external_api') {
            if ($normalized_shop_id === null) {
                throw new RuntimeException('shop_id is required for external backups');
            }

            if ($normalized_external_product_id === '') {
                throw new RuntimeException('external_product_id is required for external backups');
            }
        }

        $query = static::query()
            ->where('backupable_type', Product::class)
            ->where('backupable_id', $product_id)
            ->where('backup_source', $normalized_backup_source)
            ->where('backup_kind', $normalized_backup_kind)
            ->when(
                $normalized_shop_id !== null,
                static fn ($builder) => $builder->where('shop_id', $normalized_shop_id),
                static fn ($builder) => $builder->whereNull('shop_id')
            );

        $existing_backup = $query
            ->orderByDesc('id')
            ->first();

        $backup_attributes = [
            'backupable_type' => Product::class,
            'backupable_id' => $product_id,
            'backup_source' => $normalized_backup_source,
            'backup_kind' => $normalized_backup_kind,
            'shop_id' => $normalized_shop_id,
            'external_product_id' => $normalized_external_product_id !== '' ? $normalized_external_product_id : null,
            'payload' => $payload,
            'is_used' => false,
        ];

        if ($existing_backup instanceof self) {
            $existing_backup->update($backup_attributes);

            $query
                ->whereKeyNot($existing_backup->id)
                ->delete();

            return $existing_backup->refresh();
        }

        return static::query()->create($backup_attributes);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function createOrUpdateInternalProductBackup(int $product_id, array $payload): self
    {
        return static::createOrUpdateProductBackup(
            product_id: $product_id,
            payload: $payload,
            backup_source: 'internal',
            backup_kind: 'local_product_snapshot',
            shop_id: null,
            external_product_id: null
        );
    }

    public function markAsUsed(): bool
    {
        if ($this->is_used === true) {
            return true;
        }

        return $this->update([
            'is_used' => true,
        ]);
    }

    public static function markAsUsedById(int $backup_id): bool
    {
        $backup = static::query()->find($backup_id);

        if (! $backup instanceof self) {
            return false;
        }

        return $backup->markAsUsed();
    }
}
