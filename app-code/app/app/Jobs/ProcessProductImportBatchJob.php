<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Product\Import\ProductImportBatchesSourceTypeEnum;
use App\Enums\Product\Import\ProductImportBatchesStatusEnum;
use App\Enums\Product\Import\ProductImportItemsStatusEnum;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Products\Imports\ProductImportItem;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Supports\Services\SeoSlug\DefaultSeoSlugService;
use App\Supports\Services\SeoSlug\DeSeoSlugService;
use App\Supports\Services\SeoSlug\EnSeoSlugService;
use App\Supports\Services\SeoSlug\RuSeoSlugService;
use App\Supports\Services\SeoSlug\UaSeoSlugService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Revolution\Google\Sheets\Facades\Sheets;
use RuntimeException;
use Throwable;

class ProcessProductImportBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    private const array SHEET_TO_PAYLOAD_LIST = [
        'Description'       => 'descriptions',
        'Image'             => 'images',
        'Product Category'  => 'categories',
        'Product Attribute' => 'attributes',
        'Seo Url'           => 'seo_urls',
        'Special'           => 'specials',
        'Discount'          => 'discounts',
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

    /**
     * @var array<string, int>
     */
    private array $category_id_cache_by_path = [];

    /**
     * @var array<string, int>
     */
    private array $attribute_id_cache_by_path = [];

    /**
     * @var array<string, int>
     */
    private array $manufacturer_id_cache_by_name = [];

    /**
     * @var array<string, int>
     */
    private array $brand_id_cache_by_name = [];

    public function __construct(public int $batch_id) {}

    public function handle(): void
    {
        $batch = ProductImportBatch::query()->find($this->batch_id);

        if ($batch === null) {
            Log::channel('daily')->warning('Product import batch not found', [
                'batch_id' => $this->batch_id,
            ]);

            return;
        }

        if ($batch->status !== ProductImportBatchesStatusEnum::NEW->value) {
            Log::channel('daily')->info('Product import batch already started or finished', [
                'batch_id' => $batch->id,
                'status'   => $batch->status,
            ]);

            return;
        }

        $processing_options = [
            ...($batch->options ?? []),
            'prepare_started_at' => now()->toDateTimeString(),
            'last_error'         => null,
        ];

        if ($batch->source_type === ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value) {
            $processing_options['export_state']      = 'processing';
            $processing_options['export_started_at'] = now()->toDateTimeString();
        }

        $batch->update([
            'status'      => ProductImportBatchesStatusEnum::PROCESSING->value,
            'started_at'  => now(),
            'finished_at' => null,
            'options'     => $processing_options,
        ]);

        Log::channel('daily')->info('Product import batch started with optional shop_id auto-binding support', [
            'batch_id'     => (int)$batch->id,
            'source_type'  => (string)$batch->source_type,
            'source_name'  => (string)($batch->source_name ?? ''),
            'source_path'  => (string)($batch->source_path ?? ''),
            'source_shop'  => 'product.shop_id',
            'autobind_job' => ProcessProductShopBindingJob::class,
        ]);

        try {
            [$items_payloads, $failed_rows, $failed_details] = match ($batch->source_type) {
                ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value   => $this->prepareItemsFromExcelBatch($batch),
                ProductImportBatchesSourceTypeEnum::GOOGLE_SHEET->value => $this->prepareItemsFromGoogleSheetsBatch($batch),
                ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value  => $this->prepareItemsFromManualBatch($batch),
                default                                                 => throw new RuntimeException('Unknown source type: ' . $batch->source_type),
            };

            $total_items = count($items_payloads);

            $this->storeBatchItems((int)$batch->id, $items_payloads);

            $batch_status = match (true) {
                $total_items === 0 => ProductImportBatchesStatusEnum::FAILED,
                $failed_rows > 0   => ProductImportBatchesStatusEnum::PARTIAL_FAILED,
                default            => ProductImportBatchesStatusEnum::COMPLETED,
            };

            $error_log_path = null;

            if ($failed_details !== [] || $batch_status === ProductImportBatchesStatusEnum::FAILED) {
                $error_log_path = $this->writeBatchErrorLog($batch, $failed_details);
            }

            $batch->update([
                'status'          => $batch_status->value,
                'total_items'     => $total_items,
                'processed_items' => 0,
                'failed_items'    => $failed_rows,
                'finished_at'     => now(),
                'options'         => [
                    ...($batch->options ?? []),
                    'prepare_finished_at' => now()->toDateTimeString(),
                    'prepared_items'      => $total_items,
                    'prepare_failed_rows' => $failed_rows,
                    'error_log_path'      => $error_log_path,
                ],
            ]);
        } catch (Throwable $e) {
            Log::channel('stack')->error($e->getMessage(), $e->getTrace());

            $this->markBatchFailed($batch, $e->getMessage(), $e);
        }
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function prepareItemsFromExcelBatch(ProductImportBatch $batch): array
    {
        $source_path = Str::trim((string)($batch->source_path ?? ''));

        if ($source_path === '') {
            throw new RuntimeException('Excel source path is empty');
        }

        return $this->prepareItemsFromExcelSources([$source_path]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function prepareItemsFromGoogleSheetsBatch(ProductImportBatch $batch): array
    {
        $source_paths = $this->resolveGoogleSheetExportedFiles($batch);

        return $this->prepareItemsFromExcelSources($source_paths);
    }

    /**
     * @return list<string>
     */
    private function resolveGoogleSheetExportedFiles(ProductImportBatch $batch): array
    {
        $options = $batch->options ?? [];

        $source_paths = array_values(array_filter(
            array_map(static fn ($path): string => Str::trim((string) $path), Arr::wrap(Arr::get($options, 'exported_files', []))),
            static fn (string $path): bool => $path !== '',
        ));

        $existing_source_paths = array_values(array_filter(
            $source_paths,
            static fn (string $path): bool => Storage::exists($path),
        ));

        if ($existing_source_paths !== []) {
            if ($existing_source_paths !== $source_paths) {
                Log::channel('daily')->warning('Some Google Sheets exported files are missing, using existing ones only', [
                    'batch_id'         => (int) $batch->id,
                    'requested_files'  => $source_paths,
                    'existing_files'   => $existing_source_paths,
                    'missing_files'    => array_values(array_diff($source_paths, $existing_source_paths)),
                ]);
            }

            return $existing_source_paths;
        }

        $spreadsheet_id = Str::trim((string) Arr::get($options, 'spreadsheet_id', ''));

        if ($spreadsheet_id === '') {
            throw new RuntimeException('Google Sheets spreadsheet_id is missing');
        }

        Log::channel('daily')->info('Google Sheets async export started for import batch', [
            'batch_id'       => (int) $batch->id,
            'spreadsheet_id' => $spreadsheet_id,
        ]);

        $all_sheets_rows = $this->fetchGoogleSheetsRows($spreadsheet_id);
        [$source_path, $exported_files] = $this->storeGoogleSheetsAsExcelFiles($spreadsheet_id, $all_sheets_rows);

        $batch->update([
            'source_path' => $source_path,
            'options'     => [
                ...$options,
                'exported_files'     => $exported_files,
                'export_state'       => 'exported',
                'export_finished_at' => now()->toDateTimeString(),
            ],
        ]);

        Log::channel('daily')->info('Google Sheets async export finished for import batch', [
            'batch_id'       => (int) $batch->id,
            'spreadsheet_id' => $spreadsheet_id,
            'source_path'    => $source_path,
            'chunks_count'   => count($exported_files),
            'exported_files' => $exported_files,
        ]);

        return $exported_files;
    }

    /**
     * @return array<string, list<array<int, string>>>
     */
    private function fetchGoogleSheetsRows(string $spreadsheet_id): array
    {
        try {
            $sheet_names = array_values(Sheets::spreadsheet($spreadsheet_id)->sheetList());
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Failed to fetch Google Sheets sheet list for import batch', [
                'batch_id'       => $this->batch_id,
                'spreadsheet_id' => $spreadsheet_id,
                'error_msg'      => $exception->getMessage(),
                'file'           => $exception->getFile(),
                'line'           => $exception->getLine(),
            ]);

            throw new RuntimeException('Failed to fetch Google Sheets sheet list', 0, $exception);
        }

        $this->validateRequiredSheetNames($sheet_names);

        $all_sheets_rows = [];

        foreach ($sheet_names as $sheet_name) {
            try {
                $rows = Sheets::spreadsheet($spreadsheet_id)
                    ->sheet($sheet_name)
                    ->all();
            } catch (Throwable $exception) {
                Log::channel('stack')->error('Failed to fetch Google Sheets rows for import batch', [
                    'batch_id'       => $this->batch_id,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name'     => $sheet_name,
                    'error_msg'      => $exception->getMessage(),
                    'file'           => $exception->getFile(),
                    'line'           => $exception->getLine(),
                ]);

                throw new RuntimeException("Failed to fetch Google Sheets rows for sheet: {$sheet_name}", 0, $exception);
            }

            $all_sheets_rows[$sheet_name] = $this->normalizeRows($rows);
        }

        $this->validateRequiredHeadersFromRows($all_sheets_rows);

        if (! $this->hasDataRows($all_sheets_rows['Product'] ?? [])) {
            throw new RuntimeException('Google Sheets Product sheet has no data rows');
        }

        return $all_sheets_rows;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function prepareItemsFromManualBatch(ProductImportBatch $batch): array
    {
        $options  = $batch->options ?? [];
        $raw_data = Arr::get($options, 'raw_data');

        if (!is_array($raw_data) || $raw_data === []) {
            throw new RuntimeException('Manual payload is empty');
        }

        $payload = [
            'product'      => [
                'product_id'     => null,
                'shop_id'        => Arr::get($raw_data, 'admin_shop_id'),
                'model'          => Arr::get($raw_data, 'admin_model'),
                'sku'            => Arr::get($raw_data, 'admin_sku'),
                'ean'            => Arr::get($raw_data, 'admin_ean'),
                'quantity'       => Arr::get($raw_data, 'admin_quantity'),
                'minimum'        => Arr::get($raw_data, 'admin_minimum'),
                'image'          => null,
                'price'          => Arr::get($raw_data, 'admin_price'),
                'manufacturer'   => Arr::get($raw_data, 'admin_manufacturer'),
                'brand'          => Arr::get($raw_data, 'admin_brand'),
                'is_active'      => $this->normalizeBooleanValue(Arr::get($raw_data, 'admin_is_active')),
                'date_available' => null,
                'date_added'     => null,
                'note'           => Arr::get($raw_data, 'admin_note'),
            ],
            'descriptions' => array_values(Arr::get($raw_data, 'admin_descriptions', [])),
            'images'       => array_values(Arr::get($raw_data, 'admin_images', [])),
            'categories'   => array_values(Arr::get($raw_data, 'admin_categories', [])),
            'attributes'   => array_values(Arr::get($raw_data, 'admin_attributes', [])),
            'seo_urls'     => array_values(Arr::get($raw_data, 'admin_seo_urls', [])),
            'specials'     => array_values(Arr::get($raw_data, 'admin_specials', [])),
            'discounts'    => array_values(Arr::get($raw_data, 'admin_discounts', [])),
            'source_meta'  => [
                'type' => ProductImportBatchesSourceTypeEnum::ADMIN_PANEL->value,
            ],
        ];

        $payload = $this->normalizeOrGenerateSeoUrls($payload);

        return [[$this->removeEmptyValues($payload)], 0, []];
    }

    /**
     * @param list<string> $source_paths
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function prepareItemsFromExcelSources(array $source_paths): array
    {
        $all_payloads   = [];
        $failed_rows    = 0;
        $failed_details = [];

        foreach ($source_paths as $source_path) {
            [$file_payloads, $file_failed_rows, $file_failed_details] = $this->extractProductsFromExcelFile($source_path);
            $failed_rows    += $file_failed_rows;
            $failed_details = [...$failed_details, ...$file_failed_details];
            $all_payloads   = [...$all_payloads, ...$file_payloads];
        }

        return [$all_payloads, $failed_rows, $failed_details];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function extractProductsFromExcelFile(string $source_path): array
    {
        if (!Storage::exists($source_path)) {
            throw new RuntimeException("Source file does not exist: $source_path");
        }

        try {
            $spreadsheet = IOFactory::load(Storage::path($source_path));
        } catch (Throwable $exception) {
            throw new RuntimeException("Failed to read source file: $source_path", 0, $exception);
        }

        $sheets_rows = [];

        foreach (self::REQUIRED_SHEETS as $sheet_name) {
            $worksheet = $spreadsheet->getSheetByName($sheet_name);

            if ($worksheet === null) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);

                throw new RuntimeException("Missing required sheet: $sheet_name");
            }

            $rows                     = $worksheet->toArray();
            $sheets_rows[$sheet_name] = $this->normalizeRows($rows);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->buildProductsPayloadFromSheetsRowBased($sheets_rows, $source_path);
    }

    /**
     * @param array<string, list<array<int, string>>> $sheets_rows
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: list<array<string, mixed>>}
     */
    private function buildProductsPayloadFromSheetsRowBased(array $sheets_rows, string $source_path): array
    {
        $payloads       = [];
        $failed_rows    = 0;
        $failed_details = [];

        $headers_by_sheet = [];

        foreach (self::REQUIRED_SHEETS as $sheet_name) {
            $headers_by_sheet[$sheet_name] = $this->extractHeader(Arr::get($sheets_rows, $sheet_name, []));
        }

        $product_rows   = Arr::get($sheets_rows, 'Product', []);
        $product_header = $headers_by_sheet['Product'];
        $rows_total     = count($product_rows);

        for ($row_index = 1; $row_index < $rows_total; $row_index++) {
            $product_row   = Arr::get($product_rows, $row_index, []);
            $product_assoc = $this->rowToAssoc($product_header, $product_row);

            if ($this->isAssocRowEmpty($product_assoc)) {
                continue;
            }

            $payload = [
                'product'      => [
                    'product_id'     => null,
                    'shop_id'        => Arr::get($product_assoc, $this->normalizeHeaderKey('Shop Id')),
                    'model'          => Arr::get($product_assoc, $this->normalizeHeaderKey('Model')),
                    'sku'            => Arr::get($product_assoc, $this->normalizeHeaderKey('SKU')),
                    'ean'            => Arr::get($product_assoc, $this->normalizeHeaderKey('EAN')),
                    'quantity'       => Arr::get($product_assoc, $this->normalizeHeaderKey('Quantity')),
                    'minimum'        => Arr::get($product_assoc, $this->normalizeHeaderKey('Minimum')),
                    'image'          => Arr::get($product_assoc, $this->normalizeHeaderKey('Image')),
                    'price'          => Arr::get($product_assoc, $this->normalizeHeaderKey('Price')),
                    'manufacturer'   => Arr::get($product_assoc, $this->normalizeHeaderKey('Manufacturer')),
                    'brand'          => Arr::get($product_assoc, $this->normalizeHeaderKey('Brand')),
                    'is_active'      => $this->normalizeBooleanValue(Arr::get($product_assoc, $this->normalizeHeaderKey('Is Active'))),
                    'date_available' => Arr::get($product_assoc, $this->normalizeHeaderKey('Date Available')),
                    'date_added'     => Arr::get($product_assoc, $this->normalizeHeaderKey('Date Added')),
                ],
                'descriptions' => [],
                'images'       => [],
                'categories'   => [],
                'attributes'   => [],
                'seo_urls'     => [],
                'specials'     => [],
                'discounts'    => [],
                'source_meta'  => [
                    'type'       => ProductImportBatchesSourceTypeEnum::EXCEL_FILE->value,
                    'path'       => $source_path,
                    'row_number' => $row_index + 1,
                ],
            ];

            foreach (self::SHEET_TO_PAYLOAD_LIST as $sheet_name => $payload_key) {
                $sheet_rows   = Arr::get($sheets_rows, $sheet_name, []);
                $sheet_header = $headers_by_sheet[$sheet_name];
                $sheet_row    = Arr::get($sheet_rows, $row_index, []);
                $sheet_assoc  = $this->rowToAssoc($sheet_header, $sheet_row);

                if ($this->isAssocRowEmpty($sheet_assoc)) {
                    continue;
                }

                $mapped_row = $this->mapSheetRow($sheet_name, $sheet_assoc);

                if ($mapped_row === []) {
                    continue;
                }

                $payload[$payload_key][] = $mapped_row;
            }

            $payload    = $this->normalizeOrGenerateSeoUrls($payload);
            $payloads[] = $this->removeEmptyValues($payload);
        }

        return [$payloads, $failed_rows, $failed_details];
    }

    /**
     * @param array<string, string> $row_assoc
     *
     * @return array<string, mixed>
     */
    private function mapSheetRow(string $sheet_name, array $row_assoc): array
    {
        $get = fn(string $header): mixed => Arr::get($row_assoc, $this->normalizeHeaderKey($header));

        return match ($sheet_name) {
            'Description'       => [
                'product_id'         => null,
                'shop_language_code' => $this->normalizeLanguageCode($get('Shop Language Code') ?? $get('Language Code') ?? ''),
                'name'               => $get('Name'),
                'description'        => $get('Description'),
                'meta_title'         => $get('Meta Title'),
                'meta_description'   => $get('Meta Description'),
                'meta_keywords'      => $get('Meta Keywords'),
            ],
            'Image'             => [
                'product_id' => null,
                'image'      => $get('Image'),
                'sort_order' => $get('Sort Order'),
            ],
            'Product Category'  => [
                'product_id'    => null,
                'category_name' => $get('Category Name'),
            ],
            'Product Attribute' => [
                'product_id'         => null,
                'shop_language_code' => $this->normalizeLanguageCode($get('Shop Language Code') ?? $get('Language Code') ?? ''),
                'attribute_name'     => $get('Attribute Name'),
                'attribute_text'     => $get('Attribute Text') ?? $get('Attibute Text'),
            ],
            'Seo Url'           => [
                'product_id'         => null,
                'shop_language_code' => $this->normalizeLanguageCode($get('Shop Language Code') ?? $get('Language Code') ?? ''),
                'query_key'          => $get('Query Key'),
                'query_value'        => $get('Query Value'),
                'keyword'            => $get('Keyword'),
                'sort_order'         => $get('Sort Order'),
            ],
            'Special'           => [
                'product_id'    => null,
                'user_group_id' => $get('User Group Id'),
                'price'         => $get('Price'),
                'priority'      => $get('Priority'),
                'date_start'    => $get('Date Start'),
                'date_end'      => $get('Date End'),
            ],
            'Discount'          => [
                'product_id'    => null,
                'user_group_id' => $get('User Group Id'),
                'quantity'      => $get('Quantity'),
                'price'         => $get('Price'),
                'priority'      => $get('Priority'),
                'date_start'    => $get('Date Start'),
                'date_end'      => $get('Date End'),
            ],
            default             => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalizeOrGenerateSeoUrls(array $payload): array
    {
        $language_codes        = $this->resolveLanguageCodes($payload);
        $default_language_code = $language_codes[0] ?? 'uk';

        $seo_urls   = array_values(Arr::get($payload, 'seo_urls', []));
        $base_text  = $this->resolveBaseTextForSlug($payload);
        $product_id = (string)(Arr::get($payload, 'product.product_id') ?? '');
        $uk_keyword = $this->generateKeywordForLanguage($base_text, 'uk');

        if ($uk_keyword === '') {
            $uk_keyword = $this->normalizeSeoKeyword('product-' . $product_id);
        }

        if ($seo_urls === []) {
            $payload['seo_urls'] = [
                [
                    'product_id'         => $product_id,
                    'shop_language_code' => 'uk',
                    'query_key'          => '',
                    'query_value'        => $product_id,
                    'keyword'            => $uk_keyword,
                    'sort_order'         => 1,
                ],
            ];

            return $payload;
        }

        foreach ($seo_urls as $index => $seo_row) {
            if (!is_array($seo_row)) {
                $seo_urls[$index] = [];

                continue;
            }

            $language_code = $this->normalizeLanguageCode(Arr::get($seo_row, 'shop_language_code', ''));

            if ($language_code === '') {
                $language_code = $default_language_code;
            }

            $keyword = $this->normalizeSeoKeyword((string)Arr::get($seo_row, 'keyword', ''));

            if ($keyword === '') {
                $keyword = $uk_keyword;
            }

            $seo_urls[$index] = [
                ...$seo_row,
                'product_id'         => Arr::get($seo_row, 'product_id', $product_id),
                'shop_language_code' => $language_code,
                'query_key'          => Arr::get($seo_row, 'query_key', ''),
                'query_value'        => $product_id,
                'keyword'            => $keyword,
                'sort_order'         => Arr::get($seo_row, 'sort_order', 1),
            ];
        }

        $payload['seo_urls'] = array_values(array_filter(
            $seo_urls,
            static fn($seo_row) => Str::trim((string)Arr::get($seo_row, 'keyword', '')) !== ''
        ));

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function resolveLanguageCodes(array $payload): array
    {
        $codes = [];

        foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
            if (!is_array($description_row)) {
                continue;
            }

            $code = $this->normalizeLanguageCode(Arr::get($description_row, 'shop_language_code', ''));

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        foreach (Arr::get($payload, 'seo_urls', []) as $seo_row) {
            if (!is_array($seo_row)) {
                continue;
            }

            $code = $this->normalizeLanguageCode(Arr::get($seo_row, 'shop_language_code', ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        if ($codes === []) {
            $codes = $this->getActiveLanguageCodes();
        }

        if ($codes === []) {
            $codes = ['uk', 'en'];
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return list<string>
     */
    private function getActiveLanguageCodes(): array
    {
        try {
            $codes = ShopLanguage::getActiveCodes();
        } catch (Throwable $e) {
            Log::channel('stack')->error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [];
        }

        $normalized_codes = array_values(array_filter(
            array_map(fn($code) => $this->normalizeLanguageCode($code), $codes),
            static fn($code) => $code !== ''
        ));

        return array_values(array_unique($normalized_codes));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveBaseTextForSlug(array $payload): string
    {
        foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
            if (!is_array($description_row)) {
                continue;
            }

            $name = Str::trim((string)Arr::get($description_row, 'name', ''));

            if ($name !== '') {
                return $name;
            }
        }

        $model = Str::trim((string)Arr::get($payload, 'product.model', ''));

        if ($model !== '') {
            return $model;
        }

        $sku = Str::trim((string)Arr::get($payload, 'product.sku', ''));

        if ($sku !== '') {
            return $sku;
        }

        return Str::trim((string)Arr::get($payload, 'product.product_id', 'product'));
    }

    private function generateKeywordForLanguage(string $base_text, string $language_code): string
    {
        $normalized_code = $this->normalizeLanguageCode($language_code);
        $base_slug       = $this->buildBaseSlug($base_text, $normalized_code);

        if ($base_slug === '') {
            return '';
        }

        if (in_array($normalized_code, ['uk', 'ua'], true)) {
            return $this->normalizeSeoKeyword($base_slug);
        }

        return $this->normalizeSeoKeyword($base_slug . '-' . $normalized_code);
    }

    private function buildBaseSlug(string $base_text, string $language_code): string
    {
        if (in_array($language_code, ['uk', 'ua'], true)) {
            return UaSeoSlugService::make($base_text);
        }

        if ($language_code === 'en') {
            return EnSeoSlugService::make($base_text);
        }

        if ($language_code === 'ru') {
            return RuSeoSlugService::make($base_text);
        }

        if ($language_code === 'de') {
            return DeSeoSlugService::make($base_text);
        }

        return DefaultSeoSlugService::make($base_text);
    }

    private function normalizeSeoKeyword(string $keyword): string
    {
        $keyword = Str::lower(Str::ascii(Str::trim($keyword)));
        $keyword = Str::replace(' ', '-', $keyword);
        $keyword = Str::replaceMatches('/[^a-z0-9\-_]+/', '-', $keyword) ?? '';
        $keyword = Str::replaceMatches('/-+/', '-', $keyword) ?? '';
        $keyword = Str::replaceMatches('/_+/', '_', $keyword) ?? '';

        return Str::trim($keyword, '-_');
    }

    private function normalizeLanguageCode(mixed $language_code): string
    {
        return Str::lower(Str::trim((string)$language_code));
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value > 0;
        }

        $normalized = Str::lower(Str::trim((string)$value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param list<array<string, mixed>> $items_payloads
     *
     * @throws Throwable
     */
    private function storeBatchItems(int $batch_id, array $items_payloads): void
    {
        DB::transaction(function () use ($batch_id, $items_payloads): void {
            ProductImportItem::query()
                ->where('product_import_batch_id', $batch_id)
                ->delete();

            if ($items_payloads === []) {
                return;
            }

            $created_at     = now();
            $rows_to_insert = [];

            foreach ($items_payloads as $payload) {
                $rows_to_insert[] = [
                    'product_import_batch_id' => $batch_id,
                    'product_id'              => null,
                    'payload'                 => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'status'                  => ProductImportItemsStatusEnum::NEW->value,
                    'error_message'           => null,
                    'processed_at'            => null,
                    'created_at'              => $created_at,
                    'updated_at'              => $created_at,
                ];
            }

            foreach (array_chunk($rows_to_insert, 300) as $chunk_rows) {
                ProductImportItem::query()->insert($chunk_rows);
            }
        });

        $this->processRawBatchItems($batch_id);
    }

    private function processRawBatchItems(int $batch_id): void
    {
        ProductImportItem::query()
            ->where('product_import_batch_id', $batch_id)
            ->orderBy('id')
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    try {
                        $payload = is_array($item->payload) ? $item->payload : [];

                        $resolved_product_id = $this->createProductForImportPayload($item, $payload);
                        $normalized_payload  = $this->applyProductIdToPayload($payload, $resolved_product_id);
                        $this->persistProductRelatedData($resolved_product_id, $normalized_payload);

                        $item->update([
                            'product_id'    => $resolved_product_id,
                            'payload'       => $normalized_payload,
                            'status'        => ProductImportItemsStatusEnum::SUCCESSED->value,
                            'error_message' => null,
                            'processed_at'  => now(),
                        ]);

                        $this->dispatchAutoBindJobIfNeeded(
                            $item,
                            $normalized_payload,
                            $resolved_product_id
                        );
                    } catch (Throwable $exception) {
                        Log::channel('stack')->error($exception->getMessage(), [
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine(),
                        ]);

                        $item->update([
                            'status'        => ProductImportItemsStatusEnum::FAILED->value,
                            'error_message' => Str::trim($exception->getMessage()),
                            'processed_at'  => now(),
                        ]);

                        throw $exception;
                    }
                }
            });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchAutoBindJobIfNeeded(
        ProductImportItem $product_import_item,
        array             $payload,
        int               $product_id
    ): void {
        if ($product_id <= 0) {
            return;
        }

        $resolved_shop_id = $this->resolveAutoBindShopIdFromPayload($payload);

        if ($resolved_shop_id <= 0) {
            return;
        }

        try {
            ProcessProductShopBindingJob::dispatch(
                $product_id,
                [$resolved_shop_id],
                (int)$product_import_item->product_import_batch_id,
                $payload,
            );

            Log::channel('daily')->info('Auto-bind job dispatched from import item payload', [
                'batch_id'    => (int)$product_import_item->product_import_batch_id,
                'item_id'     => (int)$product_import_item->id,
                'product_id'  => $product_id,
                'shop_id'     => $resolved_shop_id,
                'row_number'  => $product_import_item->getSourceRowNumber(),
                'source_path' => $product_import_item->getSourceFilePath(),
            ]);
        } catch (Throwable $exception) {
            Log::channel('stack')->warning('Failed to dispatch auto-bind job from import item payload', [
                'batch_id'    => (int)$product_import_item->product_import_batch_id,
                'item_id'     => (int)$product_import_item->id,
                'product_id'  => $product_id,
                'shop_id'     => $resolved_shop_id,
                'row_number'  => $product_import_item->getSourceRowNumber(),
                'source_path' => $product_import_item->getSourceFilePath(),
                'error_msg'   => $exception->getMessage(),
                'file'        => $exception->getFile(),
                'line'        => $exception->getLine(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAutoBindShopIdFromPayload(array $payload): int
    {
        $raw_shop_id = Str::trim((string)Arr::get($payload, 'product.shop_id', ''));

        if ($raw_shop_id === '') {
            return 0;
        }

        if (!ctype_digit($raw_shop_id)) {
            Log::channel('stack')->warning('Product import auto-bind skipped due to invalid shop_id format', [
                'shop_id'    => $raw_shop_id,
                'row_number' => Arr::get($payload, 'source_meta.row_number'),
                'batch_id'   => Arr::get($payload, 'source_meta.batch_id'),
                'path'       => Arr::get($payload, 'source_meta.path'),
            ]);

            return 0;
        }

        $shop_id = (int)$raw_shop_id;

        if ($shop_id <= 0) {
            Log::channel('stack')->warning('Product import auto-bind skipped due to non-positive shop_id', [
                'shop_id'    => $shop_id,
                'row_number' => Arr::get($payload, 'source_meta.row_number'),
                'batch_id'   => Arr::get($payload, 'source_meta.batch_id'),
                'path'       => Arr::get($payload, 'source_meta.path'),
            ]);

            return 0;
        }

        try {
            $shop_exists = Shop::query()->whereKey($shop_id)->exists();
        } catch (Throwable) {
            return 0;
        }

        if (!$shop_exists) {
            Log::channel('stack')->warning('Product import auto-bind skipped because shop_id does not exist', [
                'shop_id'    => $shop_id,
                'row_number' => Arr::get($payload, 'source_meta.row_number'),
                'batch_id'   => Arr::get($payload, 'source_meta.batch_id'),
                'path'       => Arr::get($payload, 'source_meta.path'),
            ]);

            return 0;
        }

        return $shop_id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createProductForImportPayload(ProductImportItem $product_import_item, array $payload): int
    {
        $product_attributes = $this->buildProductAttributesForInsert($payload);
        $source_ulid        = Str::trim((string)($product_import_item->getAttribute('ulid') ?? ''));

        if ($source_ulid !== '') {
            $product_attributes['family_ulid'] = $source_ulid;
        }

        return Product::createFromImportPayload(
            $product_attributes,
            (int)$product_import_item->id
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function buildProductAttributesForInsert(array $payload): array
    {
        $product_data = Arr::get($payload, 'product', []);

        return [
            'marked_to_shop' => null,
            'model'          => Str::trim((string)Arr::get($product_data, 'model')),
            'sku'            => Str::trim((string)Arr::get($product_data, 'sku')),
            'ean'            => Str::trim((string)Arr::get($product_data, 'ean')),
            'quantity'       => (int)Arr::get($product_data, 'quantity', 0),
            'minimum'        => max((int)Arr::get($product_data, 'minimum', 1), 1),
            'image'          => Str::trim((string)Arr::get($product_data, 'image')),
            'price'          => (float)Arr::get($product_data, 'price', 0),
            'is_active'      => $this->normalizeBooleanValue(Arr::get($product_data, 'is_active', false)),
            'date_available' => Arr::get($product_data, 'date_available'),
            'date_added'     => Arr::get($product_data, 'date_added'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyProductIdToPayload(array $payload, int $product_id): array
    {
        $payload['product'] = [
            ...(is_array(Arr::get($payload, 'product')) ? Arr::get($payload, 'product') : []),
            'product_id' => $product_id,
        ];

        foreach (self::SHEET_TO_PAYLOAD_LIST as $payload_key) {
            $rows = Arr::get($payload, $payload_key, []);
            if (!is_array($rows)) {
                continue;
            }

            $payload[$payload_key] = array_map(function ($row) use ($product_id) {
                if (!is_array($row)) {
                    return $row;
                }

                return [
                    ...$row,
                    'product_id' => (string)$product_id,
                ];
            }, $rows);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistProductRelatedData(int $product_id, array $payload): void
    {
        $this->upsertProductDescriptions($product_id, Arr::get($payload, 'descriptions', []));
        $this->replaceProductImages($product_id, Arr::get($payload, 'images', []));
        $this->syncProductCategories($product_id, Arr::get($payload, 'categories', []));
        $this->upsertProductAttributes($product_id, Arr::get($payload, 'attributes', []));
        $this->upsertProductManufacturerBrandBinding($product_id, Arr::get($payload, 'product', []));
        $this->upsertSeoUrls($product_id, $payload, Arr::get($payload, 'seo_urls', []));
        $this->upsertProductSpecials($product_id, Arr::get($payload, 'specials', []));
        $this->upsertProductDiscounts($product_id, Arr::get($payload, 'discounts', []));
    }

    private function upsertProductDescriptions(int $product_id, mixed $description_rows): void
    {
        if (!is_array($description_rows)) {
            return;
        }

        foreach ($description_rows as $description_row) {
            if (!is_array($description_row)) {
                continue;
            }

            ProductDescription::query()->updateOrCreate(
                [
                    'product_id'       => $product_id,
                    'shop_language_id' => null,
                ],
                [
                    'name'             => Arr::get($description_row, 'name'),
                    'description'      => Arr::get($description_row, 'description'),
                    'meta_title'       => Arr::get($description_row, 'meta_title'),
                    'meta_description' => Arr::get($description_row, 'meta_description'),
                    'meta_keywords'    => Arr::get($description_row, 'meta_keywords'),
                ]
            );
        }
    }

    private function replaceProductImages(int $product_id, mixed $image_rows): void
    {
        if (!is_array($image_rows)) {
            return;
        }

        ProductImage::query()->where('product_id', $product_id)->delete();

        foreach ($image_rows as $image_row) {
            if (!is_array($image_row)) {
                continue;
            }

            $image_path = Str::trim((string)Arr::get($image_row, 'image', ''));
            if ($image_path === '') {
                continue;
            }

            ProductImage::query()->create([
                'product_id' => $product_id,
                'image'      => $image_path,
                'sort_order' => (int)Arr::get($image_row, 'sort_order', 1),
            ]);
        }
    }

    private function syncProductCategories(int $product_id, mixed $category_rows): void
    {
        if (!is_array($category_rows)) {
            return;
        }

        $resolved_category_ids = [];

        foreach ($category_rows as $category_row) {
            if (!is_array($category_row)) {
                continue;
            }

            $direct_category_id = (int)Arr::get($category_row, 'category_id', 0);
            if ($direct_category_id > 0) {
                $resolved_category_ids[] = $direct_category_id;
            }

            $category_name = Str::trim((string)Arr::get($category_row, 'category_name', ''));
            if ($category_name === '') {
                continue;
            }

            $category_paths = $this->parseCategoryPathsFromRawValue($category_name);

            foreach ($category_paths as $category_path) {
                $category_id = $this->resolveOrCreateCategoryIdByPath($category_path);
                if ($category_id === null) {
                    continue;
                }

                $resolved_category_ids[] = $category_id;
            }
        }

        foreach (array_unique($resolved_category_ids) as $category_id) {
            CategoryProduct::query()->updateOrCreate([
                'product_id'  => $product_id,
                'category_id' => $category_id,
            ]);
        }
    }

    private function upsertProductAttributes(int $product_id, mixed $attribute_rows): void
    {
        if (!is_array($attribute_rows)) {
            return;
        }

        foreach ($attribute_rows as $attribute_row) {
            if (!is_array($attribute_row)) {
                continue;
            }

            $attribute_name = Str::trim((string)Arr::get($attribute_row, 'attribute_name', ''));
            $attribute_text = Str::trim((string)Arr::get($attribute_row, 'attribute_text', Arr::get($attribute_row, 'text', '')));
            if ($attribute_name === '' || $attribute_text === '') {
                continue;
            }

            $attribute_paths = $this->parseAttributePathsFromRawValue($attribute_name);

            foreach ($attribute_paths as $attribute_path) {
                $attribute_id = $this->resolveOrCreateAttributeIdByPath($attribute_path);

                ProductToAttribute::query()->updateOrCreate(
                    [
                        'product_id'       => $product_id,
                        'attribute_id'     => $attribute_id,
                        'shop_language_id' => null,
                    ],
                    [
                        'text' => $attribute_text,
                    ]
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $product_row
     */
    private function upsertProductManufacturerBrandBinding(int $product_id, array $product_row): void
    {
        if ($product_id <= 0) {
            return;
        }

        $manufacturer_name = Str::trim((string)Arr::get($product_row, 'manufacturer', ''));
        $brand_name        = Str::trim((string)Arr::get($product_row, 'brand', ''));

        $manufacturer_id = $manufacturer_name !== ''
            ? $this->resolveOrCreateManufacturerIdByName($manufacturer_name)
            : null;
        $brand_id        = $brand_name !== ''
            ? $this->resolveOrCreateBrandIdByName($brand_name)
            : null;

        if ($manufacturer_id === null && $brand_id === null) {
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
                'manufacturer_id' => $manufacturer_id,
                'brand_id'        => $brand_id,
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertSeoUrls(int $product_id, array $payload, mixed $seo_rows): void
    {
        if (!is_array($seo_rows)) {
            $seo_rows = [];
        }

        if ($seo_rows === []) {
            $seo_rows = [[]];
        }

        $base_text  = $this->resolveBaseTextForSlug($payload);
        $uk_keyword = $this->generateKeywordForLanguage($base_text, 'uk');

        if ($uk_keyword === '') {
            $uk_keyword = $this->normalizeSeoKeyword('product-' . $product_id);
        }

        foreach ($seo_rows as $seo_row) {
            if (!is_array($seo_row)) {
                continue;
            }

            $query_value = (string)$product_id;
            $language_code = $this->normalizeLanguageCode((string) Arr::get($seo_row, 'shop_language_code', ''));
            $shop_language_id = null;
            $shop_id = (int) Arr::get($payload, 'product.shop_id', 0);
            if ($shop_id > 0 && $language_code !== '') {
                $shop_language_id = ShopLanguage::query()
                    ->where('shop_id', $shop_id)
                    ->whereRaw('LOWER(code) = ?', [$language_code])
                    ->value('id');
                $shop_language_id = $shop_language_id !== null ? (int) $shop_language_id : null;
            }

            $keyword = $this->normalizeSeoKeyword((string)Arr::get($seo_row, 'keyword', ''));

            if ($keyword === '') {
                $resolved_language_code = $language_code !== '' ? $language_code : 'uk';
                $keyword = $this->generateKeywordForLanguage($base_text, $resolved_language_code);

                if ($keyword === '') {
                    $keyword = $uk_keyword;
                }

                Log::channel('stack')->debug('[FIX] Import SEO keyword fallback generated', [
                    'batch_id'       => $this->batch_id,
                    'product_id'     => $product_id,
                    'row_number'     => Arr::get($payload, 'source_meta.row_number'),
                    'product_model'  => (string) Arr::get($payload, 'product.model', ''),
                    'language_code'  => $resolved_language_code,
                    'strategy'       => 'fallback_generated',
                ]);
            }

            SeoUrl::query()->updateOrCreate(
                [
                    'seoable_type'     => Product::class,
                    'seoable_id'       => $product_id,
                    'shop_language_id' => $shop_language_id,
                    'query_value'      => $query_value,
                ],
                [
                    'query_key'   => Arr::get($seo_row, 'query_key'),
                    'keyword'    => $keyword,
                    'sort_order' => (int)Arr::get($seo_row, 'sort_order', 1) ?: 1,
                ]
            );
        }
    }

    private function upsertProductSpecials(int $product_id, mixed $special_rows): void
    {
        if (!is_array($special_rows)) {
            return;
        }

        foreach ($special_rows as $special_row) {
            if (!is_array($special_row)) {
                continue;
            }

            ProductSpecial::query()->updateOrCreate(
                [
                    'product_id'    => $product_id,
                    'user_group_id' => (int)Arr::get($special_row, 'user_group_id', 1),
                ],
                [
                    'price'      => (float)Arr::get($special_row, 'price', 0),
                    'priority'   => (int)Arr::get($special_row, 'priority', 1),
                    'date_start' => Arr::get($special_row, 'date_start') ?: now()->toDateTimeString(),
                    'date_end'   => Arr::get($special_row, 'date_end') ?: now()->toDateTimeString(),
                ]
            );
        }
    }

    private function upsertProductDiscounts(int $product_id, mixed $discount_rows): void
    {
        if (!is_array($discount_rows)) {
            return;
        }

        foreach ($discount_rows as $discount_row) {
            if (!is_array($discount_row)) {
                continue;
            }

            ProductDiscount::query()->updateOrCreate(
                [
                    'product_id'    => $product_id,
                    'user_group_id' => (int)Arr::get($discount_row, 'user_group_id', 1),
                ],
                [
                    'quantity'   => max((int)Arr::get($discount_row, 'quantity', 1), 1),
                    'price'      => (float)Arr::get($discount_row, 'price', 0),
                    'priority'   => (int)Arr::get($discount_row, 'priority', 1),
                    'date_start' => Arr::get($discount_row, 'date_start') ?: now()->toDateTimeString(),
                    'date_end'   => Arr::get($discount_row, 'date_end') ?: now()->toDateTimeString(),
                ]
            );
        }
    }

    /**
     * @param list<string> $category_path
     */
    private function resolveOrCreateCategoryIdByPath(array $category_path): ?int
    {
        $category_path = array_values(array_filter(
            array_map(
                static fn($category_segment): string => Str::trim((string)$category_segment),
                $category_path
            ),
            static fn(string $category_segment): bool => $category_segment !== ''
        ));

        if ($category_path === []) {
            return null;
        }

        $path_cache_key = Str::lower(implode(' > ', $category_path));

        if (array_key_exists($path_cache_key, $this->category_id_cache_by_path)) {
            return $this->category_id_cache_by_path[$path_cache_key];
        }

        $parent_category_id = null;

        foreach ($category_path as $category_name) {
            $current_category_id = $this->findCategoryIdByParentAndName(
                $parent_category_id,
                $category_name,
            );

            if ($current_category_id === null) {
                $category = Category::query()->create([
                    'parent_id'  => $parent_category_id,
                    'sort_order' => 0,
                    'is_active'  => true,
                ]);

                $current_category_id = (int)$category->id;
            }

            $this->ensureCategoryDescription(
                $current_category_id,
                null,
                $category_name,
            );

            $parent_category_id = $current_category_id;
        }

        $this->category_id_cache_by_path[$path_cache_key] = $parent_category_id;

        return $parent_category_id;
    }

    private function findCategoryIdByParentAndName(
        ?int   $parent_category_id,
        string $category_name,
    ): ?int {
        $normalized_category_name = Str::lower(Str::trim($category_name));
        if ($normalized_category_name === '') {
            return null;
        }

        $query = Category::query()
            ->select('categories.id')
            ->join('category_descriptions', 'category_descriptions.category_id', '=', 'categories.id')
            ->whereRaw('LOWER(' . config('database.db_prefix') . 'category_descriptions.name) = ?', [$normalized_category_name])
            ->when(
                $parent_category_id === null,
                static fn($builder) => $builder->whereNull('categories.parent_id'),
                static fn($builder) => $builder->where('categories.parent_id', $parent_category_id),
            );

        if ($this->hasCatalogEntityShopScopeColumns('categories')) {
            $query->whereNull('categories.shop_id');
        }

        $category_id = $query
            ->orderByRaw(
                'CASE WHEN ' . config('database.db_prefix') . 'category_descriptions.shop_language_id IS NULL THEN 0 ELSE 1 END'
            )
            ->orderBy('categories.id')
            ->value('categories.id');

        return $category_id !== null ? (int)$category_id : null;
    }

    private function ensureCategoryDescription(
        int    $category_id,
        ?int   $shop_language_id,
        string $category_name
    ): void {
        CategoryDescription::ensureDefaultDescription($category_id, $shop_language_id, $category_name);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCategoryPathsFromRawValue(string $raw_category_value): array
    {
        $normalized_input = Str::trim($raw_category_value);

        if ($normalized_input === '') {
            return [];
        }

        $category_chunks = preg_split('/\s*\|\s*/u', $normalized_input) ?: [];
        $category_paths  = [];
        $seen_paths      = [];

        foreach ($category_chunks as $category_chunk) {
            $category_chunk = Str::trim((string)$category_chunk);
            if ($category_chunk === '') {
                continue;
            }

            $path_segments = preg_split('/\s*>\s*/u', $category_chunk) ?: [];
            $path_segments = array_values(array_filter(
                array_map(static fn($segment): string => Str::trim((string)$segment), $path_segments),
                static fn(string $segment): bool => $segment !== '',
            ));

            if ($path_segments === []) {
                continue;
            }

            $path_key = Str::lower(implode(' > ', $path_segments));

            if (array_key_exists($path_key, $seen_paths)) {
                continue;
            }

            $seen_paths[$path_key] = true;
            $category_paths[]      = $path_segments;
        }

        return $category_paths;
    }

    private function resolveOrCreateAttributeIdByName(string $attribute_name): int
    {
        return $this->resolveOrCreateAttributeIdByPath([$attribute_name]);
    }

    /**
     * @param list<string> $attribute_path
     */
    private function resolveOrCreateAttributeIdByPath(array $attribute_path): int
    {
        $attribute_segments = array_values(array_filter(
            array_map(
                static fn($attribute_segment): string => Str::trim((string)$attribute_segment),
                $attribute_path
            ),
            static fn(string $attribute_segment): bool => $attribute_segment !== ''
        ));

        if ($attribute_segments === []) {
            throw new RuntimeException('Attribute path is empty');
        }

        $attribute_name = (string)($attribute_segments[0] ?? '');
        $path_cache_key = Str::lower($attribute_name);
        if (array_key_exists($path_cache_key, $this->attribute_id_cache_by_path)) {
            return $this->attribute_id_cache_by_path[$path_cache_key];
        }

        $resolved_attribute_id = $this->findGlobalAttributeIdByName($attribute_name);
        if ($resolved_attribute_id > 0) {
            $this->attribute_id_cache_by_path[$path_cache_key] = $resolved_attribute_id;

            return $resolved_attribute_id;
        }

        $attribute = Attribute::query()->create([
            'shop_id'    => null,
            'sort_order' => 1,
            'is_active'  => true,
        ]);

        $resolved_attribute_id = (int)$attribute->id;

        $this->ensureAttributeDescription($resolved_attribute_id, null, $attribute_name);
        $this->attribute_id_cache_by_path[$path_cache_key] = $resolved_attribute_id;

        return $resolved_attribute_id;
    }

    private function ensureAttributeDescription(
        int    $attribute_id,
        ?int   $shop_language_id,
        string $attribute_name
    ): void {
        if (Str::trim($attribute_name) === '') {
            return;
        }

        AttributeDescription::upsertName($attribute_id, $shop_language_id ?? 0, $attribute_name);
    }

    private function resolveOrCreateManufacturerIdByName(string $manufacturer_name): ?int
    {
        $clean_name = Str::trim($manufacturer_name);
        if ($clean_name === '') {
            return null;
        }

        $cache_key = Str::lower($clean_name);
        if (array_key_exists($cache_key, $this->manufacturer_id_cache_by_name)) {
            return $this->manufacturer_id_cache_by_name[$cache_key];
        }

        $manufacturer_id = $this->findGlobalManufacturerIdByName($clean_name);
        if ($manufacturer_id <= 0) {
            $manufacturer = Manufacturer::query()->create([
                'shop_id'    => null,
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $manufacturer_id = (int)$manufacturer->id;
        }

        ManufacturerDescription::upsertName($manufacturer_id, 0, $clean_name);
        $this->manufacturer_id_cache_by_name[$cache_key] = $manufacturer_id;

        return $manufacturer_id;
    }

    private function resolveOrCreateBrandIdByName(string $brand_name): ?int
    {
        $clean_name = Str::trim($brand_name);
        if ($clean_name === '') {
            return null;
        }

        $cache_key = Str::lower($clean_name);
        if (array_key_exists($cache_key, $this->brand_id_cache_by_name)) {
            return $this->brand_id_cache_by_name[$cache_key];
        }

        $brand_id = $this->findGlobalBrandIdByName($clean_name);
        if ($brand_id <= 0) {
            $brand = Brand::query()->create([
                'shop_id'    => null,
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $brand_id = (int)$brand->id;
        }

        BrandDescription::upsertName($brand_id, 0, $clean_name);
        $this->brand_id_cache_by_name[$cache_key] = $brand_id;

        return $brand_id;
    }

    /**
     * @return list<list<string>>
     */
    private function parseAttributePathsFromRawValue(string $raw_attribute_value): array
    {
        $normalized_input = Str::trim($raw_attribute_value);

        if ($normalized_input === '') {
            return [];
        }

        $attribute_chunks = preg_split('/\s*\|\s*/u', $normalized_input) ?: [];
        $attribute_paths  = [];
        $seen_paths       = [];

        foreach ($attribute_chunks as $attribute_chunk) {
            $attribute_chunk = Str::trim((string)$attribute_chunk);
            if ($attribute_chunk === '') {
                continue;
            }

            $path_segments = [$attribute_chunk];
            $path_key      = Str::lower($attribute_chunk);
            if (array_key_exists($path_key, $seen_paths)) {
                continue;
            }

            $seen_paths[$path_key] = true;
            $attribute_paths[]     = $path_segments;
        }

        return $attribute_paths;
    }

    private function findGlobalAttributeIdByName(string $attribute_name): int
    {
        $clean_attribute_name = Str::trim($attribute_name);
        if ($clean_attribute_name === '') {
            return 0;
        }

        $query = AttributeDescription::query()
            ->select('attribute_descriptions.attribute_id')
            ->join('attributes', 'attributes.id', '=', 'attribute_descriptions.attribute_id')
            ->whereRaw('LOWER(' . config('database.db_prefix') . 'attribute_descriptions.name) = ?', [Str::lower($clean_attribute_name)])
            ->whereNull('attribute_descriptions.shop_language_id');

        if ($this->hasCatalogEntityShopScopeColumns('attributes')) {
            $query->whereNull('attributes.shop_id');
        }

        return (int)($query->value('attribute_descriptions.attribute_id') ?? 0);
    }

    private function findGlobalManufacturerIdByName(string $manufacturer_name): int
    {
        $clean_name = Str::trim($manufacturer_name);
        if ($clean_name === '') {
            return 0;
        }

        $query = ManufacturerDescription::query()
            ->select('manufacturer_descriptions.manufacturer_id')
            ->join('manufacturers', 'manufacturers.id', '=', 'manufacturer_descriptions.manufacturer_id')
            ->whereRaw('LOWER(' . config('database.db_prefix') . 'manufacturer_descriptions.name) = ?', [Str::lower($clean_name)])
            ->whereNull('manufacturer_descriptions.shop_language_id');

        if ($this->hasCatalogEntityShopScopeColumns('manufacturers')) {
            $query->whereNull('manufacturers.shop_id');
        }

        return (int)($query->value('manufacturer_descriptions.manufacturer_id') ?? 0);
    }

    private function findGlobalBrandIdByName(string $brand_name): int
    {
        $clean_name = Str::trim($brand_name);
        if ($clean_name === '') {
            return 0;
        }

        $query = BrandDescription::query()
            ->select('brand_descriptions.brand_id')
            ->join('brands', 'brands.id', '=', 'brand_descriptions.brand_id')
            ->whereRaw('LOWER(' . config('database.db_prefix') . 'brand_descriptions.name) = ?', [Str::lower($clean_name)])
            ->whereNull('brand_descriptions.shop_language_id');

        if ($this->hasCatalogEntityShopScopeColumns('brands')) {
            $query->whereNull('brands.shop_id');
        }

        return (int)($query->value('brand_descriptions.brand_id') ?? 0);
    }

    private function hasCatalogEntityShopScopeColumns(string $table_name): bool
    {
        try {
            $schema_builder = DB::connection()->getSchemaBuilder();

            return $schema_builder->hasColumn($table_name, 'shop_id')
                && $schema_builder->hasColumn($table_name, 'family_ulid');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $sheet_names
     */
    private function validateRequiredSheetNames(array $sheet_names): void
    {
        $missing_sheets = array_values(array_diff(self::REQUIRED_SHEETS, $sheet_names));

        if ($missing_sheets === []) {
            return;
        }

        throw new RuntimeException('Missing required sheet(s): '.implode(', ', $missing_sheets));
    }

    /**
     * @param  array<string, list<array<int, string>>>  $all_sheets_rows
     */
    private function validateRequiredHeadersFromRows(array $all_sheets_rows): void
    {
        foreach (self::REQUIRED_HEADERS as $sheet_name => $required_headers) {
            $rows = $all_sheets_rows[$sheet_name] ?? [];

            if ($rows === []) {
                throw new RuntimeException("Sheet is empty: {$sheet_name}");
            }

            $header            = $rows[0] ?? [];
            $normalized_header = array_map(fn ($cell): string => $this->normalizeHeaderKey((string) $cell), $header);
            $missing_headers   = [];

            foreach ($required_headers as $required_header) {
                if (! $this->isHeaderPresent($required_header, $normalized_header)) {
                    $missing_headers[] = $required_header;
                }
            }

            if ($missing_headers !== []) {
                throw new RuntimeException("Missing required columns in sheet {$sheet_name}: ".implode(', ', $missing_headers));
            }
        }
    }

    /**
     * @param  list<string>  $normalized_header
     */
    private function isHeaderPresent(string $required_header, array $normalized_header): bool
    {
        $normalized_required_header = $this->normalizeHeaderKey($required_header);

        if ($normalized_required_header === 'attribute_text') {
            return in_array('attribute_text', $normalized_header, true)
                || in_array('attibute_text', $normalized_header, true);
        }

        return in_array($normalized_required_header, $normalized_header, true);
    }

    /**
     * @param  list<array<int, string>>  $rows
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
     * @param  array<string, list<array<int, string>>>  $all_sheets_rows
     * @return array{0: string, 1: list<string>}
     */
    private function storeGoogleSheetsAsExcelFiles(string $spreadsheet_id, array $all_sheets_rows): array
    {
        $chunk_count = 1;

        foreach ($all_sheets_rows as $rows) {
            $data_row_count = max(count($rows) - 1, 0);
            $chunk_count    = max($chunk_count, (int) ceil($data_row_count / self::MAX_ROWS_PER_FILE));
        }

        $now             = now();
        $year_month_path = sprintf('upload/excel/%s/%s', $now->format('Y'), $now->format('m'));
        $timestamp       = $now->format('Ymd_His');
        $suffix          = Str::replaceMatches('/[^a-zA-Z0-9_-]/', '', $spreadsheet_id) ?: 'sheet';
        $base_folder     = sprintf('%s/google_sheet_%s_%s', $year_month_path, $timestamp, $suffix);
        $single_file     = sprintf('%s/google_sheet_%s_%s.xlsx', $year_month_path, $timestamp, $suffix);

        $exported_files = [];

        if ($chunk_count > 1) {
            Storage::makeDirectory($base_folder);

            for ($chunk_index = 1; $chunk_index <= $chunk_count; $chunk_index++) {
                $file_path = sprintf('%s/part_%02d.xlsx', $base_folder, $chunk_index);
                $this->writeChunkToExcelFile($file_path, $all_sheets_rows, $chunk_index);
                $exported_files[] = $file_path;
            }

            return [$base_folder, $exported_files];
        }

        $this->writeChunkToExcelFile($single_file, $all_sheets_rows, 1);
        $exported_files[] = $single_file;

        return [$single_file, $exported_files];
    }

    /**
     * @param  array<string, list<array<int, string>>>  $all_sheets_rows
     */
    private function writeChunkToExcelFile(string $file_path, array $all_sheets_rows, int $chunk_index): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $start_offset = ($chunk_index - 1) * self::MAX_ROWS_PER_FILE;

        foreach ($all_sheets_rows as $sheet_name => $rows) {
            $worksheet = new Worksheet($spreadsheet, $this->sanitizeSheetTitle($sheet_name));
            $spreadsheet->addSheet($worksheet);

            $header      = $rows[0] ?? [];
            $data_rows   = array_slice($rows, 1);
            $data_chunk  = array_slice($data_rows, $start_offset, self::MAX_ROWS_PER_FILE);
            $rows_to_put = [];

            if ($header !== []) {
                $rows_to_put[] = $header;
            }

            if ($data_chunk !== []) {
                $rows_to_put = [...$rows_to_put, ...$data_chunk];
            }

            if ($rows_to_put !== []) {
                $worksheet->fromArray($rows_to_put, null, 'A1', true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);
        Storage::makeDirectory(dirname($file_path));
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save(Storage::path($file_path));
        $spreadsheet->disconnectWorksheets();

        unset($spreadsheet);
    }

    private function sanitizeSheetTitle(string $sheet_title): string
    {
        $sanitized_sheet_title = Str::replaceMatches('~[:\\\\/?*\\[\\]]~', '_', Str::trim($sheet_title));
        $sanitized_sheet_title = $sanitized_sheet_title === '' ? 'Sheet' : $sanitized_sheet_title;

        return Str::substr($sanitized_sheet_title, 0, 31);
    }

    /**
     * @param list<array<int, string>> $rows
     *
     * @return list<string>
     */
    private function extractHeader(array $rows): array
    {
        $header = $rows[0] ?? [];

        return array_map(fn($value) => $this->normalizeHeaderKey((string)$value), $header);
    }

    /**
     * @param list<string>       $header
     * @param array<int, string> $row
     *
     * @return array<string, string>
     */
    private function rowToAssoc(array $header, array $row): array
    {
        $normalized_row = array_values($row);
        $header_count   = count($header);

        if (count($normalized_row) < $header_count) {
            $normalized_row = array_pad($normalized_row, $header_count, '');
        }

        if (count($normalized_row) > $header_count) {
            $normalized_row = array_slice($normalized_row, 0, $header_count);
        }

        $row_assoc = array_combine($header, $normalized_row);

        return $row_assoc ?: [];
    }

    /**
     * @param array<string, string> $row_assoc
     */
    private function isAssocRowEmpty(array $row_assoc): bool
    {
        return array_all($row_assoc, fn($value) => Str::trim((string)$value) === '');
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return list<array<int, string>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized_rows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized_rows[] = array_map(
                static fn($cell) => is_scalar($cell) ? decode_html_entities(Str::trim((string)$cell)) : '',
                array_values($row)
            );
        }

        return $normalized_rows;
    }

    private function normalizeHeaderKey(string $header): string
    {
        return Str::snake(Str::squish(Str::replace(['-', '_'], ' ', Str::trim($header))));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function removeEmptyValues(array $data): array
    {
        $cleaned_data = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeEmptyValues($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $cleaned_data[$key] = $value;
        }

        return $cleaned_data;
    }

    private function markBatchFailed(ProductImportBatch $batch, string $reason, ?Throwable $exception = null): void
    {
        $error_log_path = $this->writeBatchErrorLog($batch, [], $exception, $reason);

        $batch->update([
            'status'      => ProductImportBatchesStatusEnum::FAILED->value,
            'finished_at' => now(),
            'options'     => [
                ...($batch->options ?? []),
                'prepare_finished_at' => now()->toDateTimeString(),
                'last_error'          => $reason,
                'error_log_path'      => $error_log_path,
            ],
        ]);

        Log::channel('stack')->error('Failed to process product import batch', [
            'batch_id'  => $batch->id,
            'reason'    => $reason,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $failed_details
     */
    private function writeBatchErrorLog(
        ProductImportBatch $batch,
        array              $failed_details,
        ?Throwable         $exception = null,
        ?string            $reason = null
    ): ?string {
        $log_lines   = [];
        $log_lines[] = 'datetime: ' . now()->toDateTimeString();
        $log_lines[] = 'batch_id: ' . $batch->id;
        $log_lines[] = 'source_type: ' . $batch->source_type;

        $trimmed_reason = Str::trim((string)$reason);
        if ($trimmed_reason !== '') {
            $log_lines[] = 'reason: ' . $trimmed_reason;
        }

        if ($exception !== null) {
            $log_lines[] = 'exception: ' . Str::trim($exception->getMessage());
        }

        if ($failed_details !== []) {
            $log_lines[] = 'failed_rows: ' . count($failed_details);

            foreach ($failed_details as $index => $failed_detail) {
                $line_number = $index + 1;
                $sheet       = Str::trim((string)Arr::get($failed_detail, 'sheet', ''));
                $row         = Arr::get($failed_detail, 'row');
                $source_path = Str::trim((string)Arr::get($failed_detail, 'source_path', ''));
                $message     = Str::trim((string)Arr::get($failed_detail, 'message', 'Unknown error'));

                $log_lines[] = "$line_number. sheet=$sheet; row=$row; source=$source_path; message=$message";
            }
        }

        if ($trimmed_reason === '' && $exception === null && $failed_details === []) {
            return null;
        }

        $log_path = storage_path($this->buildBatchErrorLogPath((int)$batch->id));

        if (FIle::exists(File::dirname($log_path)) === false) {
            File::makeDirectory(File::dirname($log_path), recursive: true);
        }

        File::put($log_path, implode(PHP_EOL, $log_lines) . PHP_EOL);
        //        Storage::put($log_path, implode(PHP_EOL, $log_lines).PHP_EOL);

        return $log_path;
    }

    private function buildBatchErrorLogPath(int $batch_id): string
    {
        $now_date  = now();
        $directory = 'logs/product-imports/' . $now_date->format('Y/m');
        $file_name = 'product-import-batch-' . $batch_id . '-errors-' . $now_date->format('Ymd_His') . '.log';

        return $directory . '/' . $file_name;
    }
}
