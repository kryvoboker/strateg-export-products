<section class="mt-6 rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900" aria-labelledby="category-children-title">
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-700">
        <div class="space-y-1">
            <h2 id="category-children-title" class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('admin/categories/categories.actions.children_of', ['name' => $selected_category_name]) }}
            </h2>
        </div>

        <button
            type="button"
            wire:click="closeCategoryChildren"
            class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800"
        >
            {{ __('actions.close') }}
        </button>
    </header>

    @php($root_children = $children_by_parent_id[$selected_category_id] ?? [])

    @if ($root_children === [])
        <p class="pt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('admin/categories/categories.messages.no_children') }}
        </p>
    @else
        <ul class="divide-y divide-gray-200 dark:divide-gray-700" aria-label="{{ __('admin/categories/categories.actions.view_children_tree') }}">
            @foreach ($root_children as $category)
                @include('filament.resources.catalog.categories.pages.partials.category-children-browser-node', ['category' => $category])
            @endforeach
        </ul>
    @endif
</section>
