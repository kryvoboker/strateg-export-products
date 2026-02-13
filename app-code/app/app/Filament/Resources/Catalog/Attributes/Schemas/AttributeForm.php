<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Attributes\Schemas;

use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Shops\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class AttributeForm
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
                                TextInput::make('attribute_name')
                                    ->label(__('admin/attributes/attributes.labels.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->default(fn($record): string => $record instanceof Attribute
                                        ? (string)($record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '')
                                        : '')
                                    ->columnSpan(1),

                                TextInput::make('sort_order')
                                    ->label(__('admin/attributes/attributes.labels.sort_order'))
                                    ->numeric()
                                    ->default(1)
                                    ->columnSpan(1),

                                Select::make('shop_ids')
                                    ->label(__('admin/attributes/attributes.labels.shops'))
                                    ->options(fn(): array => Shop::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn($record): array => $record instanceof Attribute
                                        ? $record->shops()->pluck('shops.id')->map(static fn($shop_id): int => (int)$shop_id)->all()
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

    public static function syncAttributeAdditionalData(Attribute $attribute, array $data): void
    {
        $attribute_name = trim((string)Arr::get($data, 'attribute_name', ''));

        if ($attribute_name !== '') {
            AttributeDescription::query()->updateOrCreate(
                [
                    'attribute_id'     => (int)$attribute->id,
                    'shop_language_id' => null,
                ],
                [
                    'name' => $attribute_name,
                ]
            );
        }

        $shop_ids = collect(Arr::get($data, 'shop_ids', []))
            ->map(static fn($shop_id): int => (int)$shop_id)
            ->filter(static fn(int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $attribute->shops()->sync($shop_ids);
    }
}
