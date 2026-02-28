<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

final class ProductSeoKeywordService
{
    public function make(string $base_text, string $language_code): string
    {
        $normalized_language_code = $this->normalizeLanguageCode($language_code);
        $base_slug                = $this->buildSeoBaseSlug($base_text, $normalized_language_code);

        if ($base_slug === '') {
            return '';
        }

        if ($normalized_language_code === 'uk') {
            return $base_slug;
        }

        if ($normalized_language_code === '') {
            return $base_slug;
        }

        return DefaultSeoSlugService::make($base_slug.'-'.$normalized_language_code);
    }

    private function buildSeoBaseSlug(string $base_text, string $language_code): string
    {
        return match ($language_code) {
            'uk'    => UaSeoSlugService::make($base_text),
            'en'    => EnSeoSlugService::make($base_text),
            'ru'    => RuSeoSlugService::make($base_text),
            'de'    => DeSeoSlugService::make($base_text),
            default => DefaultSeoSlugService::make($base_text),
        };
    }

    private function normalizeLanguageCode(string $language_code): string
    {
        $normalized_language_code = Str::lower(Str::trim($language_code));

        return $normalized_language_code === 'ua' ? 'uk' : $normalized_language_code;
    }
}
