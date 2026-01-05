<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait NumericTableTrait
{
    /**
     * @param array $params
     *
     * @return Column
     */
    protected static function getSortOrderTableField(array $params = []): Column
    {
        $numeric_params = $params['numeric_params'] ?? [];

        return TextColumn::make('sort_order')
            ->label($params['label'] ?? __('admin/default.columns.sort_order'))
            ->numeric(...$numeric_params)
            ->sortable($params['sortable'] ?? true);
    }
}
