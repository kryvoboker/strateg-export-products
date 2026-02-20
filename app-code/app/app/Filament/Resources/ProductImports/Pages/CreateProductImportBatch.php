<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Pages;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Jobs\ProcessProductImportBatchJob;
use App\Models\Products\Imports\ProductImportBatch;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Revolution\Google\Sheets\Facades\Sheets;
use Throwable;

class CreateProductImportBatch extends CreateRecord
{
    private const array REQUIRED_SHEETS = [
        'Product',
        'Description',
        'Image',
        'Product Category',
        'Product Attribute',
        'Seo Url',
        'Special',
        'Discount',
    ];

    private const int MAX_ROWS_PER_FILE = 500;

    private const array REQUIRED_HEADERS = [
        'Product' => [
            'Product Id',
            'Model',
            'SKU',
            'EAN',
            'Quantity',
            'Minimum',
            'Image',
            'Price',
            'Manufacturer',
            'Brand',
            'Is Active',
            'Date Available',
            'Date Added',
        ],
        'Description' => [
            'Product Id',
            'Name',
            'Description',
            'Meta Title',
            'Meta Description',
            'Meta Keywords',
        ],
        'Image' => [
            'Product Id',
            'Image',
            'Sort Order',
        ],
        'Product Category' => [
            'Product Id',
            'Category Name',
        ],
        'Product Attribute' => [
            'Product Id',
            'Attribute Name',
            'Attribute Text',
        ],
        'Seo Url' => [
            'Product Id',
            'Query Key',
            'Query Value',
            'Keyword',
            'Sort Order',
        ],
        'Special' => [
            'Product Id',
            'User Group Id',
            'Price',
            'Priority',
            'Date Start',
            'Date End',
        ],
        'Discount' => [
            'Product Id',
            'User Group Id',
            'Quantity',
            'Price',
            'Priority',
            'Date Start',
            'Date End',
        ],
    ];

    protected static string $resource = ProductImportBatchResource::class;

    public null|Model|ProductImportBatch $record = null;

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected function handleRecordCreation(array $data): Model|ProductImportBatch
    {
        $mode = $this->resolveImportMode($data);

        return match ($mode) {
            'excel'  => $this->createBatchFromExcel($data),
            'google' => $this->createBatchFromGoogleSheets($data),
            'manual' => $this->createBatchFromManualForm($data),
            default  => throw new \RuntimeException('Unsupported import mode: '.$mode),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    private function createBatchFromExcel(array $data): ProductImportBatch
    {
        $excel_path = Str::trim((string) ($data['excel_file'] ?? ''));

        if ($excel_path === '') {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.excel_file_required'));
        }

        $this->validateExcelStructure($excel_path);

        $batch = ProductImportBatch::query()->create([
            'user_id'         => $this->resolveUserId(),
            'source_type'     => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
            'source_name'     => basename($excel_path),
            'source_path'     => $excel_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'    => 'excel',
                'uploaded_file' => $excel_path,
            ],
        ]);

        ProcessProductImportBatchJob::dispatchSync($batch->id);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    private function createBatchFromGoogleSheets(array $data): ProductImportBatch
    {
        $exported_files = [];
        $source_path    = '';
        $sheets_url     = Str::trim((string) ($data['sheets_url'] ?? ''));

        if ($sheets_url === '' || validate_url($sheets_url) === false) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.google_sheet_url_required'));
        }

        $extract_spreadsheet_id = $this->extractSpreadsheetId($sheets_url);

        if ($extract_spreadsheet_id === null) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.google_sheet_url_invalid'));
        }

        try {
            $sheet_names = array_values(Sheets::spreadsheet($extract_spreadsheet_id)->sheetList());
            $this->validateRequiredSheetNames($sheet_names);

            $all_sheets_rows = [];

            foreach ($sheet_names as $sheet_name) {
                $rows = Sheets::spreadsheet($extract_spreadsheet_id)
                    ->sheet($sheet_name)
                    ->all();

                $all_sheets_rows[$sheet_name] = $this->normalizeRows($rows);
            }

            $this->validateRequiredHeadersFromRows($all_sheets_rows);

            if (! $this->hasDataRows($all_sheets_rows['Product'] ?? [])) {
                $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.product_sheet_has_no_data'));
            }

            [$source_path, $exported_files] = $this->storeGoogleSheetsAsExcelFiles(
                spreadsheetId: $extract_spreadsheet_id,
                allSheetsRows: $all_sheets_rows
            );
        } catch (Halt $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('stack')->error($e->getMessage(), [
                'current_file'   => __FILE__,
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
            ]);

            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.google_sheet_fetch_failed'));
        }

        $batch = ProductImportBatch::query()->create([
            'user_id'         => $this->resolveUserId(),
            'source_type'     => ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value,
            'source_name'     => __('admin/product_imports/batches.source_names.google_sheets', ['id' => $extract_spreadsheet_id]),
            'source_path'     => $source_path,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 0,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode'       => 'google_sheets',
                'google_sheet_url' => $sheets_url,
                'spreadsheet_id'   => $extract_spreadsheet_id,
                'exported_files'   => $exported_files,
            ],
        ]);

