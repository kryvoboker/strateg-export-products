<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use App\Supports\Services\SeoSlug\Concerns\HasCyrillicTransliterationMaps;
use App\Supports\Services\SeoSlug\Concerns\NormalizesSeoSlug;

/**
 * EN SEO slug generator (latin → latin, kebab-case)
 *
 * Example:
 *  "T-shirt chervona with white circles"
 *    -> "t-shirt-chervona-with-white-circles"
 */
final class EnSeoSlugService
{
    use HasCyrillicTransliterationMaps;
    use NormalizesSeoSlug;

    /**
     * @param  string  $text  Source text (EN mixed ok)
     * @param  int  $max_len  Optional max length (0 = no limit)
     */
    public static function make(string $text, int $max_len = 0): string
    {
        return self::finalizeSeoSlug(
            $text,
            [...self::getUkrainianTransliterationMap(), ...self::getRussianTransliterationMap()],
            false,
            $max_len
        );
    }
}
