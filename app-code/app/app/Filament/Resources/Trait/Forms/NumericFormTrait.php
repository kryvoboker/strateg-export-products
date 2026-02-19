<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;

trait NumericFormTrait
{
    protected static function getNumericFormField(array $params = []): Field
    {
        $numeric_params = $params['numeric_params'] ?? [];

        return TextInput::make($params['field_name'])
            ->label($params['label'])
            ->numeric(...$numeric_params)
            ->rules($params['rules'] ?? ['required', 'numeric', 'min:0'])
            ->minValue($params['min_value'] ?? 0)
            ->disabled($params['disabled'] ?? false)
            ->dehydrated($params['dehydrated'] ?? true)
            ->default($params['default'] ?? 0)
            ->required($params['required'] ?? true);
    }

    protected static function getPriceFormField(array $params = []): Field
    {
        $numeric_params = $params['numeric_params'] ?? [];

        return TextInput::make($params['field_name'] ?? 'price')
            ->label($params['label'] ?? __('admin/default.labels.price'))
            ->numeric(...$numeric_params)
            ->rules($params['rules'] ?? ['required', 'numeric', 'min:0'])
            ->minValue($params['min_value'] ?? 0)
            ->prefix($params['prefix'] ?? config('app.currency.default_currency_symbol'))
            ->default($params['default'] ?? 0.0)
            ->required($params['required'] ?? true);
    }
}