        ProcessProductImportBatchJob::dispatchSync($batch->id);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    private function createBatchFromManualForm(array $data): ProductImportBatch
    {
        $payload = $this->extractManualPayload($data);

        if ($payload === []) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.manual_data_required'));
        }

        $batch = ProductImportBatch::query()->create([
            'user_id'         => $this->resolveUserId(),
            'source_type'     => ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value,
            'source_name'     => __('admin/product_imports/batches.source_names.manual_import', ['datetime' => now()->format('Y-m-d H:i:s')]),
            'source_path'     => null,
            'status'          => ProductImportBatchesStatusEnum::NEW->value,
            'total_items'     => 1,
            'processed_items' => 0,
            'failed_items'    => 0,
            'options'         => [
                'input_mode' => 'manual',
                'raw_data'   => $payload,
            ],
        ]);

        ProcessProductImportBatchJob::dispatchSync($batch->id);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractManualPayload(array $data): array
    {
        $manual = [];

        foreach ($data as $key => $value) {
            if (Str::startsWith($key, 'admin_') === false) {
                continue;
            }

            $manual[$key] = $value;
        }

        return $this->removeEmptyValues($manual);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    private function resolveImportMode(array $data): string
    {
        if (Str::trim((string) ($data['excel_file'] ?? '')) !== '') {
            return 'excel';
        }

        if (Str::trim((string) ($data['sheets_url'] ?? '')) !== '') {
            return 'google';
        }

        if ($this->extractManualPayload($data) !== []) {
            return 'manual';
        }

        $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.import_mode_required'));

        return '';
    }

    private function extractSpreadsheetId(string $sheetsUrl): ?string
    {
        if (empty($matches = Str::match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $sheetsUrl))) {
            return null;
        }

        return $matches;
    }

    /**
     * @param  list<string>  $sheetNames
     *
     * @throws Halt
     */
    private function validateRequiredSheetNames(array $sheetNames): void
    {
        $missing = array_values(array_diff(self::REQUIRED_SHEETS, $sheetNames));

        if ($missing === []) {
            return;
        }

        $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.required_sheet_missing', ['sheets' => implode(', ', $missing)]));
    }

