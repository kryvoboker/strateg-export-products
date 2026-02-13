<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

/**
 * EN SEO slug generator (latin → latin, kebab-case)
 *
 * Example:
 *  "T-shirt chervona with white circles"
 *    -> "t-shirt-chervona-with-white-circles"
 */
final class EnSeoSlugService
{
    /**
     * @param  string  $text  Source text (EN mixed ok)
     * @param  int  $max_len  Optional max length (0 = no limit)
     */
    public static function make(string $text, int $max_len = 0): string
    {
        $text = Str::trim($text);

        if ($text === '') {
            return '';
        }

        // Lowercase (safe for UTF-8)
        $latin = Str::lower($text);

        // Replace any non [a-z0-9] with hyphen
        $latin = Str::replaceMatches('~[^a-z0-9]+~', '-', $latin) ?? '';
        $latin = Str::trim($latin, '-');

        // Collapse multiple hyphens
        $latin = Str::replaceMatches('~-{2,}~', '-', $latin) ?? '';

        if ($max_len > 0 && Str::length($latin) > $max_len) {
            $latin = Str::rtrim(Str::substr($latin, 0, $max_len), '-');
        }

        return $latin;
    }
}
