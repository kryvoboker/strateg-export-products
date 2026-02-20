<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Attributes\Tables;

use App\Models\Attributes\Attribute;
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

class AttributesTable
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
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('attribute_name')
                    ->label(__('admin/attributes/attributes.columns.name'))
                    ->state(static fn (Attribute $record): string => self::resolveAttributeDisplayName($record))
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
                    ->label(__('admin/attributes/attributes.columns.shops'))
                    ->state(static fn (Attribute $record): string => self::resolveAttributeShopsText($record))
                    ->wrap(),

                TextColumn::make('shop_context')
                    ->label('Shop Scope')
                    ->state(static fn (Attribute $record): string => self::resolveDirectShopContext($record))
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
                    ->label(__('admin/attributes/attributes.columns.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label(__('admin/attributes/attributes.filters.shop'))
                    ->options(fn (): array => Shop::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->query(static function (Builder $query, array $data): Builder {
                        $shop_id = (int) ($data['value'] ?? 0);
                        if ($shop_id <= 0) {
                            return $query;
                        }

                        return $query->where('shop_id', $shop_id);
                    }),
                SelectFilter::make('is_active')
                    ->label(__('admin/attributes/attributes.filters.status'))
                    ->options([
                        '1' => __('admin/attributes/attributes.statuses.active'),
                        '0' => __('admin/attributes/attributes.statuses.inactive'),
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
                    BulkAction::make('bindAttributesToShops')
                        ->label(__('admin/attributes/attributes.actions.bind_attributes_to_shops'))
                        ->icon('heroicon-o-link')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/attributes/attributes.labels.shops'))
                                ->options(fn (): array => Shop::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->toArray())
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
                                Notification::make()
                                    ->title(__('admin/attributes/attributes.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $attributes_total = 0;
                            $created_total    = 0;
                            $reused_total     = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof Attribute) {
                                    continue;
                                }

                                foreach ($shop_ids as $shop_id) {
                                    $source_family_ulid = Str::trim((string) ($record->family_ulid ?? ''));
                                    $existing_target = $source_family_ulid !== ''
                                        ? Attribute::findByFamilyAndShop($source_family_ulid, $shop_id)
                                        : null;

                                    $resolved_attribute = $record->duplicateForShop($shop_id);
                                    if ($existing_target instanceof Attribute && (int) $existing_target->id === (int) $resolved_attribute->id) {
                                        $reused_total++;
                                    } elseif ((int) $resolved_attribute->id > 0) {
                                        $created_total++;
                                    }
                                }

                                $attributes_total++;
                            }

                            Notification::make()
                                ->title(__('admin/attributes/attributes.messages.bulk_bind_completed'))
                                ->body(__('admin/attributes/attributes.messages.bulk_bind_result', [
                                    'attributes_total' => $attributes_total,
                                    'shops_total'      => count($shop_ids),
                                ]).', created: '.$created_total.', reused: '.$reused_total)
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ])
                    ->dropdownWidth(Width::Large),
            ]);
    }

    private static function resolveAttributeDisplayName(Attribute $attribute): string
    {
        $description_name = collect($attribute->descriptions)
            ->map(static fn ($description): string => Str::trim((string) Arr::get($description, 'name', '')))
            ->first(static fn (string $name): bool => $name !== '');

        if (is_string($description_name) && $description_name !== '') {
            return $description_name;
        }

        $name_from_db = Str::trim((string) ($attribute->descriptions()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderByRaw('shop_language_id IS NULL DESC')
            ->orderBy('id')
            ->value('name') ?? ''));

        if ($name_from_db !== '') {
            return $name_from_db;
        }

        return '#'.(int) $attribute->id;
    }

    private static function resolveAttributeShopsText(Attribute $attribute): string
    {
        return self::resolveDirectShopContext($attribute);
    }

    private static function resolveDirectShopContext(Attribute $attribute): string
    {
        $shop_name = Str::trim((string) ($attribute->shop?->name ?? ''));

        return $shop_name !== '' ? $shop_name : __('admin/default.messages.created');
    }
}
