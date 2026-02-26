<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Longman\TelegramBot\Entities\Document;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\Update;

if (! function_exists('clear_telephone')) {
    function clear_telephone(?string $telephone, bool $is_delete_first_nums = false): string
    {
        if (! isset($telephone)) {
            return '';
        }

        if ($is_delete_first_nums) {
            return (string) (preg_replace(['/\D+/', '/^38/'], '', $telephone) ?: $telephone);
        }

        return (string) (preg_replace('/\D+/', '', $telephone) ?: $telephone);
    }
}

if (! function_exists('parse_telephone')) {
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

if (! function_exists('trim_strs_in_arr')) {
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

if (! function_exists('get_telegram_photo')) {
    /**
     * @return array<PhotoSize>
     */
    function get_telegram_photo(Update $update): array
    {
        return get_telegram_message($update)->getPhoto();
    }
}

if (! function_exists('get_telegram_message')) {
    function get_telegram_message(Update $update): Message
    {
        return $update->getMessage() ?? $update->getEditedMessage();
    }
}

if (! function_exists('get_telegram_doc')) {
    function get_telegram_doc(Update $update): Document
    {
        return get_telegram_message($update)->getDocument();
    }
}

if (! function_exists('is_telegram_has_photo')) {
    function is_telegram_has_photo(Update $update): bool
    {
        return get_telegram_photo($update) !== [];
    }
}

if (! function_exists('is_telegram_has_doc')) {
    function is_telegram_has_doc(Update $update): bool
    {
        return trim((string) get_telegram_doc($update)->getFileId()) !== '';
    }
}

if (! function_exists('get_now_date')) {
    function get_now_date(?string $time_zone = null): Carbon|CarbonInterface
    {
        return now($time_zone ?: config('app.timezone'));
    }
}

if (! function_exists('validate_url')) {
    function validate_url(mixed $url): bool
    {
        if (is_string($url) === false) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (! function_exists('decode_html_entities')) {
    function decode_html_entities(?string $string): string
    {
        return html_entity_decode((string) $string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('normalize_str')) {
    /**
     * @param string|null $string
     * @param string|null $char_list
     *
     * @return string
     */
    function normalize_str(?string $string, ?string $char_list = null): string
    {
        if ($string === null) {
            return '';
        }

        $string = decode_html_entities(Str::trim($string, $char_list));

        return Str::replace(['&nbsp;', "\u{00a0}"], ' ', $string);
    }
}

if (! function_exists('normalize_positive_int_list')) {
    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    function normalize_positive_int_list(array $values, bool $is_sort = false): array
    {
        $normalized_values = collect($values)
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique();

        if ($is_sort) {
            $normalized_values = $normalized_values->sort();
        }

        return $normalized_values
            ->values()
            ->all();
    }
}

if (! function_exists('normalize_trimmed_str')) {
    function normalize_trimmed_str(mixed $value, ?string $char_list = null): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return normalize_str((string) $value, $char_list);
    }
}

if (! function_exists('normalize_nullable_trimmed_str')) {
    function normalize_nullable_trimmed_str(mixed $value, ?string $char_list = null): ?string
    {
        $normalized_value = normalize_trimmed_str($value, $char_list);

        return $normalized_value !== '' ? $normalized_value : null;
    }
}

if (! function_exists('normalize_array_payload')) {
    /**
     * @return array<string, mixed>
     */
    function normalize_array_payload(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
