<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attributes\AttributeDescription;
use App\Models\Shops\ShopLanguage;
use App\Supports\Services\Ai\AiTranslationPromptBuilderService;
use App\Supports\Services\Ai\AiTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessAttributeNameTranslationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $shop_ids
     */
    public function __construct(
        public int $attribute_id,
        public array $shop_ids = [],
    ) {}

    public function handle(
        AiTranslationService $ai_translation_service,
        AiTranslationPromptBuilderService $prompt_builder_service,
    ): void {
        if ($this->attribute_id <= 0) {
            return;
        }

        $source_description = $this->resolveSourceDescription($this->attribute_id);
        if (! $source_description instanceof AttributeDescription) {
            return;
        }

        $source_name = Str::trim((string) ($source_description->name ?? ''));
        if ($source_name === '') {
            return;
        }

        $source_language_code = $this->resolveLanguageCode((int) ($source_description->shop_language_id ?? 0), 'uk');
        $target_languages     = $this->resolveTargetLanguages($this->shop_ids);

        foreach ($target_languages as $target_language) {
            $shop_language_id     = (int) ($target_language->id ?? 0);
            $target_language_code = $this->resolveLanguageCode($shop_language_id, '');
            if ($shop_language_id <= 0 || $target_language_code === '') {
                continue;
            }

            try {
                $translated_name = $source_name;

                if ($source_language_code !== $target_language_code) {
                    $prompt = $prompt_builder_service->buildTranslatePrompt(
                        $source_name,
                        $source_language_code,
                        $target_language_code
                    );

                    $translated_name = Str::trim((string) $ai_translation_service->attributeName($this->attribute_id, $prompt));
                    if ($translated_name === '') {
                        $translated_name = $source_name;
                    }
                }

                AttributeDescription::upsertName(
                    $this->attribute_id,
                    $shop_language_id,
                    $translated_name
                );
            } catch (Throwable $exception) {
                Log::channel('stack')->warning('Attribute name translation failed', [
                    'attribute_id'         => $this->attribute_id,
                    'shop_language_id'     => $shop_language_id,
                    'source_language_code' => $source_language_code,
                    'target_language_code' => $target_language_code,
                    'message'              => $exception->getMessage(),
                ]);
            }
        }
    }

    private function resolveSourceDescription(int $attribute_id): ?AttributeDescription
    {
        $source_description = AttributeDescription::query()
            ->where('attribute_id', $attribute_id)
            ->whereNull('shop_language_id')
            ->first();

        if ($source_description instanceof AttributeDescription) {
            return $source_description;
        }

        return AttributeDescription::query()
            ->where('attribute_id', $attribute_id)
            ->orderByRaw('shop_language_id IS NULL DESC')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $shop_ids
     * @return Collection<int, ShopLanguage>
     */
    private function resolveTargetLanguages(array $shop_ids): Collection
    {
        $normalized_shop_ids = collect($shop_ids)
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalized_shop_ids === []) {
            return collect();
        }

        return ShopLanguage::query()
            ->where('is_active', true)
            ->whereIn('shop_id', $normalized_shop_ids)
            ->orderBy('shop_id')
            ->orderBy('id')
            ->get(['id', 'shop_id', 'code']);
    }

    private function resolveLanguageCode(int $shop_language_id, string $fallback_language_code): string
    {
        $language_code = $shop_language_id > 0
            ? ShopLanguage::getCodeById($shop_language_id)
            : '';

        $language_code = Str::lower(Str::trim($language_code));
        if ($language_code === 'ua') {
            $language_code = 'uk';
        }

        if ($language_code !== '') {
            return $language_code;
        }

        $fallback_language_code = Str::lower(Str::trim($fallback_language_code));
        if ($fallback_language_code === 'ua') {
            $fallback_language_code = 'uk';
        }

        return $fallback_language_code;
    }
}
