<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;

trait BooleanTableTrait
{
    protected static function getIsActiveTableField(array $params = []): Column
    {
        return IconColumn::make('is_active')
            ->label($params['label'] ?? __('admin/default.labels.is_active'))
            ->boolean($params['boolean'] ?? true)
            ->sortable($params['sortable'] ?? true);
    }

    protected static function getIsDefaultTableField(array $params = []): Column
    {
        return IconColumn::make('is_default')
            ->label($params['label'] ?? __('admin/default.labels.is_default'))
            ->boolean($params['boolean'] ?? true)
            ->sortable($params['sortable'] ?? true);
    }

}
