<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait SlugTableTrait
{
    /**
     * @param array $params
     *
     * @return Column
     */
    protected static function getSlugTableField(array $params = []): Column
    {
        return TextColumn::make($params['field_name'] ?? 'slugs.slug')
            ->label($params['label'] ?? __('admin/default.columns.slug'))
            ->searchable($params['searchable'] ?? true)
            ->sortable($params['sortable'] ?? true)
            ->limit($params['limit'] ?? 50)
            ->getStateUsing($params['get_state_using_cb'] ?? null)
            ->toggleable(isToggledHiddenByDefault: $params['isToggledHiddenByDefault'] ?? true);
    }
}
