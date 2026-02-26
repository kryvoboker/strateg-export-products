<?php

declare(strict_types=1);

namespace App\Supports\Services\Ai;

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
use Throwable;

final readonly class AiTranslationService
{
    public function __construct(
        private CategoryNameAiTranslatorService $category_name_ai_translator_service,
        private CategoryDescriptionAiTranslatorService $category_description_ai_translator_service,
        private AttributeNameAiTranslatorService $attribute_name_ai_translator_service,
        private AttributeDescriptionAiTranslatorService $attribute_description_ai_translator_service,
        private BrandNameAiTranslatorService $brand_name_ai_translator_service,
        private BrandDescriptionAiTranslatorService $brand_description_ai_translator_service,
        private ManufacturerNameAiTranslatorService $manufacturer_name_ai_translator_service,
        private ManufacturerDescriptionAiTranslatorService $manufacturer_description_ai_translator_service,
        private ProductNameAiTranslatorService $product_name_ai_translator_service,
        private ProductDescriptionAiTranslatorService $product_description_ai_translator_service,
        private ProductAttributeTextAiTranslatorService $product_attribute_text_ai_translator_service,
    ) {}

    /**
     * @throws Throwable
     */
    public function attributeName(int $attribute_id, string $prompt): string
    {
        return $this->attribute_name_ai_translator_service
            ->setAttributeId($attribute_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function categoryName(int $category_id, string $prompt): string
    {
        return $this->category_name_ai_translator_service
            ->setCategoryId($category_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function attributeDescription(int $attribute_id, string $prompt): string
    {
        return $this->attribute_description_ai_translator_service
            ->setAttributeId($attribute_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function categoryDescription(int $category_id, string $prompt): string
    {
        return $this->category_description_ai_translator_service
            ->setCategoryId($category_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function brandName(int $brand_id, string $prompt): string
    {
        return $this->brand_name_ai_translator_service
            ->setBrandId($brand_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function brandDescription(int $brand_id, string $prompt): string
    {
        return $this->brand_description_ai_translator_service
            ->setBrandId($brand_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function manufacturerName(int $manufacturer_id, string $prompt): string
    {
        return $this->manufacturer_name_ai_translator_service
            ->setManufacturerId($manufacturer_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function manufacturerDescription(int $manufacturer_id, string $prompt): string
    {
        return $this->manufacturer_description_ai_translator_service
            ->setManufacturerId($manufacturer_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function productName(int $product_id, string $prompt): string
    {
        //        $service = app(ProductNameAiTranslatorService::class);

        return $this->product_name_ai_translator_service
            ->setProductId($product_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function productDescription(int $product_id, string $prompt): string
    {
        //        $service = app(ProductDescriptionAiTranslatorService::class);

        return $this->product_description_ai_translator_service
            ->setProductId($product_id)
            ->translate($prompt);
    }

    /**
     * @throws Throwable
     */
    public function productAttributeText(int $product_id, int $attribute_id, string $prompt): string
    {
        //        $service = app(ProductAttributeTextAiTranslatorService::class);

        return $this->product_attribute_text_ai_translator_service
            ->setProductId($product_id)
            ->setAttributeId($attribute_id)
            ->translate($prompt);
    }
}
