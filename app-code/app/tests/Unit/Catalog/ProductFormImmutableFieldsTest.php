<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use PHPUnit\Framework\TestCase;

class ProductFormImmutableFieldsTest extends TestCase
{
    public function test_product_form_marks_product_id_model_and_ean_as_disabled_and_not_dehydrated(): void
    {
        $contents = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Filament/Resources/Catalog/Products/Schemas/ProductForm.php'
        );

        self::assertMatchesRegularExpression(
            '/TextInput::make\(\'product_id\'\).*?->disabled\(\).*?->dehydrated\(false\)/s',
            $contents
        );

        self::assertMatchesRegularExpression(
            '/TextInput::make\(\'model\'\).*?->disabled\(\).*?->dehydrated\(false\)/s',
            $contents
        );

        self::assertMatchesRegularExpression(
            '/TextInput::make\(\'ean\'\).*?->disabled\(\).*?->dehydrated\(false\)/s',
            $contents
        );
    }
}
