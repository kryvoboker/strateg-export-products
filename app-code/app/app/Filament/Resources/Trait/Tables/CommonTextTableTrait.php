<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait CommonTextTableTrait
{
    /**
     * @param array $params
     *
     * @return Column
     */
    protected static function getNameTableField(array $params = []): Column
    {
        return TextColumn::make($params['field_name'] ?? 'name')
            ->label($params['label'] ?? __('admin/default.columns.name'))
            ->searchable($params['searchable'] ?? true)
            ->sortable($params['sortable'] ?? true)
            ->limit($params['limit'] ?? 50)
            ->getStateUsing($params['get_state_using_cb'] ?? null);
    }

    /**
     * @param array $params
     *
     * @return Column
     */
    protected static function getCodeTableField(array $params = []): Column
    {
        return TextColumn::make($params['field_name'] ?? 'code')
            ->label($params['label'] ?? __('admin/default.columns.code'))
            ->searchable($params['searchable'] ?? true)
            ->sortable($params['sortable'] ?? true)
            ->badge($params['badge'] ?? true);
    }
}
