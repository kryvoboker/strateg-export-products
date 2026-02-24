<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

final class DeSeoSlugService
{
    public static function make(string $text, int $max_len = 0): string
    {
        $text = Str::trim($text);

        if ($text === '') {
            return '';
        }

        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'а' => 'a', 'б' => 'b', 'в' => 'v',
            'г' => 'h', 'ґ' => 'g', 'д' => 'd',
            'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i',
            'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ь' => '', 'ю' => 'iu', 'я' => 'ia', 'А' => 'a', 'Б' => 'b', 'В' => 'v',
            'Г' => 'h', 'Ґ' => 'g', 'Д' => 'd', 'Е' => 'e', 'Є' => 'ie', 'Ж' => 'zh',
            'З' => 'z', 'И' => 'y', 'І' => 'i', 'Ї' => 'i', 'Й' => 'i', 'К' => 'k',
            'Л' => 'l', 'М' => 'm', 'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r',
            'С' => 's', 'Т' => 't', 'У' => 'u', 'Ф' => 'f', 'Х' => 'kh', 'Ц' => 'ts',
            'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch', 'Ь' => '', 'Ю' => 'iu', 'Я' => 'ia',
            '’' => '', '\'' => '', 'ʼ' => '', '`' => '',
        ];

        $latin = Str::swap($map, $text);
        $latin = Str::lower(Str::ascii($latin));
        $latin = Str::replaceMatches('~[^a-z0-9]+~', '-', $latin) ?? '';
        $latin = Str::trim($latin, '-');
        $latin = Str::replaceMatches('~-{2,}~', '-', $latin) ?? '';

        if ($max_len > 0 && Str::length($latin) > $max_len) {
            $latin = Str::rtrim(Str::substr($latin, 0, $max_len), '-');
        }

        return $latin;
    }
}
