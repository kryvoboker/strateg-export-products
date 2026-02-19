<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProductImportBatchJob;
use App\Jobs\ProcessProductUpdateBatchJob;
use App\Jobs\ProcessProductUpdateItemJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ProductUpdateInstructionsLogicTest extends TestCase
{
    public function test_batch_job_build_field_instruction_handles_delete_no_change_skip_and_set(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        $delete_instruction_latin_x    = $this->invokePrivateMethod($job, 'buildFieldInstruction', [' X ']);
        $delete_instruction_cyrillic_x = $this->invokePrivateMethod($job, 'buildFieldInstruction', [' х ']);
        $no_change_instruction         = $this->invokePrivateMethod($job, 'buildFieldInstruction', ['']);
        $set_instruction               = $this->invokePrivateMethod($job, 'buildFieldInstruction', ['MODEL-001']);

        self::assertSame(['action' => 'delete', 'value' => null], $delete_instruction_latin_x);
        self::assertSame(['action' => 'delete', 'value' => null], $delete_instruction_cyrillic_x);
        self::assertSame(['action' => 'no_change', 'value' => null], $no_change_instruction);
        self::assertSame(['action' => 'set', 'value' => 'MODEL-001'], $set_instruction);
    }

    public function test_batch_job_extract_unique_values_includes_product_id(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        /** @var array<string, string> $product_row */
        $product_row = [
            'product_id'          => '101',
            'shop_id'             => '',
            'external_product_id' => '',
            'model'               => '',
            'ean'                 => '',
        ];

        /** @var array{product_id:string,shop_id:string,external_product_id:string,model:string,ean:string} $unique_values */
        $unique_values = $this->invokePrivateMethod($job, 'extractUniqueValuesFromProductRow', [$product_row]);

        self::assertSame('101', $unique_values['product_id']);
        self::assertSame('', $unique_values['shop_id']);
        self::assertSame('', $unique_values['external_product_id']);
        self::assertSame('', $unique_values['model']);
        self::assertSame('', $unique_values['ean']);

        $all_empty = $this->invokePrivateMethod($job, 'isAllUniqueValuesEmpty', [$unique_values]);
        self::assertFalse($all_empty);
    }

    public function test_batch_job_extract_unique_values_treats_delete_marker_as_empty(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        /** @var array<string, string> $product_row */
        $product_row = [
            'product_id'          => 'x',
            'shop_id'             => 'Х',
            'external_product_id' => ' x ',
            'model'               => 'х',
            'ean'                 => 'X',
        ];

        /** @var array{product_id:string,shop_id:string,external_product_id:string,model:string,ean:string} $unique_values */
        $unique_values = $this->invokePrivateMethod($job, 'extractUniqueValuesFromProductRow', [$product_row]);

        self::assertSame('', $unique_values['product_id']);
        self::assertSame('', $unique_values['shop_id']);
        self::assertSame('', $unique_values['external_product_id']);
        self::assertSame('', $unique_values['model']);
        self::assertSame('', $unique_values['ean']);
    }

    public function test_update_item_job_build_api_update_directives_removes_skip_and_invalid_actions(): void
    {
        $job = new ProcessProductUpdateItemJob(1);

        /** @var array<string, mixed> $update_instructions */
        $update_instructions = [
            'Product' => [
                'fields' => [
                    'model'   => ['action' => 'skip', 'value' => null],
                    'sku'     => ['action' => 'set', 'value' => 'SKU-NEW'],
                    'price'   => ['action' => 'delete', 'value' => null],
                    'minimum' => ['action' => 'weird_action', 'value' => 10],
                ],
            ],
            'Description' => [
                'fields' => [
                    'name' => ['action' => 'no_change', 'value' => null],
                ],
            ],
            'Image' => [
                'fields' => [],
            ],
        ];

        /** @var array<string, mixed> $directives */
        $directives = $this->invokePrivateMethod($job, 'buildApiUpdateDirectives', [$update_instructions]);

        self::assertArrayHasKey('Product', $directives);
        self::assertArrayHasKey('Description', $directives);
        self::assertArrayNotHasKey('Image', $directives);

        self::assertArrayNotHasKey('model', $directives['Product']);
        self::assertArrayNotHasKey('minimum', $directives['Product']);

        self::assertSame(['action' => 'set', 'value' => 'SKU-NEW'], $directives['Product']['sku']);
        self::assertSame(['action' => 'delete', 'value' => null], $directives['Product']['price']);
        self::assertSame(['action' => 'no_change', 'value' => null], $directives['Description']['name']);
    }

    public function test_batch_job_resolve_sheet_field_instruction_supports_space_and_underscore_keys(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        /** @var array<string, mixed> $sheet_fields */
        $sheet_fields = [
            'is active'      => ['action' => 'set', 'value' => '1'],
            'date available' => ['action' => 'set', 'value' => '2026-02-17'],
            'sku'            => ['action' => 'set', 'value' => 'SKU-1'],
        ];

        $is_active      = $this->invokePrivateMethod($job, 'resolveSheetFieldInstruction', [$sheet_fields, 'is_active']);
        $date_available = $this->invokePrivateMethod($job, 'resolveSheetFieldInstruction', [$sheet_fields, 'date_available']);
        $sku            = $this->invokePrivateMethod($job, 'resolveSheetFieldInstruction', [$sheet_fields, 'sku']);
        $missing        = $this->invokePrivateMethod($job, 'resolveSheetFieldInstruction', [$sheet_fields, 'minimum']);

        self::assertSame(['action' => 'set', 'value' => '1'], $is_active);
        self::assertSame(['action' => 'set', 'value' => '2026-02-17'], $date_available);
        self::assertSame(['action' => 'set', 'value' => 'SKU-1'], $sku);
        self::assertNull($missing);
    }

    public function test_update_batch_job_normalize_header_key_returns_snake_case_for_common_variants(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        $cases = [
            'Product Id'      => 'product_id',
            'product_id'      => 'product_id',
            'product id'      => 'product_id',
            'ProductId'       => 'product_id',
            'Shop-Language'   => 'shop_language',
            'MetaDescription' => 'meta_description',
        ];

        foreach ($cases as $input => $expected) {
            $actual = $this->invokePrivateMethod($job, 'normalizeHeaderKey', [$input]);
            self::assertSame($expected, $actual);
        }
    }

    public function test_import_batch_job_normalize_header_key_returns_snake_case_for_common_variants(): void
    {
        $job = new ProcessProductImportBatchJob(1);

        $cases = [
            'Product Id'         => 'product_id',
            'product_id'         => 'product_id',
            'product id'         => 'product_id',
            'ProductId'          => 'product_id',
            'Shop Language Code' => 'shop_language_code',
            'ShopLanguageCode'   => 'shop_language_code',
        ];

        foreach ($cases as $input => $expected) {
            $actual = $this->invokePrivateMethod($job, 'normalizeHeaderKey', [$input]);
            self::assertSame($expected, $actual);
        }
    }

    public function test_batch_job_normalize_value_by_type_converts_excel_datetime_serial(): void
    {
        $job = new ProcessProductUpdateBatchJob(1);

        $converted = $this->invokePrivateMethod($job, 'normalizeValueByType', ['datetime', '46024']);

        self::assertIsString($converted);
        self::assertStringStartsWith('2026-01-02', $converted);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokePrivateMethod(object $target, string $method_name, array $arguments = []): mixed
    {
        $method = new ReflectionMethod($target, $method_name);

        return $method->invokeArgs($target, $arguments);
    }
}
