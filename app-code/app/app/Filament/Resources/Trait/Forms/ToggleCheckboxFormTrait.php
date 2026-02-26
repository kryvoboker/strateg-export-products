<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Toggle;

trait ToggleCheckboxFormTrait
{
    protected static function getIsActiveFormField(array $params = []): Field
    {
        $toggle_input = Toggle::make('is_active')
            ->label(__('admin/default.labels.is_active'))
            ->helperText($params['helper_text'] ?? null)
            ->default($params['default'] ?? true)
            ->required($params['required'] ?? true);

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $toggle_input->columnSpanFull();
        }

        return $toggle_input;
    }

    protected static function getIsDefaultFormField(array $params = []): Field
    {
        $toggle_input = Toggle::make('is_default')
            ->label(__('admin/default.labels.is_default'))
            ->helperText($params['helper_text'] ?? null)
            ->default($params['default'] ?? false)
            ->required($params['required'] ?? true)
            ->reactive()
            ->afterStateUpdated(function ($state, callable $set) {
                // Ensure only one default currency
                if ($state) {
                    $set('is_active', true);
                }
            });

        if (isset($params['is_column_span_full']) && $params['is_column_span_full'] === true) {
            $toggle_input->columnSpanFull();
        }

        return $toggle_input;
    }
}
