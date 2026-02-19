<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers\Schemas;

use App\Models\Manufacturers\Manufacturer;
use App\Models\Manufacturers\ManufacturerDescription;
use App\Models\Shops\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class ManufacturerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->schema([
                        Grid::make()
                            ->schema([
                                TextInput::make('manufacturer_name')
                                    ->label(__('admin/manufacturers/manufacturers.labels.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->default(fn ($record): string => $record instanceof Manufacturer
                                        ? (string) ($record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '')
                                        : '')
                                    ->columnSpan(1),
                                TextInput::make('sort_order')
                                    ->label(__('admin/manufacturers/manufacturers.labels.sort_order'))
                                    ->numeric()
                                    ->default(1)
                                    ->columnSpan(1),
                                Select::make('shop_ids')
                                    ->label(__('admin/manufacturers/manufacturers.labels.shops'))
                                    ->options(fn (): array => Shop::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn ($record): array => $record instanceof Manufacturer
                                        ? $record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all()
                                        : [])
                                    ->columnSpan(2),
                                Toggle::make('is_active')
                                    ->label(__('admin/default.labels.is_active'))
                                    ->default(true)
                                    ->columnSpan(2),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function syncManufacturerAdditionalData(Manufacturer $manufacturer, array $data): void
    {
        $manufacturer_name = trim((string) Arr::get($data, 'manufacturer_name', ''));

        if ($manufacturer_name !== '') {
            ManufacturerDescription::query()->updateOrCreate(
                [
                    'manufacturer_id'  => (int) $manufacturer->id,
                    'shop_language_id' => null,
                ],
                [
                    'name' => $manufacturer_name,
                ]
            );
        }

        $shop_ids = collect(Arr::get($data, 'shop_ids', []))
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $manufacturer->shops()->sync($shop_ids);
    }
}
