<li wire:key="category-children-node-{{ $category['id'] }}" class="py-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @if ($category['children_count'] > 0)
                <button
                    type="button"
                    wire:click="toggleCategoryChildren({{ $category['id'] }})"
                    wire:loading.attr="disabled"
                    wire:target="toggleCategoryChildren({{ $category['id'] }})"
                    aria-expanded="{{ in_array($category['id'], $expanded_parent_ids, true) ? 'true' : 'false' }}"
                    aria-controls="category-children-{{ $category['id'] }}"
                    class="inline-flex shrink-0 items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-950"
                >
                    <span>{{ $category['name'] }}</span>
                    <span aria-hidden="true">{{ in_array($category['id'], $expanded_parent_ids, true) ? '−' : '+' }}</span>
                    <span class="sr-only">
                        {{ in_array($category['id'], $expanded_parent_ids, true) ? __('actions.collapse') : __('actions.expand') }}
                    </span>
                </button>
            @else
                <span class="px-2 py-1.5 text-sm font-medium text-gray-950 dark:text-white">
                    {{ $category['name'] }}
                </span>
            @endif

            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $category['shop_name'] }}</span>
        </div>

        @if ($category['can_edit'])
            <a
                href="{{ $category['edit_url'] }}"
                aria-label="{{ __('actions.edit') }}: {{ $category['name'] }}"
                class="inline-flex shrink-0 items-center rounded-md px-3 py-1.5 text-sm font-medium text-primary-700 hover:bg-primary-50 dark:text-primary-300 dark:hover:bg-primary-950"
            >
                {{ __('actions.edit') }}
            </a>
        @endif
    </div>

    @if (in_array($category['id'], $expanded_parent_ids, true))
        <ul id="category-children-{{ $category['id'] }}" class="mt-2 space-y-1 border-l border-gray-200 pl-4 dark:border-gray-700">
            @forelse ($children_by_parent_id[$category['id']] ?? [] as $child_category)
                @include('filament.resources.catalog.categories.pages.partials.category-children-browser-node', ['category' => $child_category])
            @empty
                <li class="py-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('admin/categories/categories.messages.no_children') }}
                </li>
            @endforelse
        </ul>
    @endif
</li>
