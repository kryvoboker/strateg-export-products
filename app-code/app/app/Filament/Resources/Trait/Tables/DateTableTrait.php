<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait DateTableTrait
{
    protected static function getCreatedAtTableField(array $params = []): Column
    {
        return TextColumn::make('created_at')
            ->label($params['label'] ?? __('admin/default.columns.created_at'))
            ->date($params['datetime_format'] ?? config('app.datetime_format'), $params['timezone'] ?? config('app.timezone'))
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }

    protected static function getDateAvailableTableField(array $params = []): Column
    {
        return TextColumn::make('date_available')
            ->label($params['label'] ?? __('admin/default.columns.date_available'))
            ->date($params['datetime_format'] ?? config('app.datetime_format'), $params['timezone'] ?? config('app.timezone'))
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }

    protected static function getDateAddedTableField(array $params = []): Column
    {
        return TextColumn::make('date_added')
            ->label($params['label'] ?? __('admin/default.columns.date_added'))
            ->date($params['datetime_format'] ?? config('app.datetime_format'), $params['timezone'] ?? config('app.timezone'))
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }

    protected static function getUpdatedAtTableField(array $params = []): Column
    {
        return TextColumn::make('updated_at')
            ->label($params['label'] ?? __('admin/default.columns.updated_at'))
            ->date($params['datetime_format'] ?? config('app.datetime_format'), $params['timezone'] ?? config('app.timezone'))
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }

    protected static function getEmailVerifiedAtTableField(array $params = []): Column
    {
        return TextColumn::make('email_verified_at')
            ->label($params['label'] ?? __('admin/default.columns.email_verified_at'))
            ->date($params['datetime_format'] ?? config('app.datetime_format'), $params['timezone'] ?? config('app.timezone'))
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }
}
