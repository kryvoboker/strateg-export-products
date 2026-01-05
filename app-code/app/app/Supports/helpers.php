<?php

declare(strict_types=1);

use Longman\TelegramBot\Entities\Document;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\PhotoSize;
use Longman\TelegramBot\Entities\Update;

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

if (!function_exists('get_telegram_photo')) {
    /**
     * @param Update $update
     *
     * @return array<PhotoSize>|null
     */
    function get_telegram_photo(Update $update): ?array
    {
        return ($update->getMessage()?->getPhoto() ?? $update->getEditedMessage()?->getPhoto());
    }
}

if (!function_exists('get_telegram_message')) {
    /**
     * @param Update $update
     *
     * @return Message
     */
    function get_telegram_message(Update $update): Message
    {
        return ($update->getMessage() ?? $update->getEditedMessage());
    }
}

if (!function_exists('get_telegram_doc')) {
    /**
     * @param Update $update
     *
     * @return Document|null
     */
    function get_telegram_doc(Update $update): ?Document
    {
        return ($update->getMessage()?->getDocument() ?? $update->getEditedMessage()?->getDocument());
    }
}

if (!function_exists('is_telegram_has_photo')) {
    /**
     * @param Update $update
     *
     * @return bool
     */
    function is_telegram_has_photo(Update $update): bool
    {
        return ($update->getMessage()?->getPhoto() ?? $update->getEditedMessage()?->getPhoto()) !== null;
    }
}

if (!function_exists('is_telegram_has_doc')) {
    /**
     * @param Update $update
     *
     * @return bool
     */
    function is_telegram_has_doc(Update $update): bool
    {
        return ($update->getMessage()?->getDocument() ?? $update->getEditedMessage()?->getDocument()) !== null;
    }
}
