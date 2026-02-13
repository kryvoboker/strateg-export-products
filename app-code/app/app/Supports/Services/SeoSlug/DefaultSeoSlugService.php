<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

final class DefaultSeoSlugService
{
    public static function make(string $text, int $max_len = 0): string
    {
        $text = Str::trim($text);

        if ($text === '') {
            return '';
        }

        $latin = Str::lower(Str::ascii($text));
        $latin = Str::replaceMatches('~[^a-z0-9]+~', '-', $latin) ?? '';
        $latin = Str::trim($latin, '-');
        $latin = Str::replaceMatches('~-{2,}~', '-', $latin) ?? '';

        if ($max_len > 0 && Str::length($latin) > $max_len) {
            $latin = Str::rtrim(Str::substr($latin, 0, $max_len), '-');
        }

        return $latin;
    }
}
