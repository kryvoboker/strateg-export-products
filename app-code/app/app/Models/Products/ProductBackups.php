<?php

declare(strict_types=1);

namespace App\Models\Products;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
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
            'shop_id'       => 'integer',
            'payload'       => 'array',
            'is_used'       => 'boolean',
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
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
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
        $normalized_backup_kind   = Str::lower(Str::trim($backup_kind));

        if ($normalized_backup_source === '') {
            throw new RuntimeException('backup_source is required');
        }

        if ($normalized_backup_kind === '') {
            throw new RuntimeException('backup_kind is required');
        }

        if ($normalized_backup_source === 'external_api' && $normalized_backup_kind !== 'external_product_snapshot') {
            throw new RuntimeException('For external backups backup_kind must be external_product_snapshot');
        }

        $normalized_shop_id             = $shop_id !== null && $shop_id > 0 ? $shop_id : null;
        $normalized_external_product_id = Str::trim((string) ($external_product_id ?? ''));

        if ($normalized_backup_source === 'external_api') {
            if ($normalized_shop_id === null) {
                throw new RuntimeException('shop_id is required for external backups');
            }

            if ($normalized_external_product_id === '') {
                throw new RuntimeException('external_product_id is required for external backups');
            }
        }

        $backup_attributes = [
            'backupable_type'     => Product::class,
            'backupable_id'       => $product_id,
            'backup_source'       => $normalized_backup_source,
            'backup_kind'         => $normalized_backup_kind,
            'shop_id'             => $normalized_shop_id,
            'external_product_id' => $normalized_external_product_id !== '' ? $normalized_external_product_id : null,
            'payload'             => $payload,
            'is_used'             => false,
        ];

        $created_backup = static::query()->create($backup_attributes);

        self::trimScopeBackups(
            product_id: $product_id,
            backup_source: $normalized_backup_source,
            backup_kind: $normalized_backup_kind,
            shop_id: $normalized_shop_id,
            external_product_id: $normalized_external_product_id !== '' ? $normalized_external_product_id : null,
        );

        return $created_backup->refresh() ?? $created_backup;
    }

    /**
     * @param  array<string, mixed>  $payload
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

    public function scopeLocalProductSnapshots(Builder $query): Builder
    {
        return $query
            ->where('backupable_type', Product::class)
            ->where('backup_source', 'internal')
            ->where('backup_kind', 'local_product_snapshot');
    }

    public function scopeExternalProductSnapshots(Builder $query): Builder
    {
        return $query
            ->where('backupable_type', Product::class)
            ->where('backup_source', 'external_api')
            ->where('backup_kind', 'external_product_snapshot');
    }

    public static function hasLocalSnapshotForProduct(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        return static::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->exists();
    }

    public static function hasUnusedLocalSnapshotForProduct(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        return static::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('is_used', false)
            ->exists();
    }

    public static function getLatestLocalSnapshotForProduct(int $product_id): ?self
    {
        if ($product_id <= 0) {
            return null;
        }

        $backup = static::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->orderByDesc('id')
            ->first();

        return $backup instanceof self ? $backup : null;
    }

    public static function getLatestUnusedLocalSnapshotForProduct(int $product_id): ?self
    {
        if ($product_id <= 0) {
            return null;
        }

        $backup = static::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('is_used', false)
            ->orderByDesc('id')
            ->first();

        return $backup instanceof self ? $backup : null;
    }

    public static function getLatestExternalSnapshotForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): ?self {
        if ($product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $query = static::query()
            ->externalProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('shop_id', $shop_id);

        if ($external_product_id !== null && $external_product_id > 0) {
            $query->where('external_product_id', (string) $external_product_id);
        }

        $backup = $query
            ->orderByDesc('id')
            ->first();

        return $backup instanceof self ? $backup : null;
    }

    public static function hasUnusedExternalSnapshotForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): bool {
        if ($product_id <= 0 || $shop_id <= 0) {
            return false;
        }

        $query = static::query()
            ->externalProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('shop_id', $shop_id)
            ->where('is_used', false);

        if ($external_product_id !== null && $external_product_id > 0) {
            $query->where('external_product_id', (string) $external_product_id);
        }

        return $query->exists();
    }

    public static function getLatestUnusedExternalSnapshotForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): ?self {
        if ($product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $query = static::query()
            ->externalProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('shop_id', $shop_id)
            ->where('is_used', false);

        if ($external_product_id !== null && $external_product_id > 0) {
            $query->where('external_product_id', (string) $external_product_id);
        }

        $backup = $query
            ->orderByDesc('id')
            ->first();

        return $backup instanceof self ? $backup : null;
    }

    /**
     * @return Collection<int, static>
     */
    public static function getUnusedLocalSnapshotsForProduct(int $product_id): Collection
    {
        if ($product_id <= 0) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        return static::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('is_used', false)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, static>
     */
    public static function getUnusedExternalSnapshotsForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): Collection {
        if ($product_id <= 0 || $shop_id <= 0) {
            return static::query()->whereRaw('1 = 0')->get();
        }

        $query = static::query()
            ->externalProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('shop_id', $shop_id)
            ->where('is_used', false);

        if ($external_product_id !== null && $external_product_id > 0) {
            $query->where('external_product_id', (string) $external_product_id);
        }

        return $query
            ->orderByDesc('id')
            ->get();
    }

    private static function trimScopeBackups(
        int $product_id,
        string $backup_source,
        string $backup_kind,
        ?int $shop_id = null,
        ?string $external_product_id = null
    ): void {
        $max_backups_per_scope = self::resolveMaxBackupsPerScope();

        $scope_query = static::query()
            ->where('backupable_type', Product::class)
            ->where('backupable_id', $product_id)
            ->where('backup_source', $backup_source)
            ->where('backup_kind', $backup_kind)
            ->when(
                $shop_id !== null,
                static fn (Builder $builder): Builder => $builder->where('shop_id', $shop_id),
                static fn (Builder $builder): Builder => $builder->whereNull('shop_id'),
            )
            ->when(
                Str::trim((string) ($external_product_id ?? '')) !== '',
                static fn (Builder $builder): Builder => $builder->where('external_product_id', Str::trim((string) $external_product_id)),
                static fn (Builder $builder): Builder => $builder->whereNull('external_product_id'),
            );

        $total_backups_in_scope = (int) $scope_query->count();
        if ($total_backups_in_scope <= $max_backups_per_scope) {
            return;
        }

        $sorted_scope_backup_ids = (clone $scope_query)
            ->orderByDesc('id')
            ->pluck('id')
            ->map(static fn ($backup_id): int => (int) $backup_id)
            ->filter(static fn (int $backup_id): bool => $backup_id > 0)
            ->values()
            ->all();

        $backup_ids_to_delete = array_values(array_slice($sorted_scope_backup_ids, $max_backups_per_scope));

        if ($backup_ids_to_delete === []) {
            return;
        }

        static::query()
            ->whereIn('id', $backup_ids_to_delete)
            ->delete();

        Log::channel('daily')->info('Old product backups pruned by retention policy', [
            'backupable_type'      => Product::class,
            'backupable_id'        => $product_id,
            'backup_source'        => $backup_source,
            'backup_kind'          => $backup_kind,
            'shop_id'              => $shop_id,
            'external_product_id'  => $external_product_id,
            'retention_limit'      => $max_backups_per_scope,
            'deleted_backup_ids'   => $backup_ids_to_delete,
            'deleted_backups_count' => count($backup_ids_to_delete),
        ]);
    }

    private static function resolveMaxBackupsPerScope(): int
    {
        $configured_max_backups_per_scope = (int) config('app.product_backups_max_per_scope', 15);

        return max($configured_max_backups_per_scope, 1);
    }
}
