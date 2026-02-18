<?php

declare(strict_types=1);

namespace App\Abstratcts\Ai;

use App\Models\Products\ProductAttributeTextHash;
use App\Models\Products\ProductDescriptionHash;
use App\Models\Products\ProductNameHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use App\Supports\Services\Ai\AiPromptHasherService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

abstract class AiDbCachedTranslatorAbstract
{
    protected ?int           $product_id   = null;
    protected ?int           $attribute_id = null;
    protected ?int           $category_id = null;
    private ?ProductNameHash $product_name_hash;

    private ?ProductDescriptionHash $product_description_hash;

    private ?ProductAttributeTextHash $product_attribute_text_hash;

    public function __construct(
        protected OpenAiTranslatorService $ai,
    ) {}

    /**
     * @throws Throwable
     */
    public function translate(string $prompt): string
    {
        if (! (bool) config('app.ai_translation_enabled', true)) {
            return $this->buildFakeTranslationFromPrompt($prompt);
        }

        $normalized = AiPromptHasherService::normalize($prompt);
        $hash       = AiPromptHasherService::hash($normalized);

        $cached = $this->findCached($hash);

        if ($cached !== null) {
            return $cached;
        }

        $translated = $this->ai->translate($prompt);

        DB::transaction(function () use ($hash, $prompt, $translated) {
            // re-check in case of race
            $cached_again = $this->findCached($hash);

            if ($cached_again === null) {
                $this->storeTranslation($hash, $prompt, $translated);
            }
        });

        return $translated;
    }

    private function buildFakeTranslationFromPrompt(string $prompt): string
    {
        preg_match('/ to ([a-z]{2})\\./i', $prompt, $target_matches);
        $target_language_code = Str::lower((string) ($target_matches[1] ?? 'uk'));

        $source_text = (string) preg_replace('/^.*\\n\\n/s', '', $prompt);
        $source_text = Str::trim($source_text);

        if ($source_text === '') {
            $source_text = Str::trim($prompt);
        }

        return sprintf('translated-to-%s-%s', $target_language_code, $source_text);
    }

    /**
     * @param string $hash
     *
     * @return string|null
     */
    abstract protected function findCached(string $hash): ?string;

    /**
     * @param string $hash
     * @param string $prompt
     * @param string $translated_text
     *
     * @return Model
     */
    abstract protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model;

    /**
     * @return ProductNameHash|null
     */
    public function getProductNameHash(): ?ProductNameHash
    {
        return $this->product_name_hash;
    }

    /**
     * @return $this
     */
    public function setProductNameHash(?ProductNameHash $product_name_hash): static
    {
        $this->product_name_hash = $product_name_hash;

        return $this;
    }

    /**
     * @return ProductDescriptionHash|null
     */
    public function getProductDescriptionHash(): ?ProductDescriptionHash
    {
        return $this->product_description_hash;
    }

    /**
     * @return $this
     */
    public function setProductDescriptionHash(?ProductDescriptionHash $product_description_hash): static
    {
        $this->product_description_hash = $product_description_hash;

        return $this;
    }

    public function getProductAttributeTextHash(): ?ProductAttributeTextHash
    {
        return $this->product_attribute_text_hash;
    }

    /**
     * @return $this
     */
    public function setProductAttributeTextHash(?ProductAttributeTextHash $product_attribute_text_hash): static
    {
        $this->product_attribute_text_hash = $product_attribute_text_hash;

        return $this;
    }

    /**
     * @param int|null $product_id
     *
     * @return AiDbCachedTranslatorAbstract
     */
    public function setProductId(?int $product_id): static
    {
        $this->product_id = $product_id;

        return $this;
    }

    /**
     * @param int|null $attribute_id
     *
     * @return $this
     */
    public function setAttributeId(?int $attribute_id): static
    {
        $this->attribute_id = $attribute_id;

        return $this;
    }

    /**
     * @param int|null $category_id
     *
     * @return $this
     */
    public function setCategoryId(?int $category_id): static
    {
        $this->category_id = $category_id;

        return $this;
    }
}
