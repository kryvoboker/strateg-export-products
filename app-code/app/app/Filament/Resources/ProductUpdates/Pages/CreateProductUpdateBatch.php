<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Pages;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use App\Jobs\ProcessProductUpdateBatchJob;
use App\Models\Products\Updates\ProductUpdateBatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CreateProductUpdateBatch extends CreateRecord
{
    protected static string $resource = ProductUpdateBatchResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model|ProductUpdateBatch
    {
        $excel_path = Str::trim((string) ($data['excel_file'] ?? ''));
        $sheets_url = Str::trim((string) ($data['sheets_url'] ?? ''));

        if ($excel_path !== '') {
            return $this->createBatchFromExcel($excel_path);
        }

        if ($sheets_url !== '') {
            return $this->createBatchFromGoogleSheets($sheets_url);
        }

        $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.import_mode_required'));

        return new ProductUpdateBatch();
    }

    public function getTitle(): string
    {
        return __('admin/product_updates/batches.actions.create');
    }

    private function createBatchFromExcel(string $excel_path): ProductUpdateBatch
    {
        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => $this->resolveUserId(),
            'source_type'     => ProductUpdateBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => basename($excel_path),
            'source_path'     => $excel_path,
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'    => 'excel',
                'uploaded_file' => $excel_path,
            ],
        ]);

        ProcessProductUpdateBatchJob::dispatchSync((int) $batch->id);

        return $batch->refresh();
    }

    private function createBatchFromGoogleSheets(string $sheets_url): ProductUpdateBatch
    {
        if (validate_url($sheets_url) === false) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.google_sheet_url_invalid'));
        }

        $spreadsheet_id = $this->extractSpreadsheetId($sheets_url);
        if ($spreadsheet_id === null) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.google_sheet_url_invalid'));
        }

        $batch = ProductUpdateBatch::query()->create([
            'user_id'         => $this->resolveUserId(),
            'source_type'     => ProductUpdateBatchesSourceTypeEnum::GOOGLE_SHEET->value,
            'source_name'     => __('admin/product_imports/batches.source_names.google_sheets', ['id' => $spreadsheet_id]),
            'source_path'     => $sheets_url,
            'status'          => ProductUpdateBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'       => 'google_sheets',
                'google_sheet_url' => $sheets_url,
                'spreadsheet_id'   => $spreadsheet_id,
            ],
        ]);

        ProcessProductUpdateBatchJob::dispatchSync((int) $batch->id);

        return $batch->refresh();
    }

    private function extractSpreadsheetId(string $sheets_url): ?string
    {
        if (empty($matches = Str::match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $sheets_url))) {
            return null;
        }

        return $matches;
    }

    private function resolveUserId(): ?int
    {
        $id = Auth::id();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @throws Halt
     */
    private function sendDangerAndHalt(string $message): void
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->send();

        $this->halt();
    }
}
