<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Schemas;

use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Shops\Shop;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class CategoryForm
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
                                TextInput::make('category_name')
                                    ->label(__('admin/categories/categories.labels.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->default(fn ($record): string => $record instanceof Category
                                        ? (string) ($record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '')
                                        : '')
                                    ->columnSpan(1),

                                Select::make('parent_id')
                                    ->label(__('admin/categories/categories.labels.parent'))
                                    ->options(fn ($record): array => self::getParentCategoryOptions($record))
                                    ->searchable()
                                    ->preload()
                                    ->placeholder(__('admin/default.placeholders.select_parent_category'))
                                    ->columnSpan(1),

                                TextInput::make('sort_order')
                                    ->label(__('admin/categories/categories.labels.sort_order'))
                                    ->numeric()
                                    ->default(0)
                                    ->columnSpan(1),

                                Select::make('shop_ids')
                                    ->label(__('admin/categories/categories.labels.shops'))
                                    ->options(fn (): array => Shop::query()
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray())
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->default(fn ($record): array => $record instanceof Category
                                        ? $record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all()
                                        : [])
                                    ->columnSpan(1),

                                Toggle::make('is_active')
                                    ->label(__('admin/default.labels.is_active'))
                                    ->default(true)
                                    ->columnSpan(2),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    public static function getParentCategoryOptions(mixed $record): array
    {
        $current_category_id = $record instanceof Category ? (int) $record->id : 0;
        $categories          = Category::query()
            ->with([
                'descriptions' => static fn ($query) => $query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
            ])
            ->when(
                $current_category_id > 0,
                static fn ($query) => $query->where('id', '!=', $current_category_id),
            )
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id']);

        $name_by_id = [];
        foreach ($categories as $category) {
            $name_by_id[(int) $category->id] = (string) (
                Arr::get($category->descriptions->first(), 'name')
                ?? ('#'.(int) $category->id)
            );
        }

        $options = [];

        $append_options = function (int $parent_id, string $prefix) use (&$append_options, &$options, $categories, $name_by_id): void {
            $children = $categories->filter(static fn (Category $category): bool => (int) ($category->parent_id ?? 0) === $parent_id);

            foreach ($children as $child) {
                $child_id           = (int) $child->id;
                $options[$child_id] = $prefix.($name_by_id[$child_id] ?? ('#'.$child_id));
                $append_options($child_id, $prefix.'  ');
            }
        };

        $append_options(0, '');

        return $options;
    }

    public static function syncCategoryAdditionalData(Category $category, array $data): void
    {
        $category_name = trim((string) Arr::get($data, 'category_name', ''));

        if ($category_name !== '') {
            CategoryDescription::query()->updateOrCreate(
                [
                    'category_id'      => (int) $category->id,
                    'shop_language_id' => null,
                ],
                [
                    'name'             => $category_name,
                    'description'      => null,
                    'h1_title'         => $category_name,
                    'meta_title'       => $category_name,
                    'meta_description' => null,
                    'meta_keywords'    => null,
                ]
            );
        }

        $shop_ids = collect(Arr::get($data, 'shop_ids', []))
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $category->shops()->sync($shop_ids);
    }
}
