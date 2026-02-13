<?php

declare(strict_types=1);

namespace App\Supports\Services\Ai;

use Illuminate\Support\Str;

final class AiPromptHasherService
{
    public static function normalize(string $prompt): string
    {
        $prompt = Str::trim($prompt);

        return Str::lower($prompt);
    }

    public static function hash(string $prompt): string
    {
        return hash('sha256', self::normalize($prompt));
    }
}
