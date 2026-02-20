<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers\Tables;

use App\Models\Manufacturers\Manufacturer;
use App\Models\Shops\Shop;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ManufacturersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                    'shop',
                ])
                ->orderBy('sort_order')
                ->orderBy('id'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('manufacturer_name')
                    ->label(__('admin/manufacturers/manufacturers.columns.name'))
                    ->state(static fn (Manufacturer $record): string => self::resolveManufacturerDisplayName($record))
                    ->searchable(
                        query: static fn (Builder $query, string $search): Builder => $query->whereHas(
                            'descriptions',
                            static fn (Builder $description_query): Builder => $description_query->whereRaw(
                                'LOWER(name) LIKE ?',
                                ['%'.mb_strtolower(trim($search)).'%']
                            )
                        )
                    )
                    ->wrap(),
                TextColumn::make('shops')
                    ->label(__('admin/manufacturers/manufacturers.columns.shops'))
                    ->state(static fn (Manufacturer $record): string => self::resolveManufacturerShopsText($record))
                    ->wrap(),
                TextColumn::make('shop_context')
                    ->label('Shop Scope')
                    ->state(static fn (Manufacturer $record): string => self::resolveDirectShopContext($record))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('family_ulid')
                    ->label('Family ULID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),
                IconColumn::make('is_active')
                    ->label(__('admin/default.columns.is_active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin/manufacturers/manufacturers.columns.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label(__('admin/manufacturers/manufacturers.filters.shop'))
                    ->options(fn (): array => Shop::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->toArray())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int) ($data['value'] ?? 0);
                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->where('shop_id', $shop_id);
                    }),
                SelectFilter::make('is_active')
                    ->label(__('admin/manufacturers/manufacturers.filters.status'))
                    ->options([
                        '1' => __('admin/manufacturers/manufacturers.statuses.active'),
                        '0' => __('admin/manufacturers/manufacturers.statuses.inactive'),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        $value = Arr::get($data, 'value');
                        if (! in_array((string) $value, ['0', '1'], true)) {
                            return $query;
                        }

                        return $query->where('is_active', (bool) ((int) $value));
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bindManufacturersToShops')
                        ->label(__('admin/manufacturers/manufacturers.actions.bind_manufacturers_to_shops'))
                        ->icon('heroicon-o-link')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/manufacturers/manufacturers.labels.shops'))
                                ->options(fn (): array => Shop::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->toArray())
                                ->multiple()
                                ->required()
                                ->searchable()
                                ->preload(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $shop_ids = collect(Arr::get($data, 'shop_ids', []))
                                ->map(static fn ($shop_id): int => (int) $shop_id)
                                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                                ->unique()
                                ->values()
                                ->all();

                            if ($shop_ids === []) {
                                Notification::make()->title(__('admin/manufacturers/manufacturers.messages.select_shops_required'))->danger()->send();

                                return;
                            }

                            $manufacturers_total = 0;
                            $created_total       = 0;
                            $reused_total        = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof Manufacturer) {
                                    continue;
                                }

                                foreach ($shop_ids as $shop_id) {
                                    $source_family_ulid = Str::trim((string) ($record->family_ulid ?? ''));
                                    $existing_target = $source_family_ulid !== ''
                                        ? Manufacturer::findByFamilyAndShop($source_family_ulid, $shop_id)
                                        : null;

                                    $resolved_manufacturer = $record->duplicateForShop($shop_id);
                                    if ($existing_target instanceof Manufacturer && (int) $existing_target->id === (int) $resolved_manufacturer->id) {
                                        $reused_total++;
                                    } elseif ((int) $resolved_manufacturer->id > 0) {
                                        $created_total++;
                                    }
                                }

                                $manufacturers_total++;
                            }

                            Notification::make()
                                ->title(__('admin/manufacturers/manufacturers.messages.bulk_bind_completed'))
                                ->body(__('admin/manufacturers/manufacturers.messages.bulk_bind_result', [
                                    'manufacturers_total' => $manufacturers_total,
                                    'shops_total'         => count($shop_ids),
                                ]).', created: '.$created_total.', reused: '.$reused_total)
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ])->dropdownWidth(Width::Large),
            ]);
    }

    private static function resolveManufacturerDisplayName(Manufacturer $manufacturer): string
    {
        $name = collect($manufacturer->descriptions)
            ->map(static fn ($description): string => Str::trim((string) Arr::get($description, 'name', '')))
            ->first(static fn (string $name): bool => $name !== '');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return '#'.(int) $manufacturer->id;
    }

    private static function resolveManufacturerShopsText(Manufacturer $manufacturer): string
    {
        return self::resolveDirectShopContext($manufacturer);
    }

    private static function resolveDirectShopContext(Manufacturer $manufacturer): string
    {
        $shop_name = Str::trim((string) ($manufacturer->shop?->name ?? ''));

        return $shop_name !== '' ? $shop_name : __('admin/default.messages.created');
    }
}
