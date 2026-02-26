<?php

declare(strict_types=1);

namespace App\Abstratcts\Ai;

use App\Models\Ai\AiTranslationCache;
use App\Services\Api\Ai\OpenAiTranslatorService;
use App\Supports\Services\Ai\AiPromptHasherService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

abstract class AiDbCachedTranslatorAbstract
{
    protected ?int $product_id = null;

    protected ?int $attribute_id = null;

    protected ?int $category_id = null;

    protected ?int $brand_id = null;

    protected ?int $manufacturer_id = null;

    public function __construct(
        protected OpenAiTranslatorService $ai,
    ) {}

    /**
     * @throws Throwable
     */
    public function translate(string $prompt): string
    {
        $normalized_prompt = AiPromptHasherService::normalize($prompt);
        $hash              = AiPromptHasherService::hash($normalized_prompt);

        $cached = $this->findCached($hash);

        if ($cached !== null) {
            return $cached;
        }

        $translated_text = config('app.ai_translation_enabled', true)
            ? $this->ai->translate($prompt)
            : $this->buildFakeTranslationFromPrompt($prompt);

        DB::transaction(function () use ($hash, $prompt, $translated_text): void {
            $cached_again = $this->findCached($hash);

            if ($cached_again === null) {
                $this->storeTranslation($hash, $prompt, $translated_text);
            }
        });

        return $translated_text;
    }

    private function buildFakeTranslationFromPrompt(string $prompt): string
    {
        preg_match('/ to ([a-z]{2})\./i', $prompt, $target_matches);
        $target_language_code = Str::lower($target_matches[1] ?? 'uk');

        $source_text = (string) preg_replace('/^.*\n\n/s', '', $prompt);
        $source_text = Str::trim($source_text);

        if ($source_text === '') {
            $source_text = Str::trim($prompt);
        }

        return sprintf('[%s] %s', Str::upper($target_language_code), $source_text);
    }

    protected function findCached(string $hash): ?string
    {
        $scope_id = $this->resolveScopeId();

        if ($scope_id <= 0) {
            return null;
        }

        $scope_type = $this->resolveScopeType();

        return AiTranslationCache::query()
            ->where('translatable_type', $scope_type)
            ->where('translatable_id', $scope_id)
            ->where('hash', $hash)
            ->value('answer');
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $scope_id = $this->resolveScopeId();

        if ($scope_id <= 0) {
            throw new RuntimeException('Translation scope id must be positive for cache persistence.');
        }

        $scope_type = $this->resolveScopeType();

        return AiTranslationCache::query()->updateOrCreate(
            [
                'translatable_type' => $scope_type,
                'translatable_id'   => $scope_id,
                'hash'              => $hash,
            ],
            [
                'prompt' => $prompt,
                'answer' => $translated_text,
            ],
        );
    }

    /**
     * @return class-string<Model>
     */
    abstract protected function resolveScopeType(): string;

    abstract protected function resolveScopeId(): int;

    public function setProductId(?int $product_id): static
    {
        $this->product_id = $product_id;

        return $this;
    }

    /**
     * @return $this
     */
    public function setAttributeId(?int $attribute_id): static
    {
        $this->attribute_id = $attribute_id;

        return $this;
    }

    /**
     * @return $this
     */
    public function setCategoryId(?int $category_id): static
    {
        $this->category_id = $category_id;

        return $this;
    }

    /**
     * @return $this
     */
    public function setBrandId(?int $brand_id): static
    {
        $this->brand_id = $brand_id;

        return $this;
    }

    /**
     * @return $this
     */
    public function setManufacturerId(?int $manufacturer_id): static
    {
        $this->manufacturer_id = $manufacturer_id;

        return $this;
    }
}
