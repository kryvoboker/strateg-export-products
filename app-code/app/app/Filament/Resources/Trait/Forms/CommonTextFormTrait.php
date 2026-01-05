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
    protected static function getNameFormField(array $params = []): Field
    {
        if (isset($params['max_length']) && $params['max_length'] > 0) {
            $max_length = (int)$params['max_length'];
        } else if (isset($params['max_length']) === false) {
            $max_length = null;
        } else {
            $max_length = 255;
        }

        return TextInput::make($params['field_name'] ?? 'name')
            ->label($params['label'] ?? __('admin/default.labels.name'))
            ->helperText($params['helper_text'] ?? null)
            ->maxLength($max_length)
            ->placeholder($params['placeholder'] ?? 'John')
            ->rules($params['rules'] ?? ['string', 'max:255'])
            ->required($params['required'] ?? true);
    }
}
