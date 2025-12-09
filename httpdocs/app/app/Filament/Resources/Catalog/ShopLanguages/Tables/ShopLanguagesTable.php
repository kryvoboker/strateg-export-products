<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ShopLanguagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.name')
                    ->label(__('admin/shops/languages.columns.shop'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('code')
                    ->label(__('admin/shops/languages.columns.code'))
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label(__('admin/shops/languages.columns.name'))
                    ->sortable()
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label(__('admin/default.columns.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin/default.filters.active'))
                    ->trueLabel(__('admin/default.filters.active_only'))
                    ->falseLabel(__('admin/default.filters.inactive_only')),
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

