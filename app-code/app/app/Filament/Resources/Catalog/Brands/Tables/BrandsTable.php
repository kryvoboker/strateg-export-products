<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands\Tables;

use App\Models\Brands\Brand;
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
                    'shop',
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
                TextColumn::make('shop_context')
                    ->label('Shop Scope')
                    ->state(static fn (Brand $record): string => self::resolveDirectShopContext($record))
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
                    ->label(__('admin/brands/brands.columns.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label(__('admin/brands/brands.filters.shop'))
                    ->options(fn (): array => Shop::resolveActiveOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int) ($data['value'] ?? 0);
                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->where('shop_id', $shop_id);
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
                                ->options(fn (): array => Shop::resolveActiveOptions())
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
                            $created_total = 0;
                            $reused_total  = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof Brand) {
                                    continue;
                                }

                                foreach ($shop_ids as $shop_id) {
                                    $source_family_ulid = Str::trim((string) ($record->family_ulid ?? ''));
                                    $existing_target = $source_family_ulid !== ''
                                        ? Brand::findByFamilyAndShop($source_family_ulid, $shop_id)
                                        : null;

                                    $resolved_brand = $record->duplicateForShop($shop_id);
                                    if ($existing_target instanceof Brand && (int) $existing_target->id === (int) $resolved_brand->id) {
                                        $reused_total++;
                                    } elseif ((int) $resolved_brand->id > 0) {
                                        $created_total++;
                                    }
                                }

                                $brands_total++;
                            }

                            Notification::make()
                                ->title(__('admin/brands/brands.messages.bulk_bind_completed'))
                                ->body(__('admin/brands/brands.messages.bulk_bind_result', [
                                    'brands_total' => $brands_total,
                                    'shops_total'  => count($shop_ids),
                                ]).', created: '.$created_total.', reused: '.$reused_total)
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
        return self::resolveDirectShopContext($brand);
    }

    private static function resolveDirectShopContext(Brand $brand): string
    {
        $shop_name = Str::trim((string) ($brand->shop?->name ?? ''));

        return $shop_name !== '' ? $shop_name : __('admin/default.messages.created');
    }
}
