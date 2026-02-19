<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands\Tables;

use App\Models\Brands\Brand;
use App\Models\Brands\BrandShop;
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

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                    'shops' => static fn ($shop_query) => $shop_query->orderBy('name'),
                ])
                ->orderBy('sort_order')
                ->orderBy('id'))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('brand_name')
                    ->label(__('admin/brands/brands.columns.name'))
                    ->state(static fn (Brand $record): string => self::resolveBrandDisplayName($record))
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
                    ->label(__('admin/brands/brands.columns.shops'))
                    ->state(static fn (Brand $record): string => self::resolveBrandShopsText($record))
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label(__('admin/default.columns.is_active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin/brands/brands.columns.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label(__('admin/brands/brands.filters.shop'))
                    ->options(fn (): array => Shop::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->toArray())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int) ($data['value'] ?? 0);
                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->whereHas('shops', static fn (Builder $shop_query): Builder => $shop_query->where('shops.id', $shop_id));
                    }),
                SelectFilter::make('is_active')
                    ->label(__('admin/brands/brands.filters.status'))
                    ->options([
                        '1' => __('admin/brands/brands.statuses.active'),
                        '0' => __('admin/brands/brands.statuses.inactive'),
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
                    BulkAction::make('bindBrandsToShops')
                        ->label(__('admin/brands/brands.actions.bind_brands_to_shops'))
                        ->icon('heroicon-o-link')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/brands/brands.labels.shops'))
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
                                Notification::make()->title(__('admin/brands/brands.messages.select_shops_required'))->danger()->send();

                                return;
                            }

                            $brands_total = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof Brand) {
                                    continue;
                                }

                                $record->shops()->syncWithoutDetaching($shop_ids);
                                $brands_total++;
                            }

                            Notification::make()
                                ->title(__('admin/brands/brands.messages.bulk_bind_completed'))
                                ->body(__('admin/brands/brands.messages.bulk_bind_result', [
                                    'brands_total' => $brands_total,
                                    'shops_total'  => count($shop_ids),
                                ]))
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ])->dropdownWidth(Width::Large),
            ]);
    }

    private static function resolveBrandDisplayName(Brand $brand): string
    {
        $name = collect($brand->descriptions)
            ->map(static fn ($description): string => Str::trim((string) Arr::get($description, 'name', '')))
            ->first(static fn (string $name): bool => $name !== '');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return '#'.(int) $brand->id;
    }

    private static function resolveBrandShopsText(Brand $brand): string
    {
        $shop_names = BrandShop::query()
            ->where('brand_id', (int) $brand->id)
            ->join('shops', 'shops.id', '=', 'brand_shop.shop_id')
            ->orderBy('shops.name')
            ->pluck('shops.name')
            ->map(static fn ($shop_name): string => Str::of((string) $shop_name)->squish()->toString())
            ->filter(static fn (string $shop_name): bool => $shop_name !== '')
            ->all();

        $shop_names_by_key = [];
        foreach ($shop_names as $shop_name) {
            $shop_names_by_key[Str::lower($shop_name)] = $shop_name;
        }

        $shop_names = array_values($shop_names_by_key);

        if ($shop_names === []) {
            return __('admin/brands/brands.columns.no_shops');
        }

        return implode(', ', $shop_names);
    }
}