    /**
     * @param  array<string, list<array<int, mixed>>>  $allSheetsRows
     *
     * @throws Halt
     */
    private function validateRequiredHeadersFromRows(array $allSheetsRows): void
    {
        foreach (self::REQUIRED_HEADERS as $sheet_name => $REQUIRED_HEADER) {
            $rows = $allSheetsRows[$sheet_name] ?? [];

            if ($rows === []) {
                $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.sheet_is_empty', ['sheet' => $sheet_name]));
            }

            $header            = $rows[0] ?? [];
            $normalized_header = array_map(fn ($cell) => $this->normalizeHeaderKey((string) $cell), $header);

            $missing_headers = [];

            foreach ($REQUIRED_HEADER as $required_header) {
                if (! $this->isHeaderPresent($required_header, $normalized_header)) {
                    $missing_headers[] = $required_header;
                }
            }

            if ($missing_headers !== []) {
                $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.sheet_missing_columns', [
                    'sheet'   => $sheet_name,
                    'columns' => implode(', ', $missing_headers),
                ]));
            }
        }
    }

    /**
     * @throws Halt
     */
    private function validateExcelStructure(string $excelPath): void
    {
        try {
            $spreadsheet = IOFactory::load(Storage::path($excelPath));
        } catch (Throwable $e) {
            Log::channel('stack')->error($e->getMessage(), [
                'current_file'   => __FILE__,
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
            ]);

            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.excel_read_failed'));

            return;
        }

        $sheet_names = $spreadsheet->getSheetNames();
        $this->validateRequiredSheetNames($sheet_names);

        $all_sheets_rows = [];

        foreach ($sheet_names as $sheet_name) {
            $worksheet = $spreadsheet->getSheetByName($sheet_name);

            if ($worksheet === null) {
                continue;
            }

            $all_sheets_rows[$sheet_name] = $this->normalizeRows($worksheet->toArray());
        }

        $this->validateRequiredHeadersFromRows($all_sheets_rows);

        if (! $this->hasDataRows($all_sheets_rows['Product'] ?? [])) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.product_sheet_has_no_data'));
        }

        $spreadsheet->disconnectWorksheets();

        unset($spreadsheet);
    }

    /**
     * @param  array<string, list<array<int, mixed>>>  $allSheetsRows
     * @return array{0: string, 1: list<string>}
     */
    private function storeGoogleSheetsAsExcelFiles(string $spreadsheetId, array $allSheetsRows): array
    {
        $chunk_count = 1;

        foreach ($allSheetsRows as $rows) {
            $data_row_count = max(count($rows) - 1, 0);
            $chunk_count    = max($chunk_count, (int) ceil($data_row_count / self::MAX_ROWS_PER_FILE));
        }

        $now             = now();
        $year_month_path = sprintf('upload/excel/%s/%s', $now->format('Y'), $now->format('m'));
        $timestamp       = $now->format('Ymd_His');
        $suffix          = Str::replaceMatches('/[^a-zA-Z0-9_-]/', '', $spreadsheetId) ?: 'sheet';

        $base_folder_path = sprintf('%s/google_sheet_%s_%s', $year_month_path, $timestamp, $suffix);
        $single_file_path = sprintf('%s/google_sheet_%s_%s.xlsx', $year_month_path, $timestamp, $suffix);

        $exported_files = [];

        if ($chunk_count > 1) {
            Storage::makeDirectory($base_folder_path);

            for ($chunk_index = 1; $chunk_index <= $chunk_count; $chunk_index++) {
                $file_path = sprintf('%s/part_%02d.xlsx', $base_folder_path, $chunk_index);

                $this->writeChunkToExcelFile($file_path, $allSheetsRows, $chunk_index);

                $exported_files[] = $file_path;
            }

            return [$base_folder_path, $exported_files];
        }

        $this->writeChunkToExcelFile($single_file_path, $allSheetsRows, 1);

        $exported_files[] = $single_file_path;

        return [$single_file_path, $exported_files];
    }

    /**
     * @param  array<string, list<array<int, mixed>>>  $all_sheets_rows
     */
    private function writeChunkToExcelFile(string $file_path, array $all_sheets_rows, int $chunk_index): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $start_offset = ($chunk_index - 1) * self::MAX_ROWS_PER_FILE;

        foreach ($all_sheets_rows as $sheet_name => $rows) {
            $worksheet = new Worksheet($spreadsheet, $this->sanitizeSheetTitle($sheet_name));
            $spreadsheet->addSheet($worksheet);

            $header     = $rows[0] ?? [];
            $dataRows   = array_slice($rows, 1);
            $data_chunk = array_slice($dataRows, $start_offset, self::MAX_ROWS_PER_FILE);

            $rows_to_write = [];

            if ($header !== []) {
                $rows_to_write[] = $header;
            }

            if ($data_chunk !== []) {
                $rows_to_write = [...$rows_to_write, ...$data_chunk];
            }

            if ($rows_to_write !== []) {
                $worksheet->fromArray($rows_to_write, null, 'A1', true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        Storage::makeDirectory(dirname($file_path));

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

        $writer->save(Storage::path($file_path));

        $spreadsheet->disconnectWorksheets();

        unset($spreadsheet);
    }

    private function sanitizeSheetTitle(string $sheetTitle): string
    {
        $sanitized = Str::replaceMatches('~[:\\\\/?*\\[\\]]~', '_', Str::trim($sheetTitle));
        $sanitized = $sanitized === '' ? 'Sheet' : $sanitized;

        return Str::substr($sanitized, 0, 31);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function hasDataRows(array $rows): bool
    {
        if (count($rows) <= 1) {
            return false;
        }

        $data_rows = array_slice($rows, 1);

        foreach ($data_rows as $row) {
            foreach ($row as $cell) {
                if (Str::trim((string) $cell) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return list<array<int, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $normalized[] = array_map(static fn ($cell) => is_scalar($cell) ? (string) $cell : '', array_values($row));
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $normalizedHeader
     */
    private function isHeaderPresent(string $requiredHeader, array $normalizedHeader): bool
    {
        $requiredHeader = $this->normalizeHeaderKey($requiredHeader);

        if ($requiredHeader === 'attribute_text') {
            return in_array('attribute_text', $normalizedHeader, true)
                || in_array('attibute_text', $normalizedHeader, true);
        }

        return in_array($requiredHeader, $normalizedHeader, true);
    }

    private function normalizeHeaderKey(string $header): string
    {
        return Str::snake(Str::squish(Str::replace(['-', '_'], ' ', Str::trim($header))));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function removeEmptyValues(array $data): array
    {
        $cleaned = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeEmptyValues($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    /**
     * @throws Halt
     */
    private function sendDangerAndHalt(string $message): void
    {
        Notification::make()
            ->title(__('admin/default.errors.title'))
            ->body($message)
            ->danger()
            ->send();

        $this->halt();
    }

    /**
     * @throws Halt
     */
    private function resolveUserId(): int
    {
        $userId = Auth::id();

        if ($userId === null) {
            $this->sendDangerAndHalt(__('admin/product_imports/batches.errors.user_not_authorized'));
        }

        return (int) $userId;
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
