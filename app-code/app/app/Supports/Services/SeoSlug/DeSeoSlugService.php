<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use App\Supports\Services\SeoSlug\Concerns\HasCyrillicTransliterationMaps;
use App\Supports\Services\SeoSlug\Concerns\NormalizesSeoSlug;

final class DeSeoSlugService
{
    use HasCyrillicTransliterationMaps;
    use NormalizesSeoSlug;

    public static function make(string $text, int $max_len = 0): string
    {
        $german_transliteration_map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
            '’' => '', '\'' => '', 'ʼ' => '', '`' => '',
        ];

        return self::finalizeSeoSlug(
            $text,
            [...$german_transliteration_map, ...self::getUkrainianTransliterationMap(), ...self::getRussianTransliterationMap()],
            true,
            $max_len
        );
    }
}
