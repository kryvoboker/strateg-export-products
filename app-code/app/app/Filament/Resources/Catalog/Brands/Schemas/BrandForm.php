<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands\Schemas;

use App\Models\Brands\Brand;
use App\Models\Brands\BrandDescription;
use App\Models\Shops\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class BrandForm
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
                                TextInput::make('brand_name')
                                    ->label(__('admin/brands/brands.labels.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->default(fn ($record): string => $record instanceof Brand
                                        ? (string) ($record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '')
                                        : '')
                                    ->columnSpan(1),
                                TextInput::make('sort_order')
                                    ->label(__('admin/brands/brands.labels.sort_order'))
                                    ->numeric()
                                    ->default(1)
                                    ->columnSpan(1),
                                Select::make('shop_ids')
                                    ->label(__('admin/brands/brands.labels.shops'))
                                    ->options(fn (): array => Shop::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn ($record): array => $record instanceof Brand
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
    public static function syncBrandAdditionalData(Brand $brand, array $data): void
    {
        $brand_name = trim((string) Arr::get($data, 'brand_name', ''));

        if ($brand_name !== '') {
            BrandDescription::query()->updateOrCreate(
                [
                    'brand_id'         => (int) $brand->id,
                    'shop_language_id' => null,
                ],
                [
                    'name' => $brand_name,
                ]
            );
        }

        $shop_ids = collect(Arr::get($data, 'shop_ids', []))
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $brand->shops()->sync($shop_ids);
    }
}
