<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ProductImports\Pages;

use App\Enums\ProductImportBatchesSourceTypeEnum;
use App\Enums\ProductImportBatchesStatusEnum;
use App\Filament\Resources\Catalog\ProductImports\ProductImportBatchResource;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Products\Imports\ProductImportBatch;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateProductImportBatch extends CreateRecord
{
    protected static string              $resource = ProductImportBatchResource::class;
    public null|Model|ProductImportBatch $record   = null;

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.actions.create');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user_id = Auth::id();

        $source_type = ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value;
        $source_name = null;
        $source_path = null;
        $options    = [];

        // Define sheet keys to map inputs
        $sheet_defs = [
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
            $source_type = ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value;
            $source_path = $data['excel_file'];
            $source_name = basename((string)$source_path);

            $excel_sheets = [];

            foreach ($sheet_defs as $key) {
                $excel_sheets[$key] = [
                    'sheet_name' => Arr::get($data, "excel_{$key}_sheet"),
                    'range'      => Arr::get($data, "excel_{$key}_range"),
                    'header_row' => (int)(Arr::get($data, "excel_{$key}_header_row") ?? 1),
                ];
            }
            $options['excel'] = [
                'sheets' => $excel_sheets,
            ];
        } else if (!empty($data['sheets_url'])) {
            $source_type = ProductImportBatchesSourceTypeEnum::API->value;
            $source_path = $data['sheets_url'];
            $source_name = 'Google Sheets';

            $gsheets = [];

            foreach ($sheet_defs as $key) {
                $gsheets[$key] = [
                    'sheet_name' => Arr::get($data, "sheets_{$key}_sheet"),
                    'range'      => Arr::get($data, "sheets_{$key}_range"),
                    'header_row' => (int)(Arr::get($data, "sheets_{$key}_header_row") ?? 1),
                ];
            }
            $options['sheets'] = [
                'url'    => Arr::get($data, 'sheets_url'),
                'sheets' => $gsheets,
            ];
        } else {
            $source_type       = ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value;
            $source_name       = 'Admin Panel';
            $options['admin'] = [
                'product'      => [
                    'model'     => Arr::get($data, 'admin_model'),
                    'sku'       => Arr::get($data, 'admin_sku'),
                    'ean'       => Arr::get($data, 'admin_ean'),
                    'quantity'  => (int)(Arr::get($data, 'admin_quantity') ?? 0),
                    'minimum'   => (int)(Arr::get($data, 'admin_minimum') ?? 1),
                    'price'     => (float)(Arr::get($data, 'admin_price') ?? 0),
                    'is_active' => (bool)(Arr::get($data, 'admin_is_active') ?? false),
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
            'user_id'         => $user_id,
            'source_type'     => $source_type,
            'source_name'     => $source_name,
            'source_path'     => $source_path,
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
            new ProcessProductImportBatchJob($this->record->id)->handle();

            return;
        }

        // Иначе добавляем задачу в очередь
        ProcessProductImportBatchJob::dispatch($this->record->id);
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
