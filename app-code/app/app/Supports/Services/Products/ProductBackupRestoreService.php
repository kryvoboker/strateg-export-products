<?php

declare(strict_types=1);

namespace App\Supports\Services\Products;

use App\Models\Attributes\Attribute;
use App\Models\Categories\Category;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Seo\SeoUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        $candidate_backups = ProductBackups::query()
            ->localProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('is_used', false)
            ->orderByDesc('id')
            ->get();

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

        $candidate_backups_query = ProductBackups::query()
            ->externalProductSnapshots()
            ->where('backupable_id', $product_id)
            ->where('shop_id', $shop_id)
            ->where('is_used', false);

        if ($external_product_id !== null && $external_product_id > 0) {
            $candidate_backups_query->where('external_product_id', (string) $external_product_id);
        }

        $candidate_backups = $candidate_backups_query
            ->orderByDesc('id')
            ->get();

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

        $payload = $backup->payload;
        $reason  = '';
        if (! $this->isBackupPayloadValid($payload, $reason)) {
            throw new RuntimeException('Backup payload is invalid for restore');
        }
        $payload = is_array($payload) ? $payload : [];

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
            $product->getConnection()->transaction(function () use ($backup, $payload, $product): void {
                $product_id = (int) $product->id;

                $product->update([
                    'product_import_item_id' => ($snapshot_product_id = Arr::get($payload, 'product.product_import_item_id')) !== null
                        ? (int) $snapshot_product_id
                        : null,
                    'marked_to_shop' => Arr::get($payload, 'product.marked_to_shop'),
                    'model'          => Arr::get($payload, 'product.model'),
                    'sku'            => Arr::get($payload, 'product.sku'),
                    'ean'            => Arr::get($payload, 'product.ean'),
                    'quantity'       => (int) Arr::get($payload, 'product.quantity', 0),
                    'minimum'        => max((int) Arr::get($payload, 'product.minimum', 1), 1),
                    'image'          => Arr::get($payload, 'product.image'),
                    'price'          => (float) Arr::get($payload, 'product.price', 0),
                    'is_active'      => (bool) Arr::get($payload, 'product.is_active', false),
                    'date_available' => Arr::get($payload, 'product.date_available'),
                    'date_added'     => Arr::get($payload, 'product.date_added'),
                ]);

                ProductDescription::query()->where('product_id', $product_id)->delete();
                foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
                    if (! is_array($description_row)) {
                        continue;
                    }

                    $shop_language_id = Arr::get($description_row, 'shop_language_id');
                    ProductDescription::query()->create([
                        'product_id'       => $product_id,
                        'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                        'name'             => Arr::get($description_row, 'name'),
                        'description'      => Arr::get($description_row, 'description'),
                        'meta_title'       => Arr::get($description_row, 'meta_title'),
                        'meta_description' => Arr::get($description_row, 'meta_description'),
                        'meta_keywords'    => Arr::get($description_row, 'meta_keywords'),
                    ]);
                }

                ProductImage::query()->where('product_id', $product_id)->delete();
                foreach (Arr::get($payload, 'images', []) as $image_row) {
                    if (! is_array($image_row)) {
                        continue;
                    }

                    $image = Str::trim((string) Arr::get($image_row, 'image', ''));
                    if ($image === '') {
                        continue;
                    }

                    ProductImage::query()->create([
                        'product_id' => $product_id,
                        'image'      => $image,
                        'sort_order' => (int) Arr::get($image_row, 'sort_order', 0),
                    ]);
                }

                $category_ids = collect(Arr::get($payload, 'categories', []))
                    ->map(static fn ($category_row): int => is_array($category_row) ? (int) Arr::get($category_row, 'id', 0) : 0)
                    ->filter(static fn (int $category_id): bool => $category_id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($category_ids !== []) {
                    $existing_category_ids = Category::query()
                        ->whereIn('id', $category_ids)
                        ->pluck('id')
                        ->map(static fn ($category_id): int => (int) $category_id)
                        ->values()
                        ->all();

                    $product->categories()->sync($existing_category_ids);
                } else {
                    $product->categories()->sync([]);
                }

                ProductToAttribute::query()->where('product_id', $product_id)->delete();
                $attribute_ids = collect(Arr::get($payload, 'attributes', []))
                    ->map(static fn ($attribute_row): int => is_array($attribute_row) ? (int) Arr::get($attribute_row, 'attribute_id', 0) : 0)
                    ->filter(static fn (int $attribute_id): bool => $attribute_id > 0)
                    ->unique()
                    ->values()
                    ->all();

                $existing_attribute_ids = Attribute::query()
                    ->whereIn('id', $attribute_ids)
                    ->pluck('id')
                    ->map(static fn ($attribute_id): int => (int) $attribute_id)
                    ->flip()
                    ->all();

                foreach (Arr::get($payload, 'attributes', []) as $attribute_row) {
                    if (! is_array($attribute_row)) {
                        continue;
                    }

                    $attribute_id = (int) Arr::get($attribute_row, 'attribute_id', 0);
                    if ($attribute_id <= 0 || ! array_key_exists($attribute_id, $existing_attribute_ids)) {
                        continue;
                    }

                    $shop_language_id = Arr::get($attribute_row, 'shop_language_id');
                    ProductToAttribute::query()->create([
                        'product_id'       => $product_id,
                        'attribute_id'     => $attribute_id,
                        'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                        'text'             => Arr::get($attribute_row, 'text'),
                    ]);
                }

                SeoUrl::query()
                    ->where('seoable_type', Product::class)
                    ->where('seoable_id', $product_id)
                    ->delete();

                foreach (Arr::get($payload, 'seo_urls', []) as $seo_url_row) {
                    if (! is_array($seo_url_row)) {
                        continue;
                    }

                    $keyword = Str::trim((string) Arr::get($seo_url_row, 'keyword', ''));
                    if ($keyword === '') {
                        continue;
                    }

                    $shop_language_id = Arr::get($seo_url_row, 'shop_language_id');
                    SeoUrl::query()->create([
                        'seoable_type'     => Product::class,
                        'seoable_id'       => $product_id,
                        'shop_language_id' => is_numeric($shop_language_id) ? (int) $shop_language_id : null,
                        'query_key'        => Str::trim((string) Arr::get($seo_url_row, 'query_key', 'product_id')),
                        'query_value'      => (string) Arr::get($seo_url_row, 'query_value', (string) $product_id),
                        'keyword'          => $keyword,
                        'sort_order'       => (int) Arr::get($seo_url_row, 'sort_order', 0),
                    ]);
                }

                ProductSpecial::query()->where('product_id', $product_id)->delete();
                foreach (Arr::get($payload, 'specials', []) as $special_row) {
                    if (! is_array($special_row)) {
                        continue;
                    }

                    ProductSpecial::query()->create([
                        'product_id'    => $product_id,
                        'user_group_id' => (int) Arr::get($special_row, 'user_group_id', 0),
                        'price'         => (float) Arr::get($special_row, 'price', 0),
                        'priority'      => (int) Arr::get($special_row, 'priority', 0),
                        'date_start'    => Arr::get($special_row, 'date_start'),
                        'date_end'      => Arr::get($special_row, 'date_end'),
                    ]);
                }

                ProductDiscount::query()->where('product_id', $product_id)->delete();
                foreach (Arr::get($payload, 'discounts', []) as $discount_row) {
                    if (! is_array($discount_row)) {
                        continue;
                    }

                    ProductDiscount::query()->create([
                        'product_id'    => $product_id,
                        'user_group_id' => (int) Arr::get($discount_row, 'user_group_id', 0),
                        'quantity'      => max((int) Arr::get($discount_row, 'quantity', 1), 1),
                        'price'         => (float) Arr::get($discount_row, 'price', 0),
                        'priority'      => (int) Arr::get($discount_row, 'priority', 0),
                        'date_start'    => Arr::get($discount_row, 'date_start'),
                        'date_end'      => Arr::get($discount_row, 'date_end'),
                    ]);
                }

                $backup->markAsUsed();
            });
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to restore product from backup', [
                'backup_id'    => (int) $backup->id,
                'product_id'   => $product_id,
                'error_msg'    => $exception->getMessage(),
                'file'         => $exception->getFile(),
                'line'         => $exception->getLine(),
                'exception'    => $exception,
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
