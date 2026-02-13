<?php

declare(strict_types=1);

namespace App\Supports\Services\SeoSlug;

use Illuminate\Support\Str;

/**
 * UA SEO slug generator (ukrainian → latin, kebab-case)
 *
 * Example:
 *  "Футболка червона з білими кружечками"
 *    -> "futbolka-chervona-z-bilymy-kruzhechkamy"
 */
final class UaSeoSlugService
{
    /**
     * @param  string  $text  Source text (UA/RU/EN mixed ok)
     * @param  int  $max_len  Optional max length (0 = no limit)
     */
    public static function make(string $text, int $max_len = 0): string
    {
        $text = Str::trim($text);

        if ($text === '') {
            return '';
        }

        // Ukrainian-focused transliteration (DSTU-like, simplified for slugs)
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd',
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

        // Lowercase (safe for UTF-8)
        $latin = Str::lower($latin);

        // Replace any non [a-z0-9] with hyphen
        $latin = Str::replaceMatches('~[^a-z0-9]+~u', '-', $latin) ?? '';
        $latin = Str::trim($latin, '-');

        // Collapse multiple hyphens
        $latin = Str::replaceMatches('~-{2,}~', '-', $latin) ?? '';

        if ($max_len > 0 && Str::length($latin) > $max_len) {
            $latin = Str::rtrim(Str::substr($latin, 0, $max_len), '-');
        }

        return $latin;
    }
}
