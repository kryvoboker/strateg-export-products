<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Tables;

use App\Models\Categories\Category;
use App\Models\Categories\CategoryShop;
use App\Models\Shops\Shop;
use Filament\Actions\Action;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $selected_parent_category_id = request()->integer('parent_category_id');

                $query->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                    'shops' => static fn ($shop_query) => $shop_query->orderBy('name'),
                ])->withCount('children');

                if ($selected_parent_category_id > 0) {
                    $subtree_category_ids = self::resolveSubtreeCategoryIds($selected_parent_category_id);
                    if ($subtree_category_ids !== []) {
                        $query->whereIn('id', $subtree_category_ids);
                    }
                }

                return $query
                    ->orderByRaw('parent_id IS NOT NULL')
                    ->orderBy('parent_id')
                    ->orderBy('sort_order')
                    ->orderBy('id');
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('category_name')
                    ->label(__('admin/categories/categories.columns.name'))
                    ->state(static fn (Category $record): string => self::resolveCategoryDisplayName($record))
                    ->searchable(
                        query: static fn (Builder $query, string $search): Builder => $query->whereHas(
                            'descriptions',
                            static fn (Builder $description_query): Builder => $description_query->whereRaw(
                                'LOWER(name) LIKE ?',
                                ['%' . mb_strtolower(trim($search)) . '%']
                            )
                        )
                    )
                    ->wrap(),

                TextColumn::make('shops')
                    ->label(__('admin/categories/categories.columns.shops'))
                    ->state(static fn (Category $record): string => self::resolveCategoryShopsText($record))
                    ->wrap(),

                IconColumn::make('is_active')
                    ->label(__('admin/default.columns.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('children_count')
                    ->label(__('admin/categories/categories.columns.children_count'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label(__('admin/categories/categories.columns.sort_order'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shop_id')
                    ->label(__('admin/categories/categories.filters.shop'))
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

                        return $query->whereHas('shops', static fn (Builder $shop_query): Builder => $shop_query->where('shops.id', $shop_id));
                    }),
                SelectFilter::make('is_active')
                    ->label(__('admin/categories/categories.filters.status'))
                    ->options([
                        '1' => __('admin/categories/categories.statuses.active'),
                        '0' => __('admin/categories/categories.statuses.inactive'),
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
                Action::make('viewChildrenTree')
                    ->label(__('admin/categories/categories.actions.view_children_tree'))
                    ->icon('heroicon-o-chevron-down')
                    ->visible(static fn (Category $record): bool => (int) ($record->children_count ?? 0) > 0)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('actions.close'))
                    ->modalHeading(static fn (Category $record): string => __('admin/categories/categories.actions.children_of', [
                        'name' => self::resolveCategoryDisplayName($record),
                    ]))
                    ->modalDescription(static fn (Category $record): HtmlString => new HtmlString(
                        self::renderChildrenTreeHtml((int) $record->id)
                    )),
                EditAction::make(),
            ])
            ->headerActions([
                Action::make('showAllCategories')
                    ->label(__('admin/categories/categories.actions.show_all_categories'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(static fn (): bool => request()->integer('parent_category_id') > 0)
                    ->url(static fn (): string => request()->fullUrlWithQuery([
                        'parent_category_id' => null,
                    ])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bindCategoriesToShops')
                        ->label(__('admin/categories/categories.actions.bind_categories_to_shops'))
                        ->icon('heroicon-o-link')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->schema([
                            Select::make('shop_ids')
                                ->label(__('admin/categories/categories.labels.shops'))
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
                                    ->title(__('admin/categories/categories.messages.select_shops_required'))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $categories_total = 0;
                            foreach ($records as $record) {
                                if (! $record instanceof Category) {
                                    continue;
                                }

                                $record->shops()->syncWithoutDetaching($shop_ids);
                                $categories_total++;
                            }

                            Notification::make()
                                ->title(__('admin/categories/categories.messages.bulk_bind_completed'))
                                ->body(__('admin/categories/categories.messages.bulk_bind_result', [
                                    'categories_total' => $categories_total,
                                    'shops_total' => count($shop_ids),
                                ]))
                                ->success()
                                ->send();
                        }),
                    DeleteBulkAction::make(),
                ])
                ->dropdownWidth(Width::Large),
            ]);
    }

    private static function resolveCategoryDisplayName(Category $category): string
    {
        $description_name = collect($category->descriptions)
            ->map(static fn ($description): string => Str::trim((string) Arr::get($description, 'name', '')))
            ->first(static fn (string $name): bool => $name !== '');

        if (is_string($description_name) && $description_name !== '') {
            return $description_name;
        }

        $name_from_db = Str::trim((string) ($category->descriptions()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderByRaw('shop_language_id IS NULL DESC')
            ->orderBy('id')
            ->value('name') ?? ''));

        if ($name_from_db !== '') {
            return $name_from_db;
        }

        return '#' . (int) $category->id;
    }

    private static function resolveCategoryShopsText(Category $category): string
    {
        $shop_names = CategoryShop::query()
            ->where('category_id', (int) $category->id)
            ->join('shops', 'shops.id', '=', 'category_shop.shop_id')
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
            return __('admin/categories/categories.columns.no_shops');
        }

        return implode(', ', $shop_names);
    }

    private static function renderChildrenTreeHtml(int $root_category_id): string
    {
        if ($root_category_id <= 0) {
            return '<p>' . e(__('admin/categories/categories.messages.no_children')) . '</p>';
        }

        $children = Category::query()
            ->with([
                'descriptions' => static fn ($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
                'shops' => static fn ($shop_query) => $shop_query->orderBy('name'),
            ])
            ->where('parent_id', $root_category_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'sort_order']);

        if ($children->isEmpty()) {
            return '<p>' . e(__('admin/categories/categories.messages.no_children')) . '</p>';
        }

        return self::renderTreeListHtml($children->all());
    }

    /**
     * @param array<int, Category> $categories
     */
    private static function renderTreeListHtml(array $categories): string
    {
        if ($categories === []) {
            return '';
        }

        $html = '<ul style="margin-left: 1rem; list-style: disc;">';

        foreach ($categories as $category) {
            if (! $category instanceof Category) {
                continue;
            }

            $child_categories = Category::query()
                ->with([
                    'descriptions' => static fn ($description_query) => $description_query
                        ->orderByRaw('shop_language_id IS NULL DESC')
                        ->orderBy('id'),
                    'shops' => static fn ($shop_query) => $shop_query->orderBy('name'),
                ])
                ->where('parent_id', (int) $category->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->all();

            $category_name = self::resolveCategoryDisplayName($category);
            $shops_text = self::resolveCategoryShopsText($category);

            $html .= '<li>';
            $html .= '<span style="font-weight: 600;">' . e($category_name) . '</span>';
            $html .= '<span style="color: #6b7280;"> (' . e($shops_text) . ')</span>';

            if ($child_categories !== []) {
                $html .= self::renderTreeListHtml($child_categories);
            }

            $html .= '</li>';
        }

        $html .= '</ul>';

        return $html;
    }

    /**
     * @return list<int>
     */
    private static function resolveSubtreeCategoryIds(int $root_category_id): array
    {
        if ($root_category_id <= 0) {
            return [];
        }

        $category_ids = [$root_category_id];
        $queue = [$root_category_id];

        while ($queue !== []) {
            $current_parent_id = (int) array_shift($queue);

            $child_ids = Category::query()
                ->where('parent_id', $current_parent_id)
                ->pluck('id')
                ->map(static fn ($category_id): int => (int) $category_id)
                ->filter(static fn (int $category_id): bool => $category_id > 0)
                ->values()
                ->all();

            foreach ($child_ids as $child_id) {
                if (in_array($child_id, $category_ids, true)) {
                    continue;
                }

                $category_ids[] = $child_id;
                $queue[] = $child_id;
            }
        }

        return $category_ids;
    }
}
