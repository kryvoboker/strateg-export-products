<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug\Concerns;

use Illuminate\Support\Str;

trait NormalizesSeoSlug
{
    protected static function normalizeSeoSlug(string $raw_slug, int $max_length = 0): string
    {
        $normalized_slug = Str::lower(Str::trim($raw_slug));
        $normalized_slug = Str::replaceMatches('~[^a-z0-9]+~u', '-', $normalized_slug) ?? '';
        $normalized_slug = Str::replaceMatches('~-{2,}~', '-', $normalized_slug) ?? '';
        $normalized_slug = Str::trim($normalized_slug, '-');

        if ($max_length > 0 && Str::length($normalized_slug) > $max_length) {
            $normalized_slug = Str::rtrim(Str::substr($normalized_slug, 0, $max_length), '-');
        }

        return $normalized_slug;
    }

    protected static function finalizeSeoSlug(
        string $text,
        array $transliteration_map = [],
        bool $is_ascii_normalization = false,
        int $max_length = 0
    ): string {
        $clean_text = Str::trim($text);
        if ($clean_text === '') {
            return '';
        }

        $slug_source = $transliteration_map !== []
            ? Str::swap($transliteration_map, $clean_text)
            : $clean_text;

        if ($is_ascii_normalization) {
            $slug_source = Str::ascii($slug_source);
        }

        return self::normalizeSeoSlug($slug_source, $max_length);
    }
}
