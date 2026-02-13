<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Pages;

use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Filament\Resources\ProductImports\Pages\CreateProductImportBatch;
use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CreateProductUpdateBatch extends CreateProductImportBatch
{
    protected static string $resource = ProductUpdateBatchResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model|ProductUpdateBatch
    {
        $import_batch = parent::handleRecordCreation($data);

        if (! $import_batch instanceof ProductImportBatch) {
            return ProductUpdateBatch::query()->create([
                'user_id' => auth()->id(),
                'source_type' => 'admin_panel',
                'source_name' => __('admin/product_updates/batches.labels.model'),
                'source_path' => null,
                'status' => ProductUpdateBatchesStatusEnum::NEW->value,
                'total_items' => 0,
                'processed_items' => 0,
                'failed_items' => 0,
                'options' => [],
            ]);
        }

        $update_batch = ProductUpdateBatch::query()->create([
            'user_id' => $import_batch->user_id,
            'source_type' => (string) $import_batch->source_type,
            'source_name' => (string) $import_batch->source_name,
            'source_path' => $import_batch->source_path,
            'status' => (string) $import_batch->status,
            'total_items' => (int) $import_batch->total_items,
            'processed_items' => (int) $import_batch->processed_items,
            'failed_items' => (int) $import_batch->failed_items,
            'options' => [
                ...(is_array($import_batch->options) ? $import_batch->options : []),
                'linked_import_batch_id' => (int) $import_batch->id,
            ],
            'started_at' => $import_batch->started_at,
            'finished_at' => $import_batch->finished_at,
        ]);

        ProductImportItem::query()
            ->where('product_import_batch_id', (int) $import_batch->id)
            ->orderBy('id')
            ->get()
            ->each(static function (ProductImportItem $import_item) use ($update_batch): void {
                ProductUpdateItem::query()->create([
                    'product_update_batch_id' => (int) $update_batch->id,
                    'product_id' => $import_item->product_id,
                    'payload' => is_array($import_item->payload) ? $import_item->payload : [],
                    'status' => (string) ($import_item->status ?? ''),
                    'error_message' => Str::limit((string) Arr::get($import_item->getAttributes(), 'error_message', ''), 65000),
                    'processed_at' => $import_item->processed_at,
                ]);
            });

        return $update_batch;
    }

    public function getTitle(): string
    {
        return __('admin/product_updates/batches.actions.create');
    }
}
