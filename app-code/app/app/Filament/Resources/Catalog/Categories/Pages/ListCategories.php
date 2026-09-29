<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Pages;

use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Models\Categories\Category;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    #[Locked]
    public ?int $selected_category_id = null;

    #[Locked]
    public ?string $selected_category_name = null;

    /**
     * @var array<int, array<int, array{id: int, name: string, shop_name: string, children_count: int, edit_url: string, can_edit: bool}>>
     */
    #[Locked]
    public array $children_by_parent_id = [];

    /** @var list<int> */
    #[Locked]
    public array $expanded_parent_ids = [];

    #[On('category-children-selected')]
    public function showCategoryChildren(int $category_id): void
    {
        $category = CategoryResource::getEloquentQuery()
            ->with([
                'descriptions' => static fn ($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
            ])
            ->findOrFail($category_id);

        CategoryResource::authorize('view', $category);

        $this->selected_category_id   = (int) $category->id;
        $this->selected_category_name = $category->category_name;
        $this->children_by_parent_id  = [
            $this->selected_category_id => $this->queryCategoryChildren($this->selected_category_id),
        ];
        $this->expanded_parent_ids = [$this->selected_category_id];
    }

    public function toggleCategoryChildren(int $category_id): void
    {
        abort_if($this->selected_category_id === null, 404);

        $loaded_category_ids = collect($this->children_by_parent_id)
            ->flatten(1)
            ->pluck('id')
            ->map(static fn (int $loaded_category_id): int => $loaded_category_id)
            ->all();

        abort_unless(
            $category_id === $this->selected_category_id || in_array($category_id, $loaded_category_ids, true),
            404,
        );

        if (in_array($category_id, $this->expanded_parent_ids, true)) {
            $this->expanded_parent_ids = array_values(array_filter(
                $this->expanded_parent_ids,
                static fn (int $expanded_parent_id): bool => $expanded_parent_id !== $category_id,
            ));

            return;
        }

        if (! array_key_exists($category_id, $this->children_by_parent_id)) {
            $this->children_by_parent_id[$category_id] = $this->queryCategoryChildren($category_id);
        }

        $this->expanded_parent_ids[] = $category_id;
    }

    public function closeCategoryChildren(): void
    {
        $this->selected_category_id   = null;
        $this->selected_category_name = null;
        $this->children_by_parent_id  = [];
        $this->expanded_parent_ids    = [];
    }

    public function getFooter(): ?View
    {
        if ($this->selected_category_id === null) {
            return null;
        }

        return view('filament.resources.catalog.categories.pages.category-children-browser', [
            'selected_category_id'   => $this->selected_category_id,
            'selected_category_name' => $this->selected_category_name,
            'children_by_parent_id'  => $this->children_by_parent_id,
            'expanded_parent_ids'    => $this->expanded_parent_ids,
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, shop_name: string, children_count: int, edit_url: string, can_edit: bool}>
     */
    private function queryCategoryChildren(int $parent_category_id): array
    {
        return CategoryResource::getEloquentQuery()
            ->with([
                'descriptions' => static fn ($description_query) => $description_query
                    ->orderByRaw('shop_language_id IS NULL DESC')
                    ->orderBy('id'),
                'shop',
            ])
            ->withCount('children')
            ->where('parent_id', $parent_category_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Category $category): bool => CategoryResource::canView($category))
            ->map(static fn (Category $category): array => [
                'id'             => (int) $category->id,
                'name'           => $category->category_name,
                'shop_name'      => (string) ($category->shop?->name ?? __('admin/categories/categories.columns.no_shops')),
                'children_count' => (int) $category->children_count,
                'edit_url'       => CategoryResource::getUrl('edit', ['record' => $category]),
                'can_edit'       => CategoryResource::canEdit($category),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
