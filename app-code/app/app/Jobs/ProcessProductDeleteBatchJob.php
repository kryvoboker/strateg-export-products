<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Delete\ProductDeleteBatchesSourceTypeEnum;
use App\Enums\Product\Delete\ProductDeleteBatchesStatusEnum;
use App\Enums\Product\Delete\ProductDeleteItemsStatusEnum;
use App\Models\Products\Deletes\ProductDeleteBatch;
use App\Models\Products\Deletes\ProductDeleteItem;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Revolution\Google\Sheets\Facades\Sheets;
use RuntimeException;
use Throwable;

class ProcessProductDeleteBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var list<string>
     */
    private const array UNIQUE_KEYS = ['product_id', 'external_product_id', 'model', 'ean'];

    public function __construct(public int $product_delete_batch_id) {}

    public function handle(): void
    {
        $product_delete_batch = ProductDeleteBatch::query()->find($this->product_delete_batch_id);

        if (! $product_delete_batch instanceof ProductDeleteBatch) {
            Log::channel('stack')->warning('Product delete batch not found', [
                'product_delete_batch_id' => $this->product_delete_batch_id,
            ]);

            return;
        }

        $product_delete_batch->update([
            'status'      => ProductDeleteBatchesStatusEnum::PROCESSING->value,
            'started_at'  => now(),
            'finished_at' => null,
            'options'     => [
                ...($product_delete_batch->options ?? []),
                'prepare_started_at'  => now()->toDateTimeString(),
                'prepare_finished_at' => null,
                'last_error'          => null,
            ],
        ]);

        try {
            $normalized_document = $this->normalizeDocumentRows($product_delete_batch);

            DB::transaction(function () use ($product_delete_batch, $normalized_document): void {
                ProductDeleteItem::query()
                    ->where('product_delete_batch_id', (int) $product_delete_batch->id)
                    ->delete();

                $this->prepareDeleteItemsFromDocument((int) $product_delete_batch->id, $normalized_document);
            });

            $this->syncBatchCountersAndStatus($product_delete_batch);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to prepare product delete batch', [
                'product_delete_batch_id' => (int) $product_delete_batch->id,
                'error_msg'               => $exception->getMessage(),
                'file'                    => $exception->getFile(),
                'line'                    => $exception->getLine(),
            ]);

            $product_delete_batch->update([
                'status'      => ProductDeleteBatchesStatusEnum::FAILED->value,
                'finished_at' => now(),
                'options'     => [
                    ...($product_delete_batch->options ?? []),
                    'prepare_finished_at' => now()->toDateTimeString(),
                    'last_error'          => Str::limit(Str::trim($exception->getMessage()), 10000),
                ],
            ]);
        }
    }

    /**
     * @return array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>
     */
    private function normalizeDocumentRows(ProductDeleteBatch $product_delete_batch): array
    {
        return match ((string) $product_delete_batch->source_type) {
            ProductDeleteBatchesSourceTypeEnum::EXCEL_FILE->value   => $this->normalizeExcelDocumentRows((string) $product_delete_batch->source_path),
            ProductDeleteBatchesSourceTypeEnum::GOOGLE_SHEET->value => $this->normalizeGoogleDocumentRows($product_delete_batch),
            default                                                 => throw new RuntimeException('Unsupported source_type for delete batch: '.$product_delete_batch->source_type),
        };
    }

    /**
     * @return array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>
     */
    private function normalizeExcelDocumentRows(string $source_path): array
    {
        $resolved_path       = $this->resolveExcelPath($source_path);
        $spreadsheet         = IOFactory::load($resolved_path);
        $normalized_document = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheet_name        = $worksheet->getTitle();
            $highest_row       = $worksheet->getHighestDataRow();
            $highest_col_index = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());

            if ($highest_row < 1 || $highest_col_index < 1) {
                continue;
            }

            $headers = [];
            for ($col_index = 1; $col_index <= $highest_col_index; $col_index++) {
                $header_value = Str::trim((string) $worksheet->getCell([$col_index, 1])->getValue());
                if ($header_value === '') {
                    continue;
                }

                $normalized_header = $this->normalizeHeaderKey($header_value);
                if ($normalized_header === '') {
                    continue;
                }

                $headers[$normalized_header] = $col_index;
            }

            $rows = [];
            for ($row_index = 2; $row_index <= $highest_row; $row_index++) {
                $row_data      = [];
                $has_any_value = false;

                foreach ($headers as $normalized_header => $col_index) {
                    $clean_value                  = Str::trim((string) $worksheet->getCell([$col_index, $row_index])->getCalculatedValue());
                    $row_data[$normalized_header] = $clean_value;
                    if ($clean_value !== '') {
                        $has_any_value = true;
                    }
                }

                if ($has_any_value) {
                    $rows[$row_index] = $row_data;
                }
            }

            $normalized_document[$sheet_name] = [
                'headers' => $headers,
                'rows'    => $rows,
            ];
        }

        return $normalized_document;
    }

    /**
     * @return array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>
     */
    private function normalizeGoogleDocumentRows(ProductDeleteBatch $product_delete_batch): array
    {
        $options        = is_array($product_delete_batch->options) ? $product_delete_batch->options : [];
        $spreadsheet_id = Str::trim((string) Arr::get($options, 'spreadsheet_id', ''));

        if ($spreadsheet_id === '') {
            $google_sheet_url = Str::trim((string) Arr::get($options, 'google_sheet_url', ''));
            if ($google_sheet_url !== '') {
                $spreadsheet_id = $this->extractSpreadsheetId($google_sheet_url) ?? '';
            }
        }

        if ($spreadsheet_id === '') {
            throw new RuntimeException('Missing spreadsheet id for google sheets delete batch');
        }

        $sheet_names = array_values(Sheets::spreadsheet($spreadsheet_id)->sheetList());

        $normalized_document = [];
        foreach ($sheet_names as $sheet_name) {
            $sheet_rows = Sheets::spreadsheet($spreadsheet_id)->sheet($sheet_name)->all();

            if (! is_array($sheet_rows) || $sheet_rows === []) {
                continue;
            }

            $header_row = array_shift($sheet_rows);
            if (! is_array($header_row)) {
                continue;
            }

            $headers = [];
            foreach ($header_row as $header_index => $header_value) {
                $normalized_header = $this->normalizeHeaderKey((string) $header_value);
                if ($normalized_header === '') {
                    continue;
                }

                $headers[$normalized_header] = (int) $header_index;
            }

            $rows       = [];
            $row_number = 2;
            foreach ($sheet_rows as $sheet_row) {
                if (! is_array($sheet_row)) {
                    $row_number++;

                    continue;
                }

                $row_data      = [];
                $has_any_value = false;
                foreach ($headers as $normalized_header => $header_index) {
                    $clean_value                  = Str::trim((string) ($sheet_row[$header_index] ?? ''));
                    $row_data[$normalized_header] = $clean_value;
                    if ($clean_value !== '') {
                        $has_any_value = true;
                    }
                }

                if ($has_any_value) {
                    $rows[$row_number] = $row_data;
                }

                $row_number++;
            }

            $normalized_document[(string) $sheet_name] = [
                'headers' => $headers,
                'rows'    => $rows,
            ];
        }

        return $normalized_document;
    }

    /**
     * @param  array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>  $normalized_document
     */
    private function prepareDeleteItemsFromDocument(int $product_delete_batch_id, array $normalized_document): void
    {
        $product_sheet = $normalized_document['Product'] ?? null;
        if (! is_array($product_sheet)) {
            throw new RuntimeException('Sheet "Product" is required to identify products for delete');
        }

        $product_rows = Arr::get($product_sheet, 'rows', []);
        if (! is_array($product_rows) || $product_rows === []) {
            throw new RuntimeException('Sheet "Product" has no data rows for delete');
        }

        foreach ($product_rows as $row_number => $product_row) {
            try {
                $unique_values = $this->extractUniqueValuesFromProductRow($product_row);
                if ($this->isAllUniqueValuesEmpty($unique_values)) {
                    $this->createFailedDeleteItem(
                        $product_delete_batch_id,
                        (int) $row_number,
                        $product_row,
                        'Missing unique values in Product row. Required at least one: product_id, external_product_id, model, ean.'
                    );

                    continue;
                }

                [$resolved_product_id, $resolved_shop_id, $resolve_error_message] = $this->resolveProductByUniqueValues($unique_values);
                if ($resolved_product_id <= 0) {
                    $this->createFailedDeleteItem(
                        $product_delete_batch_id,
                        (int) $row_number,
                        $product_row,
                        $resolve_error_message ?: 'Product was not found by unique values'
                    );

                    continue;
                }

                $resolved_external_product_id = 0;
                if ($resolved_shop_id > 0) {
                    $resolved_external_product_id = (int) (ProductShop::query()
                        ->where('product_id', $resolved_product_id)
                        ->where('shop_id', $resolved_shop_id)
                        ->orderByDesc('id')
                        ->value('external_product_id') ?? 0);
                }

                ProductDeleteItem::query()->create([
                    'product_delete_batch_id' => $product_delete_batch_id,
                    'product_id'              => $resolved_product_id,
                    'payload'                 => [
                        'operation' => 'prepare_delete',
                        'source_meta' => [
                            'row_number' => (int) $row_number,
                        ],
                        'resolved_shop_id'           => $resolved_shop_id > 0 ? $resolved_shop_id : null,
                        'resolved_external_product_id' => $resolved_external_product_id > 0 ? $resolved_external_product_id : null,
                        'unique_values'              => $unique_values,
                    ],
                    'status'        => ProductDeleteItemsStatusEnum::NEW->value,
                    'error_message' => null,
                    'processed_at'  => null,
                ]);
            } catch (Throwable $exception) {
                $this->createFailedDeleteItem(
                    $product_delete_batch_id,
                    (int) $row_number,
                    $product_row,
                    $exception->getMessage()
                );

                Log::channel('stack')->error('Product delete row processing failed', [
                    'product_delete_batch_id' => $product_delete_batch_id,
                    'row_number'              => (int) $row_number,
                    'error_msg'               => $exception->getMessage(),
                    'file'                    => $exception->getFile(),
                    'line'                    => $exception->getLine(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, string>  $product_row
     * @return array{product_id:string,shop_id:string,external_product_id:string,model:string,ean:string}
     */
    private function extractUniqueValuesFromProductRow(array $product_row): array
    {
        $product_id          = $this->normalizeLookupIdentifierValue((string) ($product_row[$this->normalizeHeaderKey('Product Id')] ?? ''));
        $shop_id             = $this->normalizeLookupIdentifierValue((string) ($product_row[$this->normalizeHeaderKey('Shop Id')] ?? ''));
        $external_product_id = $this->normalizeLookupIdentifierValue((string) ($product_row[$this->normalizeHeaderKey('External Product Id')] ?? ''));
        $model               = $this->normalizeLookupIdentifierValue((string) ($product_row[$this->normalizeHeaderKey('Model')] ?? ''));
        $ean                 = $this->normalizeLookupIdentifierValue((string) ($product_row[$this->normalizeHeaderKey('EAN')] ?? ''));

        return [
            'product_id'          => $product_id,
            'shop_id'             => $shop_id,
            'external_product_id' => $external_product_id,
            'model'               => $model,
            'ean'                 => $ean,
        ];
    }

    /**
     * @param  array{product_id:string,shop_id:string,external_product_id:string,model:string,ean:string}  $unique_values
     * @return array{0:int,1:int,2:string}
     */
    private function resolveProductByUniqueValues(array $unique_values): array
    {
        $product_id = Str::trim((string) ($unique_values['product_id'] ?? ''));
        if ($product_id !== '' && ctype_digit($product_id)) {
            $resolved_product_id = (int) $product_id;
            $product_exists      = Product::query()->whereKey($resolved_product_id)->exists();

            if ($product_exists) {
                $resolved_shop_id = (int) (ProductShop::query()
                    ->where('product_id', $resolved_product_id)
                    ->orderByDesc('id')
                    ->value('shop_id') ?? 0);

                return [$resolved_product_id, $resolved_shop_id, ''];
            }

            return [0, 0, 'Product not found by product_id'];
        }

        $shop_id = Str::trim((string) ($unique_values['shop_id'] ?? ''));
        if ($shop_id === '' || ! ctype_digit($shop_id) || (int) $shop_id <= 0) {
            return [0, 0, 'shop_id is required when resolving by external_product_id, model or ean'];
        }

        $resolved_shop_id = (int) $shop_id;

        $external_product_id = Str::trim((string) ($unique_values['external_product_id'] ?? ''));
        $model               = Str::trim((string) ($unique_values['model'] ?? ''));
        $ean                 = Str::trim((string) ($unique_values['ean'] ?? ''));

        $candidate_sets = [];

        if ($external_product_id !== '') {
            $candidate_sets['external_product_id'] = ProductShop::query()
                ->where('shop_id', $resolved_shop_id)
                ->where('external_product_id', $external_product_id)
                ->pluck('product_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($model !== '') {
            $candidate_sets['model'] = Product::query()
                ->where('model', $model)
                ->whereExists(static function ($query) use ($resolved_shop_id): void {
                    $query
                        ->selectRaw('1')
                        ->from('product_shop')
                        ->whereColumn('product_shop.product_id', 'products.id')
                        ->where('product_shop.shop_id', $resolved_shop_id);
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($ean !== '') {
            $candidate_sets['ean'] = Product::query()
                ->where('ean', $ean)
                ->whereExists(static function ($query) use ($resolved_shop_id): void {
                    $query
                        ->selectRaw('1')
                        ->from('product_shop')
                        ->whereColumn('product_shop.product_id', 'products.id')
                        ->where('product_shop.shop_id', $resolved_shop_id);
                })
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        if ($candidate_sets === []) {
            return [0, 0, 'Product not found by unique values'];
        }

        foreach ($candidate_sets as $key => $candidate_ids) {
            if ($candidate_ids === []) {
                return [0, 0, 'Product not found by '.$key];
            }
        }

        $resolved_product_ids = null;
        foreach ($candidate_sets as $candidate_ids) {
            if ($resolved_product_ids === null) {
                $resolved_product_ids = $candidate_ids;

                continue;
            }

            $resolved_product_ids = array_values(array_intersect($resolved_product_ids, $candidate_ids));
        }

        $resolved_product_ids = $resolved_product_ids ?? [];

        if (count($resolved_product_ids) === 1) {
            return [(int) $resolved_product_ids[0], $resolved_shop_id, ''];
        }

        if (count($resolved_product_ids) > 1) {
            $used_keys = implode(', ', array_keys($candidate_sets));

            return [0, 0, 'Multiple products found by: '.$used_keys];
        }

        return [0, 0, 'Product not found by combined unique values'];
    }

    /**
     * @param  array{product_id:string,shop_id:string,external_product_id:string,model:string,ean:string}  $unique_values
     */
    private function isAllUniqueValuesEmpty(array $unique_values): bool
    {
        foreach (self::UNIQUE_KEYS as $unique_key) {
            if (Str::trim((string) ($unique_values[$unique_key] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $product_row
     */
    private function createFailedDeleteItem(
        int $product_delete_batch_id,
        int $row_number,
        array $product_row,
        string $error_message
    ): void {
        ProductDeleteItem::query()->create([
            'product_delete_batch_id' => $product_delete_batch_id,
            'product_id'              => null,
            'payload'                 => [
                'operation'   => 'prepare_delete',
                'source_meta' => [
                    'row_number' => $row_number,
                ],
                'row_data' => $product_row,
            ],
            'status'        => ProductDeleteItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at'  => now(),
        ]);
    }

    private function syncBatchCountersAndStatus(ProductDeleteBatch $product_delete_batch): void
    {
        $items_query = ProductDeleteItem::query()
            ->where('product_delete_batch_id', (int) $product_delete_batch->id);

        $total_items = (int) $items_query->count();
        $failed_items = (int) (clone $items_query)
            ->where('status', ProductDeleteItemsStatusEnum::FAILED->value)
            ->count();

        $status = match (true) {
            $total_items === 0 => ProductDeleteBatchesStatusEnum::FAILED->value,
            $failed_items > 0  => ProductDeleteBatchesStatusEnum::PARTIAL_FAILED->value,
            default            => ProductDeleteBatchesStatusEnum::COMPLETED->value,
        };

        $product_delete_batch->update([
            'status'          => $status,
            'total_items'     => $total_items,
            'processed_items' => 0,
            'failed_items'    => $failed_items,
            'finished_at'     => now(),
            'options'         => [
                ...($product_delete_batch->options ?? []),
                'prepare_finished_at' => now()->toDateTimeString(),
            ],
        ]);
    }

    private function resolveExcelPath(string $source_path): string
    {
        $clean_source_path = Str::trim($source_path);

        if ($clean_source_path === '') {
            throw new RuntimeException('Empty source path for excel delete batch');
        }

        if (is_file($clean_source_path)) {
            return $clean_source_path;
        }

        if (Storage::exists($clean_source_path)) {
            $storage_resolved = Storage::path($clean_source_path);
            if (is_file($storage_resolved)) {
                return $storage_resolved;
            }
        }

        throw new RuntimeException('Excel source file not found for delete batch: '.$clean_source_path);
    }

    private function extractSpreadsheetId(string $sheets_url): ?string
    {
        if (empty($matches = Str::match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $sheets_url))) {
            return null;
        }

        return $matches;
    }

    private function normalizeHeaderKey(string $header): string
    {
        return Str::snake(Str::squish(Str::replace(['-', '_'], ' ', Str::trim($header))));
    }

    private function normalizeLookupIdentifierValue(string $raw_value): string
    {
        $normalized_value = Str::trim($raw_value);

        if (preg_match('/^\s*[xх]\s*$/iu', $normalized_value) === 1) {
            return '';
        }

        return $normalized_value;
    }
}
