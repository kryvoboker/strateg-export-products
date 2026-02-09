<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Forms;

use Filament\Forms\Components\DateTimePicker;

trait DateFormTrait
{
    /**
     * @param array $params
     *
     * @return DateTimePicker
     */
    protected static function getDateAvailableFormField(array $params = []): DateTimePicker
    {
        return DateTimePicker::make($params['field_name'] ?? 'date_available')
            ->label($params['label'] ?? __('admin/default.labels.date_available'))
            ->rules($params['rules'] ?? ['required', 'date'])
            ->default(now(config('app.timezone')))
            ->required($params['required'] ?? true);
    }

    /**
     * @param array $params
     *
     * @return DateTimePicker
     */
    protected static function getEmailVerifiedAtFormField(array $params = []): DateTimePicker
    {
        return DateTimePicker::make($params['field_name'] ?? 'email_verified_at')
            ->label($params['label'] ?? __('admin/default.labels.email_verified_at'))
            ->rules($params['rules'] ?? ['required', 'date'])
            ->default(now(config('app.timezone')))
            ->required($params['required'] ?? false);
    }

    /**
     * @param array $params
     *
     * @return DateTimePicker
     */
    protected static function getDateAddedFormField(array $params = []): DateTimePicker
    {
        return DateTimePicker::make($params['field_name'] ?? 'date_added')
            ->label($params['label'] ?? __('admin/default.labels.date_added'))
            ->rules($params['rules'] ?? ['required', 'date'])
            ->default(now(config('app.timezone')))
            ->required($params['required'] ?? true);
    }

    /**
     * @param array $params
     *
     * @return DateTimePicker
     */
    protected static function getDateStartFormField(array $params = []): DateTimePicker
    {
        return DateTimePicker::make($params['field_name'] ?? 'date_start')
            ->label($params['label'] ?? __('admin/default.labels.date_start'))
            ->rules($params['rules'] ?? ['required', 'date'])
            ->default(now(config('app.timezone')))
            ->required($params['required'] ?? true);
    }

    /**
     * @param array $params
     *
     * @return DateTimePicker
     */
    protected static function getDateEndFormField(array $params = []): DateTimePicker
    {
        return DateTimePicker::make($params['field_name'] ?? 'date_end')
            ->label($params['label'] ?? __('admin/default.labels.date_end'))
            ->rules($params['rules'] ?? ['required', 'date'])
            ->default(now(config('app.timezone')))
            ->required($params['required'] ?? true);
    }
}
