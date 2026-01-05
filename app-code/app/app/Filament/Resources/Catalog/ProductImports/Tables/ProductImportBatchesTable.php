<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ProductImports\Tables;

use Filament\Actions\CreateAction;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('source_type')
                    ->label(__('admin/product_imports/batches.columns.source_type'))
                    ->formatStateUsing(function ($state) {
                        return match ($state) {
                            'excel_file'  => 'EXCEL',
                            'api'         => 'Google Sheets',
                            'admin_panel' => 'Адмінка',
                            default       => $state,
                        };
                    })
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_name')
                    ->label(__('admin/product_imports/batches.columns.source_name'))
                    ->wrap()
                    ->searchable(),
                BadgeColumn::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->colors([
                        'warning' => fn ($state) => $state === 'new' || $state === 'processing',
                        'success' => fn ($state) => $state === 'completed',
                        'danger'  => fn ($state) => in_array($state, ['failed', 'partial_failed', 'canceled'], true),
                    ])
                    ->sortable(),
                TextColumn::make('total_items')->label(__('admin/product_imports/batches.columns.total_items'))->sortable(),
                TextColumn::make('processed_items')->label(__('admin/product_imports/batches.columns.processed_items'))->sortable(),
                TextColumn::make('failed_items')->label(__('admin/product_imports/batches.columns.failed_items'))->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin/default.columns.created_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label(__('admin/product_imports/batches.columns.started_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label(__('admin/product_imports/batches.columns.finished_at'))
                    ->date(config('app.datetime_format'), config('app.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin/product_imports/batches.columns.status'))
                    ->options([
                        'new'            => __('admin/product_imports/batches.statuses.new'),
                        'processing'     => __('admin/product_imports/batches.statuses.processing'),
                        'completed'      => __('admin/product_imports/batches.statuses.completed'),
                        'failed'         => __('admin/product_imports/batches.statuses.failed'),
                        'partial_failed' => __('admin/product_imports/batches.statuses.partial_failed'),
                        'canceled'       => __('admin/product_imports/batches.statuses.canceled'),
                    ]),
            ]);
    }
}
