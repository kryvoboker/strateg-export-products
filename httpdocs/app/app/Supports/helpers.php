<?php

declare(strict_types=1);

if (!function_exists('clear_telephone')) {
    /**
     * @param string|null $telephone
     * @param bool        $is_delete_first_nums
     *
     * @return string
     */
    function clear_telephone(?string $telephone, bool $is_delete_first_nums = false): string
    {
        if (!isset($telephone)) {
            return '';
        }

        if ($is_delete_first_nums) {
            return (string)(preg_replace(['/\D+/', '/^38/'], '', $telephone) ?: $telephone);
        }

        return (string)(preg_replace('/\D+/', '', $telephone) ?: $telephone);
    }
}

if (!function_exists('parse_telephone')) {
    /**
     * @param string $telephone
     *
     * @return string
     */
    function parse_telephone(string $telephone): string
    {
        $telephone = clear_telephone($telephone, true);

        $mask         = '+38 (___) ___-__-__';
        $phone_length = \Illuminate\Support\Str::length($telephone);

        for ($index_number = 0; $index_number < $phone_length; $index_number++) {
            $mask = preg_replace('/_/', $telephone[$index_number], $mask, 1);
        }

        return $mask;
    }
}

if (!function_exists('trim_strs_in_arr')) {
    /**
     * @param array $arr
     *
     * @return array
     */
    function trim_strs_in_arr(array $arr): array
    {
        return array_map(function ($item) {
            if (is_string($item)) {
                return trim($item);
            }

            return $item;
        }, $arr);
    }
}
