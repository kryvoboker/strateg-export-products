<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Product;

use App\Models\Shops\ShopLanguage;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Ai\AiTranslationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ProductImportPayloadTranslationService
{
    public function __construct(
        private readonly AiTranslationService $ai_translation_service,
        private readonly AiTranslationPromptBuilderService $ai_translation_prompt_builder_service,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function translatePayloadToAllLanguages(array $payload): array
    {
        $language_codes = $this->getActiveLanguageCodes();

        if ($language_codes === []) {
            return $payload;
        }

        $source_language_code = $this->resolveSourceLanguageCode($payload, $language_codes);
        $product_id_for_ai    = $this->resolveProductIdForAi($payload);

        $payload['descriptions'] = $this->translateDescriptionsToAllLanguages(
            payload: $payload,
            language_codes: $language_codes,
            source_language_code: $source_language_code,
            product_id_for_ai: $product_id_for_ai,
        );

        $payload['attributes'] = $this->translateAttributesToAllLanguages(
            payload: $payload,
            language_codes: $language_codes,
            source_language_code: $source_language_code,
            product_id_for_ai: $product_id_for_ai,
        );

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function getActiveLanguageCodes(): array
    {
        try {
            $codes = ShopLanguage::getActiveCodes();
        } catch (Throwable $exception) {
            Log::channel('stack')->warning('Failed to read active language codes', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        $normalized_codes = array_values(array_filter(
            array_map(fn ($code) => Str::lower(Str::trim((string) $code)), $codes),
            static fn ($code) => $code !== ''
        ));

        return array_values(array_unique($normalized_codes));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $language_codes
     */
    private function resolveSourceLanguageCode(array $payload, array $language_codes): string
    {
        $description_language_codes = [];

        foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
            if (! is_array($description_row)) {
                continue;
            }

            $code = Str::lower(Str::trim((string) Arr::get($description_row, 'shop_language_code', '')));
            if ($code !== '') {
                $description_language_codes[] = $code;
            }
        }

        if (in_array('uk', $description_language_codes, true)) {
            return 'uk';
        }

        if ($description_language_codes !== []) {
            return $description_language_codes[0];
        }

        if (in_array('uk', $language_codes, true)) {
            return 'uk';
        }

        return $language_codes[0] ?? 'uk';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $language_codes
     * @return list<array<string, mixed>>
     */
    private function translateDescriptionsToAllLanguages(
        array $payload,
        array $language_codes,
        string $source_language_code,
        int $product_id_for_ai
    ): array {
        $description_map = [];

        foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
            if (! is_array($description_row)) {
                continue;
            }

            $language_code = Str::lower(Str::trim((string) Arr::get($description_row, 'shop_language_code', '')));
            if ($language_code === '') {
                continue;
            }

            $description_map[$language_code] = $description_row;
        }

        $source_row = $description_map[$source_language_code] ?? Arr::first($description_map) ?? [];

        $source_name = Str::trim((string) Arr::get($source_row, 'name', ''));
        if ($source_name === '') {
            $source_name = $this->resolveBaseTextForSlug($payload);
        }

        $source_description      = Str::trim((string) Arr::get($source_row, 'description', ''));
        $source_meta_title       = Str::trim((string) Arr::get($source_row, 'meta_title', $source_name));
        $source_meta_description = Str::trim((string) Arr::get($source_row, 'meta_description', $source_description));
        $source_meta_keywords    = Str::trim((string) Arr::get($source_row, 'meta_keywords', ''));

        $translated_rows = [];

        foreach ($language_codes as $language_code) {
            $existing_row = $description_map[$language_code] ?? [];

            $row_name = Str::trim((string) Arr::get($existing_row, 'name', ''));
            if ($row_name === '') {
                $row_name = $this->translateProductNameForLanguage(
                    source_text: $source_name,
                    source_language_code: $source_language_code,
                    target_language_code: $language_code,
                    product_id_for_ai: $product_id_for_ai
                );
            }

            $row_description = Str::trim((string) Arr::get($existing_row, 'description', ''));
            if ($row_description === '' && $source_description !== '') {
                $row_description = $this->translateProductDescriptionForLanguage(
                    source_text: $source_description,
                    source_language_code: $source_language_code,
                    target_language_code: $language_code,
                    product_id_for_ai: $product_id_for_ai
                );
            }

            $row_meta_title = Str::trim((string) Arr::get($existing_row, 'meta_title', ''));
            if ($row_meta_title === '' && $source_meta_title !== '') {
                $row_meta_title = $this->translateProductNameForLanguage(
                    source_text: $source_meta_title,
                    source_language_code: $source_language_code,
                    target_language_code: $language_code,
                    product_id_for_ai: $product_id_for_ai
                );
            }

            $row_meta_description = Str::trim((string) Arr::get($existing_row, 'meta_description', ''));
            if ($row_meta_description === '' && $source_meta_description !== '') {
                $row_meta_description = $this->translateProductDescriptionForLanguage(
                    source_text: $source_meta_description,
                    source_language_code: $source_language_code,
                    target_language_code: $language_code,
                    product_id_for_ai: $product_id_for_ai
                );
            }

            $row_meta_keywords = Str::trim((string) Arr::get($existing_row, 'meta_keywords', ''));
            if ($row_meta_keywords === '' && $source_meta_keywords !== '') {
                $row_meta_keywords = $this->translateProductNameForLanguage(
                    source_text: $source_meta_keywords,
                    source_language_code: $source_language_code,
                    target_language_code: $language_code,
                    product_id_for_ai: $product_id_for_ai
                );
            }

            $translated_rows[] = [
                'product_id'         => Arr::get($payload, 'product.product_id'),
                'shop_language_code' => $language_code,
                'name'               => $row_name,
                'description'        => $row_description,
                'meta_title'         => $row_meta_title,
                'meta_description'   => $row_meta_description,
                'meta_keywords'      => $row_meta_keywords,
            ];
        }

        return $translated_rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $language_codes
     * @return list<array<string, mixed>>
     */
    private function translateAttributesToAllLanguages(
        array $payload,
        array $language_codes,
        string $source_language_code,
        int $product_id_for_ai
    ): array {
        $attribute_rows = array_values(array_filter(
            Arr::get($payload, 'attributes', []),
            static fn ($row) => is_array($row)
        ));

        if ($attribute_rows === []) {
            return [];
        }

        $by_attribute_name = [];

        foreach ($attribute_rows as $attribute_row) {
            $attribute_name = Str::trim((string) Arr::get($attribute_row, 'attribute_name', ''));
            if ($attribute_name === '') {
                continue;
            }

            $language_code = Str::lower(Str::trim((string) Arr::get($attribute_row, 'shop_language_code', '')));
            if ($language_code === '') {
                $language_code = $source_language_code;
            }

            $by_attribute_name[$attribute_name][$language_code] = $attribute_row;
        }

        $translated_rows = [];

        foreach ($by_attribute_name as $attribute_name => $rows_by_language) {
            $source_row          = $rows_by_language[$source_language_code] ?? Arr::first($rows_by_language) ?? [];
            $source_text         = Str::trim((string) Arr::get($source_row, 'attribute_text', Arr::get($source_row, 'text', '')));
            $attribute_id_for_ai = max(abs(crc32(Str::lower((string) $attribute_name))), 1);

            foreach ($language_codes as $language_code) {
                $existing_row = $rows_by_language[$language_code] ?? [];
                $row_text     = Str::trim((string) Arr::get($existing_row, 'attribute_text', Arr::get($existing_row, 'text', '')));

                if ($row_text === '' && $source_text !== '') {
                    $row_text = $this->translateProductAttributeTextForLanguage(
                        source_text: $source_text,
                        source_language_code: $source_language_code,
                        target_language_code: $language_code,
                        product_id_for_ai: $product_id_for_ai,
                        attribute_id_for_ai: $attribute_id_for_ai
                    );
                }

                $translated_rows[] = [
                    'product_id'         => Arr::get($payload, 'product.product_id'),
                    'attribute_name'     => $attribute_name,
                    'shop_language_code' => $language_code,
                    'attribute_text'     => $row_text,
                ];
            }
        }

        return $translated_rows;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProductIdForAi(array $payload): int
    {
        $product_id = Arr::get($payload, 'product.product_id');

        if (is_numeric($product_id)) {
            return max((int) $product_id, 1);
        }

        $sku   = (string) Arr::get($payload, 'product.sku', '');
        $model = (string) Arr::get($payload, 'product.model', '');
        $seed  = Str::trim($sku.'|'.$model.'|'.$product_id);

        if ($seed === '') {
            return 1;
        }

        return max(abs(crc32($seed)), 1);
    }

    private function translateProductNameForLanguage(
        string $source_text,
        string $source_language_code,
        string $target_language_code,
        int $product_id_for_ai
    ): string {
        $source_text = Str::trim($source_text);

        if ($source_text === '' || $source_language_code === $target_language_code) {
            return $source_text;
        }

        $prompt = $this->ai_translation_prompt_builder_service->buildTranslatePrompt(
            $source_text,
            $source_language_code,
            $target_language_code
        );

        return $this->translateWithAi('productName', $product_id_for_ai, null, $prompt, $source_text);
    }

    private function translateProductDescriptionForLanguage(
        string $source_text,
        string $source_language_code,
        string $target_language_code,
        int $product_id_for_ai
    ): string {
        $source_text = Str::trim($source_text);

        if ($source_text === '' || $source_language_code === $target_language_code) {
            return $source_text;
        }

        $prompt = $this->ai_translation_prompt_builder_service->buildTranslatePrompt(
            $source_text,
            $source_language_code,
            $target_language_code
        );

        return $this->translateWithAi('productDescription', $product_id_for_ai, null, $prompt, $source_text);
    }

    private function translateProductAttributeTextForLanguage(
        string $source_text,
        string $source_language_code,
        string $target_language_code,
        int $product_id_for_ai,
        int $attribute_id_for_ai
    ): string {
        $source_text = Str::trim($source_text);

        if ($source_text === '' || $source_language_code === $target_language_code) {
            return $source_text;
        }

        $prompt = $this->ai_translation_prompt_builder_service->buildTranslatePrompt(
            $source_text,
            $source_language_code,
            $target_language_code
        );

        return $this->translateWithAi('productAttributeText', $product_id_for_ai, $attribute_id_for_ai, $prompt, $source_text);
    }

    private function translateWithAi(
        string $method,
        int $product_id_for_ai,
        ?int $attribute_id_for_ai,
        string $prompt,
        string $fallback
    ): string {
        try {
            return match ($method) {
                'productName'          => $this->ai_translation_service->productName($product_id_for_ai, $prompt),
                'productDescription'   => $this->ai_translation_service->productDescription($product_id_for_ai, $prompt),
                'productAttributeText' => $this->ai_translation_service->productAttributeText($product_id_for_ai, (int) $attribute_id_for_ai, $prompt),
                default                => $fallback,
            };
        } catch (Throwable $exception) {
            Log::channel('stack')->warning('AI translation failed, fallback is used', [
                'method'              => $method,
                'product_id_for_ai'   => $product_id_for_ai,
                'attribute_id_for_ai' => $attribute_id_for_ai,
                'exception'           => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveBaseTextForSlug(array $payload): string
    {
        foreach (Arr::get($payload, 'descriptions', []) as $description_row) {
            if (! is_array($description_row)) {
                continue;
            }

            $name = Str::trim((string) Arr::get($description_row, 'name', ''));
            if ($name !== '') {
                return $name;
            }
        }

        $model = Str::trim((string) Arr::get($payload, 'product.model', ''));
        if ($model !== '') {
            return $model;
        }

        $sku = Str::trim((string) Arr::get($payload, 'product.sku', ''));
        if ($sku !== '') {
            return $sku;
        }

        return Str::trim((string) Arr::get($payload, 'product.product_id', 'product'));
    }
}
