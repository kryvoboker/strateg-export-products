<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;

trait CommonTextFormTrait
{
    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getTextFormField(array $params = []): Field
    {
        $max_length = self::processGetMaxLength($params);

        $text_input = TextInput::make($params['field_name'])
            ->label($params['label'])
            ->helperText($params['helper_text'] ?? null)
            ->maxLength($max_length)
            ->placeholder($params['placeholder'] ?? null)
            ->rules($params['rules'] ?? ['string', "max:$max_length"])
            ->default($params['default'] ?? null)
            ->required($params['required'] ?? true);

        if (isset($params['unique']['ignore_record'])) {
            $text_input->unique(ignoreRecord: $params['unique']['ignore_record']);
        }

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $text_input->columnSpanFull();
        }

        return $text_input;
    }

    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getEmailFormField(array $params = []): Field
    {
        $max_length = self::processGetMaxLength($params);

        $text_input = TextInput::make($params['field_name'] ?? 'email')
            ->label($params['label'] ?? __('admin/default.labels.email'))
            ->helperText($params['helper_text'] ?? null)
            ->maxLength($max_length)
            ->placeholder($params['placeholder'] ?? 'knur@gamil.com')
            ->regex($params['regex'] ?? config('app.regex_validate_conditions.email'))
            ->email()
            ->rules($params['rules'] ?? ['email', "max:$max_length"])
            ->required($params['required'] ?? true)
            ->default($params['default'] ?? null);

        if (isset($params['unique']['ignore_record'])) {
            $text_input->unique(ignoreRecord: $params['unique']['ignore_record']);
        }

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $text_input->columnSpanFull();
        }

        return $text_input;
    }

    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getTelFormField(array $params = []): Field
    {
        $max_length = self::processGetMaxLength($params);

        $text_input = TextInput::make($params['field_name'] ?? 'telephone')
            ->label($params['label'] ?? __('admin/default.labels.telephone'))
            ->helperText($params['helper_text'] ?? null)
            ->maxLength($max_length)
            ->placeholder($params['placeholder'] ?? '+380 (96) 690-64-12')
            ->regex($params['regex'] ?? config('app.regex_validate_conditions.telephone'))
            ->tel()
            ->rules($params['rules'] ?? ['nullable', 'string', "max:$max_length", 'regex:' . config('app.regex_validate_conditions.telephone')])
            ->required($params['required'] ?? false)
            ->default($params['default'] ?? null);

        if (isset($params['unique']['ignore_record'])) {
            $text_input->unique(ignoreRecord: $params['unique']['ignore_record']);
        }

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $text_input->columnSpanFull();
        }

        return $text_input;
    }

    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getUrlFormField(array $params = []): Field
    {
        $max_length = self::processGetMaxLength($params);

        $text_input = TextInput::make($params['field_name'] ?? 'base_url')
            ->label($params['label'] ?? __('admin/default.labels.base_url'))
            ->helperText($params['helper_text'] ?? null)
            ->maxLength($max_length)
            ->url()
            ->rules($params['rules'] ?? ['nullable', 'url', "max:$max_length"])
            ->placeholder($params['placeholder'] ?? 'https://example.com')
            ->required($params['required'] ?? true);

        if (isset($params['unique']['ignore_record'])) {
            $text_input->unique(ignoreRecord: $params['unique']['ignore_record']);
        }

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $text_input->columnSpanFull();
        }

        return $text_input;
    }

    /**
     * @param array $params
     *
     * @return int|null
     */
    private static function processGetMaxLength(array $params): ?int
    {
        if (isset($params['max_length']) && is_numeric($params['max_length']) && $params['max_length'] > 0) {
            $max_length = (int)$params['max_length'];
        } else if (array_key_exists('max_length', $params) && $params['max_length'] === null) {
            $max_length = null;
        } else {
            $max_length = 255;
        }

        return $max_length;
    }
}
