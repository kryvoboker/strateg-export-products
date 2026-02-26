<?php

declare(strict_types=1);

namespace App\Supports\Services\Products;

use App\Models\Products\Product;
use App\Models\Products\ProductBackups;
use App\Supports\Services\Products\Backup\ProductBackupPayloadRestoreService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProductBackupRestoreService
{
    public function hasValidLatestLocalSnapshotForProduct(int $product_id): bool
    {
        return $this->resolveLatestValidLocalSnapshotForProduct($product_id) instanceof ProductBackups;
    }

    public function resolveLatestValidLocalSnapshotForProduct(int $product_id): ?ProductBackups
    {
        if ($product_id <= 0) {
            return null;
        }

        $candidate_backups = ProductBackups::getUnusedLocalSnapshotsForProduct($product_id);

        foreach ($candidate_backups as $backup) {
            $reason = '';
            if ($this->isBackupPayloadValid($backup->payload, $reason)) {
                return $backup;
            }

            Log::channel('daily')->warning('Skipping local backup due to invalid payload', [
                'backup_id'   => (int) $backup->id,
                'product_id'  => $product_id,
                'is_used'     => (bool) $backup->is_used,
                'error_msg'   => $reason,
            ]);
        }

        return null;
    }

    public function isBackupPayloadValid(mixed $payload, ?string &$reason = null): bool
    {
        if (! is_array($payload)) {
            $reason = 'Payload is not an array';

            return false;
        }

        $product_payload = Arr::get($payload, 'product');
        if (! is_array($product_payload)) {
            $reason = 'Payload does not contain product object';

            return false;
        }

        if ($product_payload === []) {
            $reason = 'Payload product object is empty';

            return false;
        }

        $reason = null;

        return true;
    }

    public function isExternalBackupPayloadValid(mixed $payload, ?string &$reason = null): bool
    {
        if (! is_array($payload)) {
            $reason = 'Payload is not an array';

            return false;
        }

        if ($payload === []) {
            $reason = 'Payload array is empty';

            return false;
        }

        $reason = null;

        return true;
    }

    public function hasValidLatestExternalSnapshotForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): bool {
        return $this->resolveLatestValidExternalSnapshotForProductShop(
            $product_id,
            $shop_id,
            $external_product_id
        ) instanceof ProductBackups;
    }

    public function resolveLatestValidExternalSnapshotForProductShop(
        int $product_id,
        int $shop_id,
        ?int $external_product_id = null
    ): ?ProductBackups {
        if ($product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $candidate_backups = ProductBackups::getUnusedExternalSnapshotsForProductShop(
            $product_id,
            $shop_id,
            $external_product_id
        );

        foreach ($candidate_backups as $backup) {
            $reason = '';
            if ($this->isExternalBackupPayloadValid($backup->payload, $reason)) {
                return $backup;
            }

            Log::channel('daily')->warning('Skipping external backup due to invalid payload', [
                'backup_id'           => (int) $backup->id,
                'product_id'          => $product_id,
                'shop_id'             => $shop_id,
                'external_product_id' => $external_product_id,
                'is_used'             => (bool) $backup->is_used,
                'error_msg'           => $reason,
            ]);
        }

        return null;
    }

    public function restoreLatestSnapshotForProduct(int $product_id): ProductBackups
    {
        $backup = $this->resolveLatestValidLocalSnapshotForProduct($product_id);

        if (! $backup instanceof ProductBackups) {
            throw new RuntimeException('Valid local product backup not found');
        }

        return $this->restoreFromBackup($backup);
    }

    /**
     * @throws Throwable
     */
    public function restoreFromBackup(ProductBackups $backup): ProductBackups
    {
        $product_id = (int) ($backup->backupable_id ?? 0);
        if ($product_id <= 0) {
            throw new RuntimeException('Invalid backupable_id for product restore');
        }

        $payload = normalize_array_payload($backup->payload);
        $reason  = '';
        if (! $this->isBackupPayloadValid($payload, $reason)) {
            throw new RuntimeException('Backup payload is invalid for restore');
        }

        $product = Product::query()->find($product_id);
        if (! $product instanceof Product) {
            throw new RuntimeException('Product not found for restore');
        }

        Log::channel('daily')->info('Starting product restore from backup', [
            'backup_id'    => (int) $backup->id,
            'product_id'   => $product_id,
            'restore_mode' => 'sync_no_queue',
        ]);

        try {
            $product_payload_restore_service = app(ProductBackupPayloadRestoreService::class);
            $product->getConnection()->transaction(function () use ($backup, $payload, $product, $product_payload_restore_service): void {
                $product_payload_restore_service->applySnapshotToProduct($product, $payload);
                $backup->markAsUsed();
            });
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to restore product from backup', [
                'backup_id'    => (int) $backup->id,
                'product_id'   => $product_id,
                'error_msg'    => $exception->getMessage(),
                'file'         => $exception->getFile(),
                'line'         => $exception->getLine(),
                'restore_mode' => 'sync_no_queue',
            ]);

            throw $exception;
        }

        $restored_backup = $backup->refresh();

        Log::channel('daily')->info('Product restore from backup completed', [
            'backup_id'    => (int) $restored_backup->id,
            'product_id'   => $product_id,
            'is_used'      => (bool) $restored_backup->is_used,
            'restore_mode' => 'sync_no_queue',
        ]);

        return $restored_backup;
    }
}
