<?php

declare(strict_types=1);

namespace App\Supports\Services\Ai;

final class AiTranslationPromptBuilderService
{
    public function buildTranslatePrompt(string $text, string $from_language_code, string $to_language_code): string
    {
        return trim(
            "Translate text from $from_language_code to $to_language_code. ".
            'Return only translated text without explanations or quotes.'.
            "\n\n$text"
        );
    }
}
