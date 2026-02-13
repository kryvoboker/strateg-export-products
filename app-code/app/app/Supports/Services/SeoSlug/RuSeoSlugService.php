<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

final class RuSeoSlugService
{
    public static function make(string $text, int $max_len = 0): string
    {
        $text = Str::trim($text);

        if ($text === '') {
            return '';
        }

        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'А' => 'a', 'Б' => 'b', 'В' => 'v',
            'Г' => 'g', 'Д' => 'd', 'Е' => 'e', 'Ё' => 'e', 'Ж' => 'zh', 'З' => 'z',
            'И' => 'i', 'Й' => 'y', 'К' => 'k', 'Л' => 'l', 'М' => 'm', 'Н' => 'n',
            'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
            'Ф' => 'f', 'Х' => 'kh', 'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch',
            'Ъ' => '', 'Ы' => 'y', 'Ь' => '', 'Э' => 'e', 'Ю' => 'yu', 'Я' => 'ya',
        ];

        $latin = Str::swap($map, $text);
        $latin = Str::lower($latin);
        $latin = Str::replaceMatches('~[^a-z0-9]+~u', '-', $latin) ?? '';
        $latin = Str::trim($latin, '-');
        $latin = Str::replaceMatches('~-{2,}~', '-', $latin) ?? '';

        if ($max_len > 0 && Str::length($latin) > $max_len) {
            $latin = Str::rtrim(Str::substr($latin, 0, $max_len), '-');
        }

        return $latin;
    }
}
