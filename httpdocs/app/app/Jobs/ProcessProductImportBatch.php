<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProductImportBatchesStatusEnum;
use App\Enums\ProductImportItemsStatusEnum;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\SimpleCache\CacheInterface as Psr16Cache;

class ProcessProductImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $batchId)
    {
    }

    public function handle(): void
    {
        /** @var ProductImportBatch|null $batch */
        $batch = ProductImportBatch::query()->find($this->batchId);
        if (!$batch) {
            return;
        }

        $batch->update([
            'status'     => ProductImportBatchesStatusEnum::PROCESSING->value,
            'started_at' => now(),
        ]);

        try {
            match ($batch->source_type) {
                'excel_file'  => $this->processExcel($batch),
                'api'         => $this->processGoogleSheets($batch),
                'admin_panel' => $this->processAdminSingle($batch),
                default       => null,
            };

            // Если были ошибки у отдельных элементов, статус пакета считаем completed,
            // но в будущем можно выставлять PARTIAL_FAILED, если есть failed_items > 0.
            $status = $batch->failed_items > 0
                ? ProductImportBatchesStatusEnum::PARTIAL_FAILED->value
                : ProductImportBatchesStatusEnum::COMPLETED->value;

            $batch->update([
                'status'      => $status,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('stack')->error('Import batch failed', [
                'batch_id' => $batch->id,
                'message'  => $e->getMessage(),
            ]);

            $batch->update([
                'status'      => ProductImportBatchesStatusEnum::FAILED->value,
                'finished_at' => now(),
            ]);
        }
    }

    protected function processExcel(ProductImportBatch $batch): void
    {
        // Configure PhpSpreadsheet cache for PHP 8.4
        try {
            if (class_exists(\PhpOffice\PhpSpreadsheet\Settings::class) && extension_loaded('apcu')) {
                $pool = new \Symfony\Component\Cache\Adapter\ApcuAdapter(namespace: 'phpspreadsheet_cache', defaultLifetime: 3600);
                /** @var Psr16Cache $simpleCache */
                $simpleCache = new \Symfony\Component\Cache\Psr16Cache($pool);
                \PhpOffice\PhpSpreadsheet\Settings::setCache($simpleCache);
            }
        } catch (\Throwable $e) {
            // Fallback silently
            Log::channel('stack')->warning('PhpSpreadsheet cache init failed', [
                'batch_id' => $batch->id,
                'message'  => $e->getMessage(),
            ]);
        }

        // If PhpSpreadsheet not installed yet, skip heavy processing.
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return;
        }

        $path = $batch->source_path;
        if (!$path) {
            return;
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($path)) {
            Log::channel('stack')->warning('Excel file does not exist on disk', [
                'batch_id' => $batch->id,
                'path'     => $path,
            ]);
            return;
        }

        $fullPath = $disk->path($path);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fullPath);
        $spreadsheet = $reader->load($fullPath);

        $sheetsCfg = (array) data_get($batch->options, 'excel.sheets', []);
        $byProduct = [];

        foreach ($sheetsCfg as $sheetKey => $cfg) {
            $sheetName = (string) data_get($cfg, 'sheet_name');
            $range = data_get($cfg, 'range');
            $headerRow = (int) (data_get($cfg, 'header_row', 1));

            $sheet = $sheetName !== '' ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getActiveSheet();
            if (!$sheet) {
                Log::channel('stack')->warning('Sheet not found in Excel', ['sheet' => $sheetName, 'batch_id' => $batch->id]);
                continue;
            }

            $grid = $range ? $sheet->rangeToArray($range, null, true, true, false) : $sheet->toArray(null, true, true, false);
            $assocRows = $this->rowsFromGrid($grid, $headerRow);

            foreach ($assocRows as $row) {
                $key = $this->detectProductKey($row);
                if ($key === null) {
                    // если не удалось определить ключ, создаем уникальный
                    $key = 'row_' . md5(json_encode($row));
                }

                $byProduct[$key] ??= [
                    'source' => 'excel',
                ];
                $byProduct[$key][$sheetKey] ??= [];
                $byProduct[$key][$sheetKey][] = $row;
            }
        }

        $created = 0;
        $failed = 0;
        foreach ($byProduct as $key => $payload) {
            try {
                ProductImportItem::query()->create([
                    'product_import_batch_id' => $batch->id,
                    'raw_payload'             => $payload,
                    'status'                  => ProductImportItemsStatusEnum::NEW->value,
                ]);
                $created++;
            } catch (\Throwable $e) {
                Log::channel('stack')->error('Failed to create import item from Excel', [
                    'batch_id' => $batch->id,
                    'key'      => $key,
                    'message'  => $e->getMessage(),
                ]);
                ProductImportItem::query()->create([
                    'product_import_batch_id' => $batch->id,
                    'raw_payload'             => $payload,
                    'status'                  => ProductImportItemsStatusEnum::FAILED->value,
                    'error_message'           => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $batch->update([
            'total_items'     => $created + $failed,
            'processed_items' => $created,
            'failed_items'    => $failed,
        ]);
    }

    protected function processGoogleSheets(ProductImportBatch $batch): void
    {
        // If package not installed, skip.
        if (!class_exists(\Revolution\Google\Sheets\Facades\Sheets::class)) {
            return;
        }

        $url = (string) data_get($batch->options, 'sheets.url');
        $spreadsheetId = $this->extractSpreadsheetIdFromUrl($url);
        if (!$spreadsheetId) {
            return;
        }

        $sheetsCfg = (array) data_get($batch->options, 'sheets.sheets', []);

        $byProduct = [];
        foreach ($sheetsCfg as $sheetKey => $cfg) {
            $sheetName = (string) data_get($cfg, 'sheet_name');
            $range = (string) data_get($cfg, 'range');
            $headerRow = (int) (data_get($cfg, 'header_row', 1));

            $grid = \Revolution\Google\Sheets\Facades\Sheets::spreadsheet($spreadsheetId)
                ->sheet($sheetName ?: null)
                ->range($range ?: null)
                ->get()
                ->toArray();

            $assocRows = $this->rowsFromGrid($grid, $headerRow);

            foreach ($assocRows as $row) {
                $key = $this->detectProductKey($row);
                if ($key === null) {
                    $key = 'row_' . md5(json_encode($row));
                }
                $byProduct[$key] ??= [
                    'source' => 'sheets',
                ];
                $byProduct[$key][$sheetKey] ??= [];
                $byProduct[$key][$sheetKey][] = $row;
            }
        }

        $created = 0;
        $failed = 0;
        foreach ($byProduct as $key => $payload) {
            try {
                ProductImportItem::query()->create([
                    'product_import_batch_id' => $batch->id,
                    'raw_payload'             => $payload,
                    'status'                  => ProductImportItemsStatusEnum::NEW->value,
                ]);
                $created++;
            } catch (\Throwable $e) {
                Log::channel('stack')->error('Failed to create import item from Google Sheets', [
                    'batch_id' => $batch->id,
                    'key'      => $key,
                    'message'  => $e->getMessage(),
                ]);
                ProductImportItem::query()->create([
                    'product_import_batch_id' => $batch->id,
                    'raw_payload'             => $payload,
                    'status'                  => ProductImportItemsStatusEnum::FAILED->value,
                    'error_message'           => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $batch->update([
            'total_items'     => $created + $failed,
            'processed_items' => $created,
            'failed_items'    => $failed,
        ]);
    }

    protected function processAdminSingle(ProductImportBatch $batch): void
    {
        $payload = (array) data_get($batch->options, 'admin', []);

        try {
            ProductImportItem::query()->create([
                'product_import_batch_id' => $batch->id,
                'raw_payload'             => [
                    'source' => 'admin',
                    'data'   => $payload,
                ],
                'status'                  => ProductImportItemsStatusEnum::NEW->value,
            ]);
            $batch->update([
                'total_items'     => 1,
                'processed_items' => 1,
                'failed_items'    => 0,
            ]);
        } catch (\Throwable $e) {
            Log::channel('stack')->error('Failed to create admin import item', [
                'batch_id' => $batch->id,
                'message'  => $e->getMessage(),
            ]);
            ProductImportItem::query()->create([
                'product_import_batch_id' => $batch->id,
                'raw_payload'             => $payload,
                'status'                  => ProductImportItemsStatusEnum::FAILED->value,
                'error_message'           => $e->getMessage(),
            ]);
            $batch->update([
                'total_items'     => 1,
                'processed_items' => 0,
                'failed_items'    => 1,
            ]);
        }
    }

    protected function extractSpreadsheetIdFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        // Typical format: https://docs.google.com/spreadsheets/d/{id}/edit#...
        if (preg_match('~spreadsheets/d/([a-zA-Z0-9-_]+)~', $url, $m)) {
            return $m[1];
        }
        // If only ID provided
        if (preg_match('~^[a-zA-Z0-9-_]{20,}$~', $url)) {
            return $url;
        }
        return null;
    }

    /**
     * Преобразует двумерный массив в список ассоциативных строк, используя строку заголовков.
     * @param array<int, array<int|string, mixed>> $grid
     * @return array<int, array<string, mixed>>
     */
    protected function rowsFromGrid(array $grid, int $headerRow = 1): array
    {
        if (empty($grid)) {
            return [];
        }
        $grid = array_values($grid);
        $offset = max(0, $headerRow - 1);
        if ($offset > 0) {
            $grid = array_slice($grid, $offset);
        }
        if (empty($grid)) {
            return [];
        }
        $headers = array_map(function ($h) {
            $h = is_string($h) ? $h : (string) $h;
            $h = trim($h);
            $h = strtolower(preg_replace('/\s+/', '_', $h));
            return $h ?: 'col_' . uniqid();
        }, array_values($grid[0]));

        $rows = [];
        foreach (array_slice($grid, 1) as $line) {
            $values = array_values($line);
            $assoc = [];
            foreach ($headers as $i => $name) {
                $assoc[$name] = $values[$i] ?? null;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    /**
     * Пытается определить ключ продукта по стандартным полям.
     */
    protected function detectProductKey(array $row): ?string
    {
        $candidates = ['sku', 'model', 'product_id', 'id'];
        foreach ($candidates as $c) {
            foreach ($row as $k => $v) {
                if (strtolower((string)$k) === $c && $v !== null && $v !== '') {
                    return (string) $v;
                }
            }
        }
        return null;
    }
}
