<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ProductImports\Pages;

use App\Enums\ProductImportBatchesSourceTypeEnum;
use App\Enums\ProductImportBatchesStatusEnum;
use App\Filament\Resources\Catalog\ProductImports\ProductImportBatchResource;
use App\Jobs\ProcessProductImportBatch;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateProductImportBatch extends CreateRecord
{
    protected static string $resource = ProductImportBatchResource::class;

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.actions.create');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $userId = Auth::id();

        $sourceType = ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value;
        $sourceName = null;
        $sourcePath = null;
        $options    = [];

        // Define sheet keys to map inputs
        $sheetDefs = [
            'products',
            'product_images',
            'product_descriptions',
            'product_discounts',
            'product_specials',
            'seo_urls',
            'attributes',
            'attribute_descriptions',
            'product_to_attributes',
            'categories',
            'category_descriptions',
        ];

        if (!empty($data['excel_file'])) {
            $sourceType = ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value;
            $sourcePath = $data['excel_file'];
            $sourceName = basename((string)$sourcePath);

            $excelSheets = [];
            foreach ($sheetDefs as $key) {
                $excelSheets[$key] = [
                    'sheet_name' => Arr::get($data, "excel_{$key}_sheet"),
                    'range'      => Arr::get($data, "excel_{$key}_range"),
                    'header_row' => (int) (Arr::get($data, "excel_{$key}_header_row") ?? 1),
                ];
            }
            $options['excel'] = [
                'sheets' => $excelSheets,
            ];
        } elseif (!empty($data['sheets_url'])) {
            $sourceType = ProductImportBatchesSourceTypeEnum::API->value;
            $sourcePath = $data['sheets_url'];
            $sourceName = 'Google Sheets';

            $gsheets = [];
            foreach ($sheetDefs as $key) {
                $gsheets[$key] = [
                    'sheet_name' => Arr::get($data, "sheets_{$key}_sheet"),
                    'range'      => Arr::get($data, "sheets_{$key}_range"),
                    'header_row' => (int) (Arr::get($data, "sheets_{$key}_header_row") ?? 1),
                ];
            }
            $options['sheets'] = [
                'url'    => Arr::get($data, 'sheets_url'),
                'sheets' => $gsheets,
            ];
        } else {
            $sourceType = ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value;
            $sourceName = 'Admin Panel';
            $options['admin'] = [
                'product'      => [
                    'model'     => Arr::get($data, 'admin_model'),
                    'sku'       => Arr::get($data, 'admin_sku'),
                    'ean'       => Arr::get($data, 'admin_ean'),
                    'quantity'  => (int) (Arr::get($data, 'admin_quantity') ?? 0),
                    'minimum'   => (int) (Arr::get($data, 'admin_minimum') ?? 1),
                    'price'     => (float) (Arr::get($data, 'admin_price') ?? 0),
                    'is_active' => (bool) (Arr::get($data, 'admin_is_active') ?? false),
                    'note'      => Arr::get($data, 'admin_note'),
                ],
                'descriptions' => Arr::get($data, 'admin_descriptions', []),
                'images'       => Arr::get($data, 'admin_images', []),
                'discounts'    => Arr::get($data, 'admin_discounts', []),
                'specials'     => Arr::get($data, 'admin_specials', []),
                'seo_urls'     => Arr::get($data, 'admin_seo_urls', []),
                'attributes'   => Arr::get($data, 'admin_attributes', []),
                'categories'   => Arr::get($data, 'admin_categories', []),
            ];
        }

        return [
            'user_id'         => $userId,
            'source_type'     => $sourceType,
            'source_name'     => $sourceName,
            'source_path'     => $sourcePath,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => $options,
        ];
    }

    protected function afterCreate(): void
    {
        // Если источник — админка, выполняем синхронно, без постановки в очередь
        if ($this->record->source_type === ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value) {
            (new ProcessProductImportBatch($this->record->id))->handle();
            return;
        }

        // Иначе добавляем задачу в очередь
        ProcessProductImportBatch::dispatch($this->record->id);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admin/product_imports/batches.messages.created');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
