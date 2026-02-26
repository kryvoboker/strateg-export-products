<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Supports\Services\Ai\AiTranslationService;
use App\Supports\Services\Translations\Attribute\AttributeDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Attribute\AttributeNameAiTranslatorService;
use App\Supports\Services\Translations\Brand\BrandDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Brand\BrandNameAiTranslatorService;
use App\Supports\Services\Translations\Category\CategoryDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Category\CategoryNameAiTranslatorService;
use App\Supports\Services\Translations\Manufacturer\ManufacturerDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Manufacturer\ManufacturerNameAiTranslatorService;
use App\Supports\Services\Translations\Product\ProductAttributeTextAiTranslatorService;
use App\Supports\Services\Translations\Product\ProductDescriptionAiTranslatorService;
use App\Supports\Services\Translations\Product\ProductNameAiTranslatorService;
use PHPUnit\Framework\TestCase;

class AiTranslationServiceRoutingTest extends TestCase
{
    public function test_it_routes_new_entity_translation_calls_to_expected_translators(): void
    {
        $category_name_translator = $this->createMock(CategoryNameAiTranslatorService::class);
        $category_name_translator->method('setCategoryId')->willReturnSelf();
        $category_name_translator->method('translate')->willReturn('ok-category-name');

        $category_description_translator = $this->createMock(CategoryDescriptionAiTranslatorService::class);
        $category_description_translator->expects(self::once())
            ->method('setCategoryId')
            ->with(21)
            ->willReturnSelf();
        $category_description_translator->expects(self::once())
            ->method('translate')
            ->with('p2')
            ->willReturn('ok-category-description');

        $attribute_name_translator = $this->createMock(AttributeNameAiTranslatorService::class);
        $attribute_name_translator->method('setAttributeId')->willReturnSelf();
        $attribute_name_translator->method('translate')->willReturn('ok-attribute-name');

        $attribute_description_translator = $this->createMock(AttributeDescriptionAiTranslatorService::class);
        $attribute_description_translator->expects(self::once())
            ->method('setAttributeId')
            ->with(22)
            ->willReturnSelf();
        $attribute_description_translator->expects(self::once())
            ->method('translate')
            ->with('p1')
            ->willReturn('ok-attribute-description');

        $brand_name_translator = $this->createMock(BrandNameAiTranslatorService::class);
        $brand_name_translator->expects(self::once())
            ->method('setBrandId')
            ->with(23)
            ->willReturnSelf();
        $brand_name_translator->expects(self::once())
            ->method('translate')
            ->with('p3')
            ->willReturn('ok-brand-name');

        $brand_description_translator = $this->createMock(BrandDescriptionAiTranslatorService::class);
        $brand_description_translator->expects(self::once())
            ->method('setBrandId')
            ->with(24)
            ->willReturnSelf();
        $brand_description_translator->expects(self::once())
            ->method('translate')
            ->with('p4')
            ->willReturn('ok-brand-description');

        $manufacturer_name_translator = $this->createMock(ManufacturerNameAiTranslatorService::class);
        $manufacturer_name_translator->expects(self::once())
            ->method('setManufacturerId')
            ->with(25)
            ->willReturnSelf();
        $manufacturer_name_translator->expects(self::once())
            ->method('translate')
            ->with('p5')
            ->willReturn('ok-manufacturer-name');

        $manufacturer_description_translator = $this->createMock(ManufacturerDescriptionAiTranslatorService::class);
        $manufacturer_description_translator->expects(self::once())
            ->method('setManufacturerId')
            ->with(26)
            ->willReturnSelf();
        $manufacturer_description_translator->expects(self::once())
            ->method('translate')
            ->with('p6')
            ->willReturn('ok-manufacturer-description');

        $product_name_translator = $this->createMock(ProductNameAiTranslatorService::class);
        $product_name_translator->method('setProductId')->willReturnSelf();
        $product_name_translator->method('translate')->willReturn('ok-product-name');

        $product_description_translator = $this->createMock(ProductDescriptionAiTranslatorService::class);
        $product_description_translator->method('setProductId')->willReturnSelf();
        $product_description_translator->method('translate')->willReturn('ok-product-description');

        $product_attribute_text_translator = $this->createMock(ProductAttributeTextAiTranslatorService::class);
        $product_attribute_text_translator->method('setProductId')->willReturnSelf();
        $product_attribute_text_translator->method('setAttributeId')->willReturnSelf();
        $product_attribute_text_translator->method('translate')->willReturn('ok-product-attribute-text');

        $service = new AiTranslationService(
            $category_name_translator,
            $category_description_translator,
            $attribute_name_translator,
            $attribute_description_translator,
            $brand_name_translator,
            $brand_description_translator,
            $manufacturer_name_translator,
            $manufacturer_description_translator,
            $product_name_translator,
            $product_description_translator,
            $product_attribute_text_translator,
        );

        self::assertSame('ok-attribute-description', $service->attributeDescription(22, 'p1'));
        self::assertSame('ok-category-description', $service->categoryDescription(21, 'p2'));
        self::assertSame('ok-brand-name', $service->brandName(23, 'p3'));
        self::assertSame('ok-brand-description', $service->brandDescription(24, 'p4'));
        self::assertSame('ok-manufacturer-name', $service->manufacturerName(25, 'p5'));
        self::assertSame('ok-manufacturer-description', $service->manufacturerDescription(26, 'p6'));
    }
}
