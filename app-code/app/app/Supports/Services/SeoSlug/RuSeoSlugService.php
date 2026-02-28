<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use App\Supports\Services\SeoSlug\Concerns\HasCyrillicTransliterationMaps;
use App\Supports\Services\SeoSlug\Concerns\NormalizesSeoSlug;

final class RuSeoSlugService
{
    use HasCyrillicTransliterationMaps;
    use NormalizesSeoSlug;

    public static function make(string $text, int $max_len = 0): string
    {
        return self::finalizeSeoSlug(
            $text,
            self::getRussianTransliterationMap(),
            false,
            $max_len
        );
    }
}
