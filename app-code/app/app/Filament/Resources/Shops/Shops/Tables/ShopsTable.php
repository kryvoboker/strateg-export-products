<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\Shops\Tables;

use App\Filament\Resources\Trait\Filters\BooleanFilterTrait;
use App\Filament\Resources\Trait\Tables\BooleanTableTrait;
use App\Filament\Resources\Trait\Tables\CommonTextTableTrait;
use App\Filament\Resources\Trait\Tables\DateTableTrait;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopsTable
{
    use CommonTextTableTrait, BooleanTableTrait, DateTableTrait, BooleanFilterTrait;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                self::getTextTableField([
                    'field_name' => 'name',
                    'label'      => __('admin/default.columns.name'),
                ]),

                self::getTextTableField([
                    'field_name' => 'type',
                    'label'      => __('admin/shops/shops.columns.type'),
                ]),

                TextColumn::make('base_url')
                    ->label(__('admin/shops/shops.columns.base_url'))
                    ->url(fn($record) => $record->base_url, true)
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                self::getIsActiveTableField(),

                self::getCreatedAtTableField(),
            ])
            ->filters([
                self::getIsDefaultFilterField(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
