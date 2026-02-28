<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use App\Supports\Services\SeoSlug\Concerns\HasCyrillicTransliterationMaps;
use App\Supports\Services\SeoSlug\Concerns\NormalizesSeoSlug;

/**
 * UA SEO slug generator (ukrainian → latin, kebab-case)
 *
 * Example:
 *  "Футболка червона з білими кружечками"
 *    -> "futbolka-chervona-z-bilymy-kruzhechkamy"
 */
final class UaSeoSlugService
{
    use HasCyrillicTransliterationMaps;
    use NormalizesSeoSlug;

    /**
     * @param  string  $text  Source text (UA/RU/EN mixed ok)
     * @param  int  $max_len  Optional max length (0 = no limit)
     */
    public static function make(string $text, int $max_len = 0): string
    {
        return self::finalizeSeoSlug(
            $text,
            self::getUkrainianTransliterationMap(),
            false,
            $max_len
        );
    }
}
