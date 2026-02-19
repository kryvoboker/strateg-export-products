<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait NumericTableTrait
{
    protected static function getNumericTableField(array $params = []): Column
    {
        $numeric_params = $params['numeric_params'] ?? [];

        return TextColumn::make($params['filed_name'])
            ->label($params['label'])
            ->numeric(...$numeric_params)
            ->sortable($params['sortable'] ?? true)
            ->toggleable(isToggledHiddenByDefault: $params['is_toggled_hidden_by_default'] ?? false);
    }
}
