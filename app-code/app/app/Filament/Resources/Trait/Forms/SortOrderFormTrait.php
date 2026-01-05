<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;

trait SortOrderFormTrait
{
    /**
     * @param array $params
     *
     * @return Field
     */
    protected static function getSortOrderFormField(array $params = []): Field
    {
        return TextInput::make('sort_order')
            ->label(__('admin/default.labels.sort_order'))
            ->helperText($params['helper_text'] ?? null)
            ->numeric()
            ->rules($params['rules'] ?? ['numeric', 'min:0'])
            ->default($params['default'] ?? 1)
            ->required($params['required'] ?? true);
    }
}
