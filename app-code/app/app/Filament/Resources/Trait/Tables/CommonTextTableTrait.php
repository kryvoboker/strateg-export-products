<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Tables;

use App\Filament\Resources\Trait\Support\FieldOptionResolverTrait;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;

trait CommonTextTableTrait
{
    use FieldOptionResolverTrait;

    protected static function getTextTableField(array $params = []): Column
    {
        return TextColumn::make($params['field_name'])
            ->label($params['label'])
            ->searchable($params['searchable'] ?? true)
            ->sortable($params['sortable'] ?? true)
            ->limit($params['limit'] ?? 50)
            ->getStateUsing($params['get_state_using_cb'] ?? null)
            ->formatStateUsing($params['format_state_using_cb'] ?? null)
            ->toggleable(isToggledHiddenByDefault: self::resolveToggleHiddenByDefault($params));
    }
}
