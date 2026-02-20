<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Update\ProductUpdateBatchesSourceTypeEnum;
use App\Enums\Product\Update\ProductUpdateBatchesStatusEnum;
use App\Enums\Product\Update\ProductUpdateItemsStatusEnum;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Brands\BrandShop;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Manufacturers\ManufacturerShop;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Products\Updates\ProductBackups;
use App\Models\Products\Updates\ProductUpdateBatch;
use App\Models\Products\Updates\ProductUpdateItem;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;
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
            'Product Id',
            'Shop Id',
            'External Product Id',
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
    private const array UNIQUE_KEYS = ['product_id', 'external_product_id', 'model', 'ean'];

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

    /**
     * @var array<string, int>
     */
    private array $manufacturer_id_cache_by_name = [];

    /**
     * @var array<string, int>
     */
    private array $brand_id_cache_by_name = [];

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
            'started_at'  => now(),
            'finished_at' => null,
            'options'     => [
                ...($product_update_batch->options ?? []),
                'prepare_started_at'  => now()->toDateTimeString(),
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
                'finished_at' => now(),
                'options'     => [
                    ...($product_update_batch->options ?? []),
                    'prepare_finished_at' => now()->toDateTimeString(),
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
            ProductUpdateBatchesSourceTypeEnum::EXCEL_FILE->value   => $this->normalizeExcelDocumentRows((string) $product_update_batch->source_path),
            ProductUpdateBatchesSourceTypeEnum::GOOGLE_SHEET->value => $this->normalizeGoogleDocumentRows($product_update_batch),
            default                                                 => throw new RuntimeException('Unsupported source_type for update batch: '.$product_update_batch->source_type),
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
        return Str::snake(Str::squish(Str::replace(['-', '_'], ' ', Str::trim($header))));
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
                        'Missing unique values in Product row. Required at least one: product_id, external_product_id, model, ean.'
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
                $this->applyLocalProductDescriptionUpdates($resolved_product_id, Arr::get($row_instructions, 'Description.fields', []));
                $this->applyLocalImageUpdates($resolved_product_id, Arr::get($row_instructions, 'Image.fields', []));
                $this->applyLocalCategoryUpdates(
                    $resolved_product_id,
                    $resolved_shop_id,
                    Arr::get($row_instructions, 'Product Category.fields', [])
                );
                $this->applyLocalAttributeUpdates(
                    $resolved_product_id,
                    $resolved_shop_id,
                    Arr::get($row_instructions, 'Product Attribute.fields', [])
                );
                $this->applyLocalManufacturerBrandUpdates(
                    $resolved_product_id,
                    $resolved_shop_id,
                    Arr::get($row_instructions, 'Product.fields', [])
                );
                $this->applyLocalSeoUrlUpdates(
                    $resolved_product_id,
                    $resolved_shop_id,
                    Arr::get($row_instructions, 'Seo Url.fields', [])
                );
                $this->applyLocalSpecialUpdates($resolved_product_id, Arr::get($row_instructions, 'Special.fields', []));
                $this->applyLocalDiscountUpdates($resolved_product_id, Arr::get($row_instructions, 'Discount.fields', []));

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
                    'processed_at'  => now(),
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

            if ($product_exists === true) {
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

                $raw_value                                    = Str::trim((string) ($sheet_row[$normalized_header] ?? ''));
                $sheet_field_instructions[$normalized_header] = $this->buildFieldInstruction($raw_value);
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
    private function buildFieldInstruction(string $raw_value): array
    {
        if (preg_match('/^\s*[xх]\s*$/iu', $raw_value) === 1) {
            return [
                'action' => 'delete',
                'value'  => null,
            ];
        }

        if ($raw_value === '') {
            return [
                'action' => 'no_change',
                'value'  => null,
            ];
        }

        return [
            'action' => 'set',
            'value'  => $raw_value,
        ];
    }

    private function normalizeLookupIdentifierValue(string $raw_value): string
    {
        $normalized_value = Str::trim($raw_value);

        if (preg_match('/^\s*[xх]\s*$/iu', $normalized_value) === 1) {
            return '';
        }

        return $normalized_value;
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
            $instruction = $this->resolveSheetFieldInstruction($product_fields, $normalized_header);

            if (! is_array($instruction)) {
                continue;
            }
            $action = (string) ($instruction['action'] ?? 'skip');

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
                    (string) $field_config['type'],
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

    /**
     * @param  array<string, mixed>  $sheet_fields
     * @return array{action:string,value:mixed}|null
     */
    private function resolveSheetFieldInstruction(array $sheet_fields, string $field_key): ?array
    {
        $normalized_field_key = $this->normalizeHeaderKey($field_key);
        $legacy_space_key     = Str::replace('_', ' ', $normalized_field_key);

        $instruction = $sheet_fields[$normalized_field_key]
            ?? $sheet_fields[$legacy_space_key]
            ?? $sheet_fields[$field_key]
            ?? null;

        return is_array($instruction) ? $instruction : null;
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $description_fields
     */
    private function applyLocalProductDescriptionUpdates(int $product_id, array $description_fields): void
    {
        if ($product_id <= 0 || $description_fields === []) {
            return;
        }

        $column_map = [
            $this->normalizeHeaderKey('Name')             => 'name',
            $this->normalizeHeaderKey('Description')      => 'description',
            $this->normalizeHeaderKey('Meta Title')       => 'meta_title',
            $this->normalizeHeaderKey('Meta Description') => 'meta_description',
            $this->normalizeHeaderKey('Meta Keywords')    => 'meta_keywords',
        ];

        $updates = [];

        foreach ($column_map as $normalized_header => $column) {
            if (! array_key_exists($normalized_header, $description_fields)) {
                continue;
            }

            $instruction = $description_fields[$normalized_header];
            $action      = (string) $instruction['action'];

            if ($action === 'skip' || $action === 'no_change') {
                continue;
            }

            if ($action === 'delete') {
                $updates[$column] = null;

                continue;
            }

            if ($action === 'set') {
                $updates[$column] = Str::trim((string) ($instruction['value'] ?? ''));
            }
        }

        if ($updates === []) {
            return;
        }

        $updated_rows = ProductDescription::query()
            ->where('product_id', $product_id)
            ->update($updates);

        if ($updated_rows > 0) {
            return;
        }

        ProductDescription::query()->create([
            'product_id'       => $product_id,
            'shop_language_id' => null,
            'name'             => array_key_exists('name', $updates) ? $updates['name'] : null,
            'description'      => array_key_exists('description', $updates) ? $updates['description'] : null,
            'meta_title'       => array_key_exists('meta_title', $updates) ? $updates['meta_title'] : null,
            'meta_description' => array_key_exists('meta_description', $updates) ? $updates['meta_description'] : null,
            'meta_keywords'    => array_key_exists('meta_keywords', $updates) ? $updates['meta_keywords'] : null,
        ]);
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $image_fields
     */
    private function applyLocalImageUpdates(int $product_id, array $image_fields): void
    {
        if ($product_id <= 0 || $image_fields === []) {
            return;
        }

        $image_instruction = $image_fields[$this->normalizeHeaderKey('Image')] ?? ['action' => 'skip', 'value' => null];
        $sort_instruction  = $image_fields[$this->normalizeHeaderKey('Sort Order')] ?? ['action' => 'skip', 'value' => null];

        $image_action = (string) ($image_instruction['action'] ?? 'skip');
        $sort_action  = (string) ($sort_instruction['action'] ?? 'skip');

        if (in_array($image_action, ['skip', 'no_change'], true) && in_array($sort_action, ['skip', 'no_change'], true)) {
            return;
        }

        $existing_image = ProductImage::query()
            ->where('product_id', $product_id)
            ->orderBy('id')
            ->first();

        if ($image_action === 'delete') {
            if ($existing_image instanceof ProductImage) {
                $existing_image->delete();
            }

            return;
        }

        $updates = [];

        if ($image_action === 'set') {
            $updates['image'] = Str::trim((string) ($image_instruction['value'] ?? ''));
        }

        if ($sort_action === 'set') {
            $updates['sort_order'] = is_numeric((string) ($sort_instruction['value'] ?? null))
                ? (int) $sort_instruction['value']
                : 0;
        } elseif ($sort_action === 'delete') {
            $updates['sort_order'] = 0;
        }

        if ($updates === []) {
            return;
        }

        if ($existing_image instanceof ProductImage) {
            $existing_image->update($updates);

            return;
        }

        ProductImage::query()->create([
            'product_id' => $product_id,
            'image'      => (string) ($updates['image'] ?? ''),
            'sort_order' => (int) ($updates['sort_order'] ?? 0),
        ]);
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $category_fields
     */
    private function applyLocalCategoryUpdates(int $product_id, int $resolved_shop_id, array $category_fields): void
    {
        if ($product_id <= 0 || $category_fields === []) {
            return;
        }

        $category_name_instruction = $category_fields[$this->normalizeHeaderKey('Category Name')] ?? ['action' => 'skip', 'value' => null];
        $category_name_action      = (string) ($category_name_instruction['action'] ?? 'skip');

        if (in_array($category_name_action, ['skip', 'no_change'], true)) {
            return;
        }

        if ($category_name_action === 'delete') {
            CategoryProduct::query()->where('product_id', $product_id)->delete();

            return;
        }

        if ($category_name_action !== 'set') {
            return;
        }

        $raw_category_path = Str::trim((string) ($category_name_instruction['value'] ?? ''));
        if ($raw_category_path === '') {
            return;
        }

        $category_segments = array_values(array_filter(
            preg_split('/\s*>\s*/u', $raw_category_path) ?: [],
            static fn ($segment): bool => Str::trim((string) $segment) !== ''
        ));

        if ($category_segments === []) {
            return;
        }

        $default_shop_language_id = $this->resolveDefaultShopLanguageId($resolved_shop_id);
        $category_id              = $this->resolveOrCreateCategoryIdByPath($category_segments, $default_shop_language_id);

        if ($category_id <= 0) {
            return;
        }

        CategoryProduct::query()->firstOrCreate([
            'product_id'  => $product_id,
            'category_id' => $category_id,
        ]);
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $attribute_fields
     */
    private function applyLocalAttributeUpdates(int $product_id, int $resolved_shop_id, array $attribute_fields): void
    {
        if ($product_id <= 0 || $attribute_fields === []) {
            return;
        }

        $attribute_name_instruction = $attribute_fields[$this->normalizeHeaderKey('Attribute Name')] ?? ['action' => 'skip', 'value' => null];
        $attribute_text_instruction = $attribute_fields[$this->normalizeHeaderKey('Attribute Text')] ?? ['action' => 'skip', 'value' => null];

        $attribute_name_action = (string) ($attribute_name_instruction['action'] ?? 'skip');
        $attribute_text_action = (string) ($attribute_text_instruction['action'] ?? 'skip');

        if (in_array($attribute_name_action, ['skip', 'no_change'], true) && in_array($attribute_text_action, ['skip', 'no_change'], true)) {
            return;
        }

        $default_shop_language_id = $this->resolveDefaultShopLanguageId($resolved_shop_id);

        if ($attribute_name_action === 'delete') {
            ProductToAttribute::query()
                ->where('product_id', $product_id)
                ->where(function ($query) use ($default_shop_language_id): void {
                    if ($default_shop_language_id > 0) {
                        $query
                            ->where('shop_language_id', $default_shop_language_id)
                            ->orWhereNull('shop_language_id');

                        return;
                    }

                    $query->whereNull('shop_language_id');
                })
                ->delete();

            return;
        }

        if ($attribute_name_action !== 'set') {
            return;
        }

        $attribute_name_parts = array_values(array_filter(
            preg_split('/\s*\|\s*/u', Str::trim((string) ($attribute_name_instruction['value'] ?? ''))) ?: [],
            static fn ($part): bool => Str::trim((string) $part) !== ''
        ));

        if ($attribute_name_parts === []) {
            return;
        }

        $attribute_text_parts = array_values(array_filter(
            preg_split('/\s*\|\s*/u', Str::trim((string) ($attribute_text_instruction['value'] ?? ''))) ?: [],
            static fn ($part): bool => Str::trim((string) $part) !== ''
        ));

        foreach ($attribute_name_parts as $part_index => $attribute_name_part) {
            $attribute_name_part = Str::trim((string) $attribute_name_part);
            if ($attribute_name_part === '') {
                continue;
            }

            $attribute_id = $this->resolveOrCreateAttributeIdByName($attribute_name_part, $default_shop_language_id);
            if ($attribute_id <= 0) {
                continue;
            }

            if ($attribute_text_action === 'delete') {
                ProductToAttribute::query()
                    ->where('product_id', $product_id)
                    ->where('attribute_id', $attribute_id)
                    ->where('shop_language_id', $default_shop_language_id > 0 ? $default_shop_language_id : null)
                    ->delete();

                continue;
            }

            $attribute_text = '';
            if ($attribute_text_action === 'set') {
                $attribute_text = Str::trim((string) ($attribute_text_parts[$part_index] ?? $attribute_text_parts[0] ?? ''));
            }

            ProductToAttribute::query()->updateOrCreate(
                [
                    'product_id'       => $product_id,
                    'attribute_id'     => $attribute_id,
                    'shop_language_id' => $default_shop_language_id > 0 ? $default_shop_language_id : null,
                ],
                [
                    'text' => $attribute_text_action === 'set' ? $attribute_text : '',
                ]
            );
        }
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $product_fields
     */
    private function applyLocalManufacturerBrandUpdates(int $product_id, int $resolved_shop_id, array $product_fields): void
    {
        if ($product_id <= 0 || $product_fields === []) {
            return;
        }

        $manufacturer_instruction = $this->resolveSheetFieldInstruction(
            $product_fields,
            $this->normalizeHeaderKey('Manufacturer')
        );
        $brand_instruction = $this->resolveSheetFieldInstruction(
            $product_fields,
            $this->normalizeHeaderKey('Brand')
        );

        if (! is_array($manufacturer_instruction) && ! is_array($brand_instruction)) {
            return;
        }

        $default_shop_language_id = $this->resolveDefaultShopLanguageId($resolved_shop_id);

        $existing_binding = ProductToManufacturerBrand::query()
            ->where('product_id', $product_id)
            ->first();

        $manufacturer_id = (int) ($existing_binding?->manufacturer_id ?? 0);
        $brand_id        = (int) ($existing_binding?->brand_id ?? 0);

        if (is_array($manufacturer_instruction)) {
            $manufacturer_action = (string) ($manufacturer_instruction['action'] ?? 'skip');
            if ($manufacturer_action === 'delete') {
                $manufacturer_id = 0;
            } elseif ($manufacturer_action === 'set') {
                $manufacturer_name = Str::trim((string) ($manufacturer_instruction['value'] ?? ''));
                $manufacturer_id   = $manufacturer_name !== ''
                    ? $this->resolveOrCreateManufacturerIdByName($manufacturer_name, $default_shop_language_id)
                    : 0;
            }
        }

        if (is_array($brand_instruction)) {
            $brand_action = (string) ($brand_instruction['action'] ?? 'skip');
            if ($brand_action === 'delete') {
                $brand_id = 0;
            } elseif ($brand_action === 'set') {
                $brand_name = Str::trim((string) ($brand_instruction['value'] ?? ''));
                $brand_id   = $brand_name !== ''
                    ? $this->resolveOrCreateBrandIdByName($brand_name, $default_shop_language_id)
                    : 0;
            }
        }

        if ($manufacturer_id <= 0 && $brand_id <= 0) {
            ProductToManufacturerBrand::query()
                ->where('product_id', $product_id)
                ->delete();

            return;
        }

        ProductToManufacturerBrand::query()->updateOrCreate(
            [
                'product_id' => $product_id,
            ],
            [
                'manufacturer_id' => $manufacturer_id > 0 ? $manufacturer_id : null,
                'brand_id'        => $brand_id > 0 ? $brand_id : null,
            ]
        );

        if ($resolved_shop_id > 0 && $manufacturer_id > 0) {
            ManufacturerShop::query()->firstOrCreate(
                [
                    'manufacturer_id' => $manufacturer_id,
                    'shop_id'         => $resolved_shop_id,
                ],
                [
                    'external_manufacturer_id' => null,
                ]
            );
        }

        if ($resolved_shop_id > 0 && $brand_id > 0) {
            BrandShop::query()->firstOrCreate(
                [
                    'brand_id' => $brand_id,
                    'shop_id'  => $resolved_shop_id,
                ],
                [
                    'external_brand_id' => null,
                ]
            );
        }
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $seo_fields
     */
    private function applyLocalSeoUrlUpdates(int $product_id, int $resolved_shop_id, array $seo_fields): void
    {
        if ($product_id <= 0 || $seo_fields === []) {
            return;
        }

        $query_value_instruction = $seo_fields[$this->normalizeHeaderKey('Query Value')] ?? ['action' => 'skip', 'value' => null];
        $keyword_instruction     = $seo_fields[$this->normalizeHeaderKey('Keyword')] ?? ['action' => 'skip', 'value' => null];
        $sort_instruction        = $seo_fields[$this->normalizeHeaderKey('Sort Order')] ?? ['action' => 'skip', 'value' => null];

        $query_value_action = (string) ($query_value_instruction['action'] ?? 'skip');
        $keyword_action     = (string) ($keyword_instruction['action'] ?? 'skip');
        $sort_action        = (string) ($sort_instruction['action'] ?? 'skip');

        if (
            in_array($query_value_action, ['skip', 'no_change'], true)
            && in_array($keyword_action, ['skip', 'no_change'], true)
            && in_array($sort_action, ['skip', 'no_change'], true)
        ) {
            return;
        }

        $default_shop_language_id = $this->resolveDefaultShopLanguageId($resolved_shop_id);

        $seo_query = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', $product_id)
            ->where('shop_language_id', $default_shop_language_id > 0 ? $default_shop_language_id : null);

        $query_value = '';
        if ($query_value_action === 'set') {
            $query_value = Str::trim((string) ($query_value_instruction['value'] ?? ''));

            if ($query_value !== '') {
                $seo_query->where('query_value', $query_value);
            }
        }

        if ($keyword_action === 'delete') {
            $seo_query->delete();

            return;
        }

        $updates = [];

        if ($query_value_action === 'set' && $query_value !== '') {
            $updates['query_value'] = $query_value;
        } elseif ($query_value_action === 'delete') {
            $updates['query_value'] = (string) $product_id;
        }

        if ($keyword_action === 'set') {
            $updates['keyword'] = Str::trim((string) ($keyword_instruction['value'] ?? ''));
        } elseif ($keyword_action === 'delete') {
            $updates['keyword'] = null;
        }

        if ($sort_action === 'set') {
            $updates['sort_order'] = is_numeric((string) ($sort_instruction['value'] ?? null))
                ? (int) $sort_instruction['value']
                : 0;
        } elseif ($sort_action === 'delete') {
            $updates['sort_order'] = 0;
        }

        if ($updates === []) {
            return;
        }

        $existing_seo = $seo_query->orderBy('id')->first();

        if ($existing_seo instanceof SeoUrl) {
            $existing_seo->update($updates);

            return;
        }

        SeoUrl::query()->create([
            'seoable_type'     => Product::class,
            'seoable_id'       => $product_id,
            'shop_language_id' => $default_shop_language_id > 0 ? $default_shop_language_id : null,
            'query_value'      => (string) ($updates['query_value'] ?? $product_id),
            'keyword'          => $updates['keyword'] ?? null,
            'sort_order'       => (int) ($updates['sort_order'] ?? 0),
        ]);
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $special_fields
     */
    private function applyLocalSpecialUpdates(int $product_id, array $special_fields): void
    {
        if ($product_id <= 0 || $special_fields === []) {
            return;
        }

        $special_updates = $this->buildPriceRuleUpdates($special_fields, false);
        if ($special_updates === null) {
            return;
        }

        if (($special_updates['__delete_all'] ?? false) === true) {
            ProductSpecial::query()->where('product_id', $product_id)->delete();

            return;
        }

        $user_group_id = (int) ($special_updates['user_group_id'] ?? 1);
        unset($special_updates['user_group_id'], $special_updates['__delete_all']);

        ProductSpecial::query()->updateOrCreate(
            [
                'product_id'    => $product_id,
                'user_group_id' => $user_group_id,
            ],
            $special_updates
        );
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $discount_fields
     */
    private function applyLocalDiscountUpdates(int $product_id, array $discount_fields): void
    {
        if ($product_id <= 0 || $discount_fields === []) {
            return;
        }

        $discount_updates = $this->buildPriceRuleUpdates($discount_fields, true);
        if ($discount_updates === null) {
            return;
        }

        if (($discount_updates['__delete_all'] ?? false) === true) {
            ProductDiscount::query()->where('product_id', $product_id)->delete();

            return;
        }

        $user_group_id = (int) ($discount_updates['user_group_id'] ?? 1);
        unset($discount_updates['user_group_id'], $discount_updates['__delete_all']);

        ProductDiscount::query()->updateOrCreate(
            [
                'product_id'    => $product_id,
                'user_group_id' => $user_group_id,
            ],
            $discount_updates
        );
    }

    /**
     * @param  array<string,array{action:string,value:mixed}>  $fields
     * @return array<string, mixed>|null
     */
    private function buildPriceRuleUpdates(array $fields, bool $with_quantity): ?array
    {
        $field_map = [
            $this->normalizeHeaderKey('User Group Id') => ['column' => 'user_group_id', 'type' => 'int', 'default' => 1],
            $this->normalizeHeaderKey('Price')         => ['column' => 'price', 'type' => 'float', 'default' => 0],
            $this->normalizeHeaderKey('Priority')      => ['column' => 'priority', 'type' => 'int', 'default' => 1],
            $this->normalizeHeaderKey('Date Start')    => ['column' => 'date_start', 'type' => 'datetime', 'default' => null],
            $this->normalizeHeaderKey('Date End')      => ['column' => 'date_end', 'type' => 'datetime', 'default' => null],
        ];

        if ($with_quantity) {
            $field_map[$this->normalizeHeaderKey('Quantity')] = ['column' => 'quantity', 'type' => 'int', 'default' => 1];
        }

        $has_any_action = false;
        $all_delete     = true;
        $updates        = [];

        foreach ($field_map as $normalized_header => $config) {
            if (! array_key_exists($normalized_header, $fields)) {
                continue;
            }

            $instruction = $fields[$normalized_header];
            $action      = (string) $instruction['action'];

            if (in_array($action, ['skip', 'no_change'], true)) {
                $all_delete = false;

                continue;
            }

            $has_any_action = true;

            if ($action !== 'delete') {
                $all_delete = false;
            }

            if ($action === 'delete') {
                $updates[(string) $config['column']] = $config['default'];

                continue;
            }

            if ($action === 'set') {
                $updates[(string) $config['column']] = $this->normalizeValueByType(
                    (string) $config['type'],
                    $instruction['value'] ?? null
                );
            }
        }

        if (! $has_any_action) {
            return null;
        }

        if ($all_delete) {
            return ['__delete_all' => true];
        }

        if (! array_key_exists('user_group_id', $updates)) {
            $updates['user_group_id'] = 1;
        }

        return $updates;
    }

    private function resolveDefaultShopLanguageId(int $shop_id): int
    {
        return ShopLanguage::getDefaultLanguageIdByShopId($shop_id);
    }

    /**
     * @param  list<string>  $category_path
     */
    private function resolveOrCreateCategoryIdByPath(array $category_path, int $shop_language_id): int
    {
        $category_path = array_values(array_filter(
            array_map(static fn ($segment): string => Str::trim((string) $segment), $category_path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($category_path === []) {
            return 0;
        }

        $parent_category_id = null;

        foreach ($category_path as $category_name) {
            $existing_category_id = CategoryDescription::findCategoryIdByParentAndName($parent_category_id, $category_name);

            if ($existing_category_id === null) {
                $category = Category::query()->create([
                    'parent_id'  => $parent_category_id,
                    'sort_order' => 0,
                    'is_active'  => true,
                ]);

                $existing_category_id = (int) $category->id;
            }

            CategoryDescription::ensureDefaultDescription(
                $existing_category_id,
                $shop_language_id > 0 ? $shop_language_id : null,
                $category_name
            );

            $parent_category_id = $existing_category_id;
        }

        return (int) $parent_category_id;
    }

    private function resolveOrCreateAttributeIdByName(string $attribute_name, int $shop_language_id): int
    {
        $clean_attribute_name = Str::trim($attribute_name);
        if ($clean_attribute_name === '') {
            return 0;
        }

        $attribute_id = AttributeDescription::findAttributeIdByNameForLanguage(
            $clean_attribute_name,
            $shop_language_id
        );

        if ($attribute_id <= 0) {
            $attribute = Attribute::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $attribute_id = (int) $attribute->id;
        }

        AttributeDescription::upsertName(
            $attribute_id,
            $shop_language_id,
            $clean_attribute_name
        );

        return $attribute_id;
    }

    private function resolveOrCreateManufacturerIdByName(string $manufacturer_name, int $shop_language_id): int
    {
        $clean_name = Str::trim($manufacturer_name);
        if ($clean_name === '') {
            return 0;
        }

        $cache_key = Str::lower($clean_name).':'.$shop_language_id;
        if (array_key_exists($cache_key, $this->manufacturer_id_cache_by_name)) {
            return $this->manufacturer_id_cache_by_name[$cache_key];
        }

        $manufacturer_id = ManufacturerDescription::findManufacturerIdByNameForLanguage($clean_name, $shop_language_id);
        if ($manufacturer_id <= 0) {
            $manufacturer = Manufacturer::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $manufacturer_id = (int) $manufacturer->id;
        }

        ManufacturerDescription::upsertName($manufacturer_id, $shop_language_id, $clean_name);
        $this->manufacturer_id_cache_by_name[$cache_key] = $manufacturer_id;

        return $manufacturer_id;
    }

    private function resolveOrCreateBrandIdByName(string $brand_name, int $shop_language_id): int
    {
        $clean_name = Str::trim($brand_name);
        if ($clean_name === '') {
            return 0;
        }

        $cache_key = Str::lower($clean_name).':'.$shop_language_id;
        if (array_key_exists($cache_key, $this->brand_id_cache_by_name)) {
            return $this->brand_id_cache_by_name[$cache_key];
        }

        $brand_id = BrandDescription::findBrandIdByNameForLanguage($clean_name, $shop_language_id);
        if ($brand_id <= 0) {
            $brand = Brand::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $brand_id = (int) $brand->id;
        }

        BrandDescription::upsertName($brand_id, $shop_language_id, $clean_name);
        $this->brand_id_cache_by_name[$cache_key] = $brand_id;

        return $brand_id;
    }

    private function normalizeValueByType(string $type, mixed $value): mixed
    {
        $raw_value = Str::trim((string) $value);

        return match ($type) {
            'int'      => is_numeric($raw_value) ? (int) $raw_value : 0,
            'float'    => is_numeric($raw_value) ? (float) $raw_value : 0,
            'bool'     => $this->normalizeBooleanValue($raw_value),
            'datetime' => $this->normalizeDateTimeValue($raw_value),
            default    => $raw_value !== '' ? $raw_value : null,
        };
    }

    private function normalizeDateTimeValue(string $raw_value): ?string
    {
        if ($raw_value === '') {
            return null;
        }

        if (is_numeric($raw_value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw_value)
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return null;
            }
        }

        return $raw_value;
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
            'processed_at'  => now(),
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
            'captured_at' => now()->toDateTimeString(),
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
                'date_available'         => $this->normalizeDateTimeValue((string) ($product->date_available ?? '')),
                'date_added'             => $this->normalizeDateTimeValue((string) ($product->date_added ?? '')),
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
            'finished_at'     => $is_finished ? now() : null,
            'options'         => [
                ...($product_update_batch->options ?? []),
                'prepare_finished_at'      => now()->toDateTimeString(),
                'prepared_items_total'     => $total_items,
                'prepared_items_processed' => $processed_items,
                'prepared_items_failed'    => $failed_items,
                'last_error'               => null,
            ],
        ]);
    }

}
