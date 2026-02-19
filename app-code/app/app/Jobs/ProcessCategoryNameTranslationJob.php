<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Categories\CategoryDescription;
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

class ProcessCategoryNameTranslationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<int>  $shop_ids
     */
    public function __construct(
        public int $category_id,
        public array $shop_ids = [],
    ) {}

    public function handle(
        AiTranslationService $ai_translation_service,
        AiTranslationPromptBuilderService $prompt_builder_service,
    ): void {
        if ($this->category_id <= 0) {
            return;
        }

        $source_description = CategoryDescription::findSourceForCategory($this->category_id, 0);
        if (! $source_description instanceof CategoryDescription) {
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

            $existing_row = CategoryDescription::query()
                ->where('category_id', $this->category_id)
                ->where('shop_language_id', $shop_language_id)
                ->first();

            $existing_name = Str::trim((string) ($existing_row?->name ?? ''));
            if ($existing_name !== '') {
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

                    $translated_name = Str::trim((string) $ai_translation_service->categoryName($this->category_id, $prompt));
                    if ($translated_name === '') {
                        $translated_name = $source_name;
                    }
                }

                CategoryDescription::upsertByCategoryAndLanguage(
                    $this->category_id,
                    $shop_language_id,
                    [
                        'name'             => $translated_name,
                        'description'      => $existing_row?->description,
                        'h1_title'         => $existing_row?->h1_title ?: $translated_name,
                        'meta_title'       => $existing_row?->meta_title ?: $translated_name,
                        'meta_description' => $existing_row?->meta_description,
                        'meta_keywords'    => $existing_row?->meta_keywords,
                    ]
                );
            } catch (Throwable $exception) {
                Log::channel('stack')->warning('Category name translation failed', [
                    'category_id'          => $this->category_id,
                    'shop_language_id'     => $shop_language_id,
                    'source_language_code' => $source_language_code,
                    'target_language_code' => $target_language_code,
                    'message'              => $exception->getMessage(),
                ]);
            }
        }
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
