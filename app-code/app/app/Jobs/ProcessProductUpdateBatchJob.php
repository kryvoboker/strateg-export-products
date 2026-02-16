<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Seo\SeoUrl;
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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Revolution\Google\Sheets\Facades\Sheets;
use RuntimeException;
use Throwable;

class ProcessProductUpdateBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const array REQUIRED_HEADERS = [
        'Product' => [
            'External Product Id',
            'Model',
            'SKU',
            'EAN',
            'Quantity',
            'Minimum',
            'Image',
            'Price',
            'Is Active',
            'Date Available',
            'Date Added',
        ],
        'Description' => [
            'Name',
            'Description',
            'Meta Title',
            'Meta Description',
            'Meta Keywords',
        ],
        'Image' => [
            'Image',
            'Sort Order',
        ],
        'Product Category' => [
            'Category Name',
        ],
        'Product Attribute' => [
            'Attribute Name',
            'Attribute Text',
        ],
        'Seo Url' => [
            'Query Key',
            'Query Value',
            'Keyword',
            'Sort Order',
        ],
        'Special' => [
            'User Group Id',
            'Price',
            'Priority',
            'Date Start',
            'Date End',
        ],
        'Discount' => [
            'User Group Id',
            'Quantity',
            'Price',
            'Priority',
            'Date Start',
            'Date End',
        ],
    ];

    /**
     * @var list<string>
     */
    private const array UNIQUE_KEYS = ['external_product_id', 'model', 'ean'];

    /**
     * @var array<string, array{column:string,type:string,default:mixed,is_unique:bool}>
     */
    private const array PRODUCT_FIELD_MAP = [
        'model'          => ['column' => 'model', 'type' => 'string', 'default' => null, 'is_unique' => true],
        'sku'            => ['column' => 'sku', 'type' => 'string', 'default' => null, 'is_unique' => false],
        'ean'            => ['column' => 'ean', 'type' => 'string', 'default' => null, 'is_unique' => true],
        'quantity'       => ['column' => 'quantity', 'type' => 'int', 'default' => 0, 'is_unique' => false],
        'minimum'        => ['column' => 'minimum', 'type' => 'int', 'default' => 1, 'is_unique' => false],
        'image'          => ['column' => 'image', 'type' => 'string', 'default' => null, 'is_unique' => false],
        'price'          => ['column' => 'price', 'type' => 'float', 'default' => 0, 'is_unique' => false],
        'is_active'      => ['column' => 'is_active', 'type' => 'bool', 'default' => false, 'is_unique' => false],
        'date_available' => ['column' => 'date_available', 'type' => 'datetime', 'default' => null, 'is_unique' => false],
        'date_added'     => ['column' => 'date_added', 'type' => 'datetime', 'default' => null, 'is_unique' => false],
    ];

    public function __construct(public int $product_update_batch_id) {}

    public function handle(): void
    {
        $product_update_batch = ProductUpdateBatch::query()->find($this->product_update_batch_id);

        if (! $product_update_batch instanceof ProductUpdateBatch) {
            Log::channel('stack')->warning('Product update batch not found', [
                'product_update_batch_id' => $this->product_update_batch_id,
            ]);

            return;
        }

        $product_update_batch->update([
            'status'      => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            'started_at'  => get_now_date(),
            'finished_at' => null,
            'options'     => [
                ...($product_update_batch->options ?? []),
                'prepare_started_at'  => get_now_date()->toDateTimeString(),
                'prepare_finished_at' => null,
                'last_error'          => null,
            ],
        ]);

        try {
            $normalized_document = $this->normalizeDocumentRows($product_update_batch);

            DB::transaction(function () use ($product_update_batch, $normalized_document): void {
                ProductUpdateItem::query()
                    ->where('product_update_batch_id', (int) $product_update_batch->id)
                    ->delete();

                $this->prepareUpdateItemsFromDocument((int) $product_update_batch->id, $normalized_document);
            });

            $this->syncBatchCountersAndStatus($product_update_batch);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to prepare product update batch', [
                'product_update_batch_id' => (int) $product_update_batch->id,
                'error_msg'               => $exception->getMessage(),
                'file'                    => $exception->getFile(),
                'line'                    => $exception->getLine(),
                'exception'               => $exception,
            ]);

            $product_update_batch->update([
                'status'      => ProductUpdateBatchesStatusEnum::FAILED->value,
                'finished_at' => get_now_date(),
                'options'     => [
                    ...($product_update_batch->options ?? []),
                    'prepare_finished_at' => get_now_date()->toDateTimeString(),
                    'last_error'          => Str::limit(Str::trim($exception->getMessage()), 10000),
                ],
            ]);
        }
    }

    /**
     * @return array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>
     */
    private function normalizeDocumentRows(ProductUpdateBatch $product_update_batch): array
    {
        return match ((string) $product_update_batch->source_type) {
            ProductUpdateBatchesSourceTypeEnum::EXCEL_FILE->value => $this->normalizeExcelDocumentRows((string) $product_update_batch->source_path),
            ProductUpdateBatchesSourceTypeEnum::API->value        => $this->normalizeGoogleDocumentRows($product_update_batch),
            default                                               => throw new RuntimeException('Unsupported source_type for update batch: '.$product_update_batch->source_type),
        };
    }

    /**
     * @return array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>
     */
    private function normalizeExcelDocumentRows(string $source_path): array
    {
        $resolved_path = $this->resolveExcelPath($source_path);

        $spreadsheet         = IOFactory::load($resolved_path);
        $normalized_document = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheet_name        = (string) $worksheet->getTitle();
            $highest_row       = (int) $worksheet->getHighestDataRow();
            $highest_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());

            if ($highest_row < 1 || $highest_col_index < 1) {
                continue;
            }

            $headers       = [];
            $header_lookup = [];

            for ($col_index = 1; $col_index <= $highest_col_index; $col_index++) {
                $header_value = Str::trim((string) $worksheet->getCell([$col_index, 1])->getValue());
                if ($header_value === '') {
                    continue;
                }

                $normalized_header = $this->normalizeHeaderKey($header_value);
                if ($normalized_header === '') {
                    continue;
                }

                $headers[$normalized_header]       = $col_index;
                $header_lookup[$normalized_header] = $header_value;
            }

            $rows = [];
            for ($row_index = 2; $row_index <= $highest_row; $row_index++) {
                $row_data      = [];
                $has_any_value = false;

                foreach ($headers as $normalized_header => $col_index) {
                    $raw_value                    = (string) $worksheet->getCell([$col_index, $row_index])->getCalculatedValue();
                    $clean_value                  = Str::trim($raw_value);
                    $row_data[$normalized_header] = $clean_value;
                    if ($clean_value !== '') {
                        $has_any_value = true;
                    }
                }

                if ($sheet_name !== 'Product' || $has_any_value) {
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
    private function normalizeGoogleDocumentRows(ProductUpdateBatch $product_update_batch): array
    {
        $options        = is_array($product_update_batch->options) ? $product_update_batch->options : [];
        $spreadsheet_id = Str::trim((string) Arr::get($options, 'spreadsheet_id', ''));

        if ($spreadsheet_id === '') {
            $google_sheet_url = Str::trim((string) Arr::get($options, 'google_sheet_url', ''));
            if ($google_sheet_url !== '') {
                $spreadsheet_id = $this->extractSpreadsheetId($google_sheet_url) ?? '';
            }
        }

        if ($spreadsheet_id === '') {
            throw new RuntimeException('Missing spreadsheet id for google sheets update batch');
        }

        $sheet_names = array_values(Sheets::spreadsheet($spreadsheet_id)->sheetList());

        $normalized_document = [];

        foreach ($sheet_names as $sheet_name) {
            $sheet_rows = Sheets::spreadsheet($spreadsheet_id)
                ->sheet($sheet_name)
                ->all();

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
                } elseif ((string) $sheet_name !== 'Product') {
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

    private function resolveExcelPath(string $source_path): string
    {
        $clean_source_path = Str::trim($source_path);

        if ($clean_source_path === '') {
            throw new RuntimeException('Empty source path for excel update batch');
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

        throw new RuntimeException('Excel source file not found for update batch: '.$clean_source_path);
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
        return Str::lower(Str::squish(Str::replace(['_', '-'], ' ', Str::trim($header))));
    }

    /**
     * @param  array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>  $normalized_document
     */
    private function prepareUpdateItemsFromDocument(int $product_update_batch_id, array $normalized_document): void
    {
        $product_sheet = $normalized_document['Product'] ?? null;

        if (! is_array($product_sheet)) {
            throw new RuntimeException('Sheet "Product" is required to identify products for update');
        }

        $product_rows = Arr::get($product_sheet, 'rows', []);
        if (! is_array($product_rows) || $product_rows === []) {
            throw new RuntimeException('Sheet "Product" has no data rows for update');
        }

        foreach ($product_rows as $row_number => $product_row) {
            try {
                $unique_values = $this->extractUniqueValuesFromProductRow($product_row);

                if ($this->isAllUniqueValuesEmpty($unique_values)) {
                    $this->createFailedUpdateItem(
                        $product_update_batch_id,
                        (int) $row_number,
                        $product_row,
                        'Missing unique values in Product row. Required at least one: external_product_id, model, ean.'
                    );

                    Log::channel('stack')->warning('Product update row skipped because unique values are empty', [
                        'product_update_batch_id' => $product_update_batch_id,
                        'row_number'              => (int) $row_number,
                    ]);

                    continue;
                }

                [$resolved_product_id, $resolved_shop_id, $resolve_error_message] = $this->resolveProductByUniqueValues($unique_values);

                if ($resolved_product_id <= 0) {
                    $this->createFailedUpdateItem(
                        $product_update_batch_id,
                        (int) $row_number,
                        $product_row,
                        $resolve_error_message ?: 'Product was not found by unique values'
                    );

                    Log::channel('stack')->warning('Product update row skipped because product was not resolved', [
                        'product_update_batch_id' => $product_update_batch_id,
                        'row_number'              => (int) $row_number,
                        'unique_values'           => $unique_values,
                        'error_message'           => $resolve_error_message,
                    ]);

                    continue;
                }

                $this->backupLocalProductSnapshot($resolved_product_id, $resolved_shop_id);

                $row_instructions = $this->buildRowInstructions((int) $row_number, $normalized_document);

                $this->applyLocalProductUpdates($resolved_product_id, Arr::get($row_instructions, 'Product.fields', []));

                ProductUpdateItem::query()->create([
                    'product_update_batch_id' => $product_update_batch_id,
                    'product_id'              => $resolved_product_id,
                    'payload'                 => [
                        'operation'   => 'prepare_update',
                        'source_meta' => [
                            'row_number' => (int) $row_number,
                        ],
                        'resolved_shop_id'    => $resolved_shop_id > 0 ? $resolved_shop_id : null,
                        'unique_values'       => $unique_values,
                        'update_instructions' => $row_instructions,
                    ],
                    'status'        => ProductUpdateItemsStatusEnum::SUCCESSED->value,
                    'error_message' => null,
                    'processed_at'  => get_now_date(),
                ]);
            } catch (Throwable $exception) {
                $this->createFailedUpdateItem(
                    $product_update_batch_id,
                    (int) $row_number,
                    $product_row,
                    $exception->getMessage()
                );

                Log::channel('stack')->error('Product update row processing failed', [
                    'product_update_batch_id' => $product_update_batch_id,
                    'row_number'              => (int) $row_number,
                    'error_msg'               => $exception->getMessage(),
                    'file'                    => $exception->getFile(),
                    'line'                    => $exception->getLine(),
                ]);
            }
        }
    }

    /**
     * @param  array<string,string>  $product_row
     * @return array{external_product_id:string,model:string,ean:string}
     */
    private function extractUniqueValuesFromProductRow(array $product_row): array
    {
        $external_product_id = Str::trim((string) ($product_row[$this->normalizeHeaderKey('External Product Id')] ?? ''));
        $model               = Str::trim((string) ($product_row[$this->normalizeHeaderKey('Model')] ?? ''));
        $ean                 = Str::trim((string) ($product_row[$this->normalizeHeaderKey('EAN')] ?? ''));

        return [
            'external_product_id' => $external_product_id,
            'model'               => $model,
            'ean'                 => $ean,
        ];
    }

    /**
     * @param  array{external_product_id:string,model:string,ean:string}  $unique_values
     * @return array{0:int,1:int,2:string}
     */
    private function resolveProductByUniqueValues(array $unique_values): array
    {
        $external_product_id = Str::trim((string) ($unique_values['external_product_id'] ?? ''));
        if ($external_product_id !== '') {
            $product_shops = ProductShop::query()
                ->where('external_product_id', $external_product_id)
                ->orderByDesc('id')
                ->get();

            $resolved_product_ids = $product_shops
                ->pluck('product_id')
                ->map(static fn ($product_id): int => (int) $product_id)
                ->filter(static fn (int $product_id): bool => $product_id > 0)
                ->unique()
                ->values()
                ->all();

            if (count($resolved_product_ids) > 1) {
                return [0, 0, 'Multiple products found by external_product_id'];
            }

            if (count($resolved_product_ids) === 1) {
                $resolved_shop_id = (int) ($product_shops->firstWhere('product_id', $resolved_product_ids[0])->shop_id ?? 0);

                return [$resolved_product_ids[0], $resolved_shop_id, ''];
            }
        }

        $model = Str::trim((string) ($unique_values['model'] ?? ''));
        if ($model !== '') {
            $product_ids_by_model = Product::query()
                ->where('model', $model)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if (count($product_ids_by_model) > 1) {
                return [0, 0, 'Multiple products found by model'];
            }

            if (count($product_ids_by_model) === 1) {
                $resolved_shop_id = (int) (ProductShop::query()
                    ->where('product_id', $product_ids_by_model[0])
                    ->orderByDesc('id')
                    ->value('shop_id') ?? 0);

                return [$product_ids_by_model[0], $resolved_shop_id, ''];
            }
        }

        $ean = Str::trim((string) ($unique_values['ean'] ?? ''));
        if ($ean !== '') {
            $product_ids_by_ean = Product::query()
                ->where('ean', $ean)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if (count($product_ids_by_ean) > 1) {
                return [0, 0, 'Multiple products found by ean'];
            }

            if (count($product_ids_by_ean) === 1) {
                $resolved_shop_id = (int) (ProductShop::query()
                    ->where('product_id', $product_ids_by_ean[0])
                    ->orderByDesc('id')
                    ->value('shop_id') ?? 0);

                return [$product_ids_by_ean[0], $resolved_shop_id, ''];
            }
        }

        return [0, 0, 'Product not found by unique values'];
    }

    /**
     * @param  array{external_product_id:string,model:string,ean:string}  $unique_values
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
     * @param  array<string, array{headers:array<string,int>,rows:array<int,array<string,string>>}>  $normalized_document
     * @return array<string, array{fields:array<string,array{action:string,value:mixed}>}>
     */
    private function buildRowInstructions(int $row_number, array $normalized_document): array
    {
        $instructions = [];

        foreach (self::REQUIRED_HEADERS as $sheet_name => $required_headers) {
            $sheet = $normalized_document[$sheet_name] ?? null;
            if (! is_array($sheet)) {
                continue;
            }

            $sheet_headers = Arr::get($sheet, 'headers', []);
            $sheet_row     = Arr::get($sheet, 'rows.'.$row_number, []);

            if (! is_array($sheet_headers) || ! is_array($sheet_row)) {
                continue;
            }

            $sheet_field_instructions = [];

            foreach ($required_headers as $header_name) {
                $normalized_header = $this->normalizeHeaderKey($header_name);

                if (! array_key_exists($normalized_header, $sheet_headers)) {
                    continue;
                }

                $raw_value = Str::trim((string) ($sheet_row[$normalized_header] ?? ''));
                $is_unique = in_array($normalized_header, [
                    $this->normalizeHeaderKey('External Product Id'),
                    $this->normalizeHeaderKey('Model'),
                    $this->normalizeHeaderKey('EAN'),
                ], true);

                $sheet_field_instructions[$normalized_header] = $this->buildFieldInstruction($raw_value, $is_unique);
            }

            if ($sheet_field_instructions !== []) {
                $instructions[$sheet_name] = [
                    'fields' => $sheet_field_instructions,
                ];
            }
        }

        return $instructions;
    }

    /**
     * @return array{action:string,value:mixed}
     */
    private function buildFieldInstruction(string $raw_value, bool $is_unique): array
    {
        if (preg_match('/^\s*[xх]\s*$/iu', $raw_value) === 1) {
            return [
                'action' => 'no_change',
                'value'  => null,
            ];
        }

        if ($raw_value === '') {
            if ($is_unique) {
                return [
                    'action' => 'skip',
                    'value'  => null,
                ];
            }

            return [
                'action' => 'delete',
                'value'  => null,
            ];
        }

        return [
            'action' => 'set',
            'value'  => $raw_value,
        ];
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $product_fields
     */
    private function applyLocalProductUpdates(int $product_id, array $product_fields): void
    {
        if ($product_id <= 0 || $product_fields === []) {
            return;
        }

        $updates = [];

        foreach (self::PRODUCT_FIELD_MAP as $normalized_header => $field_config) {
            if (! array_key_exists($normalized_header, $product_fields)) {
                continue;
            }

            $instruction = $product_fields[$normalized_header];
            $action      = (string) ($instruction['action'] ?? 'skip');

            if ($action === 'skip' || $action === 'no_change') {
                continue;
            }

            $column = (string) $field_config['column'];
            if ($action === 'delete') {
                $updates[$column] = $field_config['default'];

                continue;
            }

            if ($action === 'set') {
                $updates[$column] = $this->normalizeValueByType(
                    (string) ($field_config['type'] ?? 'string'),
                    $instruction['value']
                );
            }
        }

        if ($updates === []) {
            return;
        }

        Product::query()
            ->whereKey($product_id)
            ->update($updates);
    }

    private function normalizeValueByType(string $type, mixed $value): mixed
    {
        $raw_value = Str::trim((string) $value);

        return match ($type) {
            'int'      => is_numeric($raw_value) ? (int) $raw_value : 0,
            'float'    => is_numeric($raw_value) ? (float) $raw_value : 0,
            'bool'     => $this->normalizeBooleanValue($raw_value),
            'datetime' => $raw_value !== '' ? $raw_value : null,
            default    => $raw_value !== '' ? $raw_value : null,
        };
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        $normalized = Str::lower(Str::trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'так', 't'], true);
    }

    /**
     * @param  array<string, mixed>  $source_row
     */
    private function createFailedUpdateItem(
        int $product_update_batch_id,
        int $row_number,
        array $source_row,
        string $error_message
    ): void {
        ProductUpdateItem::query()->create([
            'product_update_batch_id' => $product_update_batch_id,
            'product_id'              => null,
            'payload'                 => [
                'operation'   => 'prepare_update',
                'source_meta' => [
                    'row_number' => $row_number,
                ],
                'source_row' => $source_row,
            ],
            'status'        => ProductUpdateItemsStatusEnum::FAILED->value,
            'error_message' => Str::limit(Str::trim($error_message), 10000),
            'processed_at'  => get_now_date(),
        ]);
    }

    private function backupLocalProductSnapshot(int $product_id, int $resolved_shop_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        $product = Product::query()->find($product_id);
        if (! $product instanceof Product) {
            return;
        }

        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
            'productShops',
        ]);

        $single_shop_id = $resolved_shop_id > 0
            ? $resolved_shop_id
            : (int) ($product->productShops->first()?->shop_id ?? 0);

        $seo_urls = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->orderBy('id')
            ->get()
            ->map(static fn (SeoUrl $seo_url): array => [
                'id'               => (int) $seo_url->id,
                'shop_language_id' => $seo_url->shop_language_id !== null ? (int) $seo_url->shop_language_id : null,
                'query_key'        => $seo_url->query_key,
                'query_value'      => $seo_url->query_value,
                'keyword'          => $seo_url->keyword,
                'sort_order'       => (int) ($seo_url->sort_order ?? 0),
            ])
            ->values()
            ->all();

        $snapshot_payload = [
            'captured_at' => get_now_date()->toDateTimeString(),
            'product'     => [
                'id'                     => (int) $product->id,
                'product_import_item_id' => $product->product_import_item_id !== null ? (int) $product->product_import_item_id : null,
                'marked_to_shop'         => $product->marked_to_shop,
                'model'                  => $product->model,
                'sku'                    => $product->sku,
                'ean'                    => $product->ean,
                'quantity'               => (int) ($product->quantity ?? 0),
                'minimum'                => (int) ($product->minimum ?? 1),
                'image'                  => $product->image,
                'price'                  => (string) ($product->price ?? '0'),
                'is_active'              => (bool) $product->is_active,
                'date_available'         => $product->date_available?->toDateTimeString(),
                'date_added'             => $product->date_added?->toDateTimeString(),
            ],
            'descriptions' => $product->descriptions
                ->map(static fn ($description): array => [
                    'id'               => (int) $description->id,
                    'shop_language_id' => $description->shop_language_id !== null ? (int) $description->shop_language_id : null,
                    'name'             => $description->name,
                    'description'      => $description->description,
                    'meta_title'       => $description->meta_title,
                    'meta_description' => $description->meta_description,
                    'meta_keywords'    => $description->meta_keywords,
                ])->values()->all(),
            'images' => $product->images
                ->map(static fn ($image): array => [
                    'id'         => (int) $image->id,
                    'image'      => $image->image,
                    'sort_order' => (int) ($image->sort_order ?? 0),
                ])->values()->all(),
            'categories' => $product->categories
                ->map(static fn ($category): array => [
                    'id'           => (int) $category->id,
                    'parent_id'    => $category->parent_id !== null ? (int) $category->parent_id : null,
                    'sort_order'   => (int) ($category->sort_order ?? 0),
                    'is_active'    => (bool) $category->is_active,
                    'descriptions' => $category->descriptions
                        ->map(static fn ($description): array => [
                            'id'               => (int) $description->id,
                            'shop_language_id' => $description->shop_language_id !== null ? (int) $description->shop_language_id : null,
                            'name'             => $description->name,
                            'description'      => $description->description,
                            'h1_title'         => $description->h1_title,
                            'meta_title'       => $description->meta_title,
                            'meta_description' => $description->meta_description,
                            'meta_keywords'    => $description->meta_keywords,
                        ])->values()->all(),
                ])->values()->all(),
            'attributes' => $product->productToAttributes
                ->map(static fn ($attribute): array => [
                    'id'               => (int) $attribute->id,
                    'attribute_id'     => $attribute->attribute_id !== null ? (int) $attribute->attribute_id : null,
                    'shop_language_id' => $attribute->shop_language_id !== null ? (int) $attribute->shop_language_id : null,
                    'text'             => $attribute->text,
                ])->values()->all(),
            'seo_urls' => $seo_urls,
            'specials' => $product->specials
                ->map(static fn ($special): array => [
                    'id'            => (int) $special->id,
                    'user_group_id' => $special->user_group_id !== null ? (int) $special->user_group_id : null,
                    'price'         => (string) ($special->price ?? '0'),
                    'priority'      => (int) ($special->priority ?? 0),
                    'date_start'    => $special->date_start,
                    'date_end'      => $special->date_end,
                ])->values()->all(),
            'discounts' => $product->discounts
                ->map(static fn ($discount): array => [
                    'id'            => (int) $discount->id,
                    'user_group_id' => $discount->user_group_id !== null ? (int) $discount->user_group_id : null,
                    'quantity'      => (int) ($discount->quantity ?? 0),
                    'price'         => (string) ($discount->price ?? '0'),
                    'priority'      => (int) ($discount->priority ?? 0),
                    'date_start'    => $discount->date_start,
                    'date_end'      => $discount->date_end,
                ])->values()->all(),
            'product_shops' => $single_shop_id > 0 ? $single_shop_id : null,
        ];

        ProductBackups::createOrUpdateInternalProductBackup($product_id, $snapshot_payload);
    }

    private function syncBatchCountersAndStatus(ProductUpdateBatch $product_update_batch): void
    {
        $items_query = ProductUpdateItem::query()
            ->where('product_update_batch_id', (int) $product_update_batch->id);

        $total_items  = (int) (clone $items_query)->count();
        $failed_items = (int) (clone $items_query)
            ->where('status', ProductUpdateItemsStatusEnum::FAILED->value)
            ->count();
        $processing_items = (int) (clone $items_query)
            ->where('status', ProductUpdateItemsStatusEnum::PROCESSING->value)
            ->count();
        $new_items = (int) (clone $items_query)
            ->where('status', ProductUpdateItemsStatusEnum::NEW->value)
            ->count();

        $processed_items = max($total_items - $new_items - $processing_items, 0);

        $batch_status = match (true) {
            $total_items <= 0                                 => ProductUpdateBatchesStatusEnum::FAILED->value,
            $processing_items > 0 || $new_items > 0           => ProductUpdateBatchesStatusEnum::PROCESSING->value,
            $failed_items > 0 && $failed_items < $total_items => ProductUpdateBatchesStatusEnum::PARTIAL_FAILED->value,
            $failed_items >= $total_items                     => ProductUpdateBatchesStatusEnum::FAILED->value,
            default                                           => ProductUpdateBatchesStatusEnum::COMPLETED->value,
        };

        $is_finished = $batch_status !== ProductUpdateBatchesStatusEnum::PROCESSING->value;

        $product_update_batch->update([
            'status'          => $batch_status,
            'total_items'     => $total_items,
            'processed_items' => $processed_items,
            'failed_items'    => $failed_items,
            'finished_at'     => $is_finished ? get_now_date() : null,
            'options'         => [
                ...($product_update_batch->options ?? []),
                'prepare_finished_at'      => get_now_date()->toDateTimeString(),
                'prepared_items_total'     => $total_items,
                'prepared_items_processed' => $processed_items,
                'prepared_items_failed'    => $failed_items,
                'last_error'               => null,
            ],
        ]);
    }
}
