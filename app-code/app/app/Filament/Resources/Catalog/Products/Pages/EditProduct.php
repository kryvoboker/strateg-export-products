<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Filament\Resources\Catalog\Products\ProductResource;
use App\Models\Attributes\Attribute;
use App\Models\Attributes\AttributeDescription;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryDescription;
use App\Models\Categories\CategoryProduct;
use App\Models\Products\Product;
use App\Models\Products\ProductDescription;
use App\Models\Products\ProductDiscount;
use App\Models\Products\ProductImage;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductSpecial;
use App\Models\Products\ProductToAttribute;
use App\Models\Products\ProductToManufacturerBrand;
use App\Models\Seo\SeoUrl;
use App\Models\Shops\ShopLanguage;
use App\Services\Products\ProductEditStateBuilderService;
use App\Services\Products\ProductUpdateQueueService;
use App\Services\Products\ProductResourceOptionsService;
use App\Services\Products\ProductEditPersistenceService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @var array<string, array{sheet:string, fields:list<string>}>
     */
    private const array API_UPDATE_FIELD_MAP = [
        'product' => [
            'sheet'  => 'Product',
            'fields' => ['sku', 'quantity', 'minimum', 'image', 'price', 'manufacturer', 'brand', 'is_active', 'date_available', 'date_added'],
        ],
        'description' => [
            'sheet'  => 'Description',
            'fields' => ['name', 'description', 'meta_title', 'meta_description', 'meta_keywords'],
        ],
        'image' => [
            'sheet'  => 'Image',
            'fields' => ['image', 'sort_order'],
        ],
        'product_category' => [
            'sheet'  => 'Product Category',
            'fields' => ['category_name'],
        ],
        'product_attribute' => [
            'sheet'  => 'Product Attribute',
            'fields' => ['attribute_name', 'attribute_text'],
        ],
        'seo_url' => [
            'sheet'  => 'Seo Url',
            'fields' => ['query_key', 'query_value', 'keyword', 'sort_order'],
        ],
        'special' => [
            'sheet'  => 'Special',
            'fields' => ['user_group_id', 'price', 'priority', 'date_start', 'date_end'],
        ],
        'discount' => [
            'sheet'  => 'Discount',
            'fields' => ['user_group_id', 'quantity', 'price', 'priority', 'date_start', 'date_end'],
        ],
    ];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('updateProductViaApi')
                ->label(__('admin/products/products.actions.update_product_via_api'))
                ->icon(Heroicon::ArrowPathRoundedSquare)
                ->color('success')
                ->requiresConfirmation()
                ->action('saveAndQueueProductUpdateViaApi'),
            ...parent::getFormActions(),
        ];
    }

    /**
     * @throws Throwable
     */
    public function saveAndQueueProductUpdateViaApi(): void
    {
        try {
            $this->data = $this->sanitizeAttributesSelectionBeforeSave(is_array($this->data) ? $this->data : []);
            $this->save(false);
        } catch (Exception|Throwable $e) {
            Notification::make()
                ->title(__('admin/products/products.errors.product_save_before_update_by_api'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $this->record instanceof Product) {
            return;
        }

        $summary = $this->queueProductUpdateViaApi($this->record->refresh());

        if (($summary['updates_queued'] ?? 0) > 0) {
            Notification::make()
                ->title(__('admin/products/products.messages.item_update_queued'))
                ->body(__('admin/products/products.messages.item_update_result', $summary))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('admin/products/products.messages.item_update_not_queued'))
            ->body(__('admin/products/products.messages.item_update_result', $summary))
            ->warning()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $form_data
     * @return array<string, mixed>
     */
    private function sanitizeAttributesSelectionBeforeSave(array $form_data): array
    {
        $selected_by_language = Arr::get($form_data, 'attributes_selected_by_language', []);
        $custom_by_language   = Arr::get($form_data, 'attributes_custom_by_language', []);

        if (! is_array($selected_by_language) || ! is_array($custom_by_language)) {
            return $form_data;
        }

        foreach ($selected_by_language as $shop_language_id => $selected_attribute_ids) {
            if (! is_array($selected_attribute_ids)) {
                continue;
            }

            $custom_rows = $custom_by_language[$shop_language_id] ?? null;
            if (! is_array($custom_rows)) {
                continue;
            }

            $has_custom_attribute_names = collect($custom_rows)
                ->filter(static fn ($row): bool => is_array($row))
                ->contains(static fn (array $row): bool => Str::trim((string) Arr::get($row, 'attribute_name', '')) !== '');

            if (! $has_custom_attribute_names) {
                continue;
            }

            Arr::set($form_data, 'attributes_selected_by_language.'.$shop_language_id, []);
        }

        return $form_data;
    }

    public function renderingHasRelationManagers(): void
    {
        $managers                = $this->getRelationManagers();
        $active_relation_manager = (string) ($this->activeRelationManager ?? '');

        if (array_key_exists($active_relation_manager, $managers)) {
            $this->activeRelationManager = $active_relation_manager;

            return;
        }

        if ($this->hasCombinedRelationManagerTabsWithContent()) {
            $this->activeRelationManager = $active_relation_manager;

            return;
        }

        $this->activeRelationManager = array_key_first($managers);

        if ($this->activeRelationManager === null) {
            $this->activeRelationManager = '';
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! $this->record instanceof Product) {
            return $data;
        }

        $product = Product::query()->with([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
            'productToManufacturerBrand',
        ])->find((int) $this->record->id);

        if (! $product instanceof Product) {
            return $data;
        }

        return [
            ...$data,
            ...app(ProductEditStateBuilderService::class)->buildForProduct($product),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Product) {
            return $record;
        }

        $data                  = $this->sanitizeAttributesSelectionBeforeSave($data);
        $data                  = $this->enforceImmutableProductFields($record, $data);
        $bind_shop_language_id = (int) Arr::get($data, 'bind_shop_language_id', 0);

        DB::transaction(function () use ($record, $data, $bind_shop_language_id): void {
            try {
                $this->persistEditedProductData($record, $data, $bind_shop_language_id);
            } catch (Exception $e) {
                Notification::make()
                    ->title(__('admin/products/products.errors.product_update'))
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                throw $e;
            }
        });

        return $record->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function enforceImmutableProductFields(Product $record, array $data): array
    {
        $data['product_id'] = (int) $record->id;
        $data['model']      = (string) ($record->model ?? '');
        $data['ean']        = (string) ($record->ean ?? '');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistEditedProductData(Product $product, array $data, int $default_shop_language_id = 0): void
    {
        app(ProductEditPersistenceService::class)->persist(
            $product,
            $data,
            $default_shop_language_id,
            [
                'sync_external_product_id' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncExternalProductIdForSelectedShop(int $product_id, array $data): void
    {
        app(ProductEditPersistenceService::class)->syncExternalProductIdForSelectedShop($product_id, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductCategoriesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        CategoryProduct::query()->where('product_id', $product_id)->delete();

        $resolved_category_ids = [];
        $seen_category_ids     = [];

        foreach (Arr::get($data, 'categories_existing_ids', []) as $category_id) {
            $resolved_category_id = (int) $category_id;
            if ($resolved_category_id <= 0) {
                continue;
            }

            if (array_key_exists($resolved_category_id, $seen_category_ids)) {
                throw ValidationException::withMessages([
                    'categories_existing_ids' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
                ]);
            }

            $seen_category_ids[$resolved_category_id] = true;
            $resolved_category_ids[]                  = $resolved_category_id;
        }

        [$category_paths, $duplicate_category_paths] = $this->parseHierarchyPathsWithDuplicates(
            (string) Arr::get($data, 'categories_custom_paths', '')
        );

        if ($duplicate_category_paths !== []) {
            throw ValidationException::withMessages([
                'categories_custom_paths' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
            ]);
        }

        foreach ($category_paths as $category_path) {
            $category_id = $this->resolveOrCreateCategoryIdByPath($category_path, $default_shop_language_id);
            if ($category_id === null) {
                continue;
            }

            if (array_key_exists($category_id, $seen_category_ids)) {
                throw ValidationException::withMessages([
                    'categories_custom_paths' => __('admin/product_imports/batches.product_edit.errors.duplicate_category'),
                ]);
            }

            $seen_category_ids[$category_id] = true;
            $resolved_category_ids[]         = $category_id;
        }

        foreach ($resolved_category_ids as $category_id) {
            CategoryProduct::query()->create([
                'product_id'  => $product_id,
                'category_id' => $category_id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductAttributesFromFormData(int $product_id, array $data, int $default_shop_language_id): void
    {
        ProductToAttribute::query()->where('product_id', $product_id)->delete();

        $attribute_rows_to_create = [];
        $seen_attribute_pairs     = [];

        $attributes_selected_by_language = Arr::get($data, 'attributes_selected_by_language', []);
        if (is_array($attributes_selected_by_language)) {
            foreach ($attributes_selected_by_language as $shop_language_id => $attribute_ids) {
                $resolved_shop_language_id = $this->resolveAttributeRowLanguageId($shop_language_id, $default_shop_language_id);

                if (! is_array($attribute_ids)) {
                    continue;
                }

                foreach ($attribute_ids as $attribute_id) {
                    $resolved_attribute_id = (int) $attribute_id;
                    if ($resolved_attribute_id <= 0) {
                        continue;
                    }

                    $pair_key = ($resolved_shop_language_id ?? 0).':'.$resolved_attribute_id;
                    if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                        throw ValidationException::withMessages([
                            "attributes_selected_by_language.$shop_language_id" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    $seen_attribute_pairs[$pair_key] = true;
                    $attribute_rows_to_create[]      = [
                        'attribute_id'     => $resolved_attribute_id,
                        'shop_language_id' => $resolved_shop_language_id,
                        'text'             => '',
                    ];
                }
            }
        }

        $attributes_custom_by_language = Arr::get($data, 'attributes_custom_by_language', []);
        if (is_array($attributes_custom_by_language)) {
            foreach ($attributes_custom_by_language as $shop_language_id => $attribute_rows) {
                $resolved_shop_language_id = $this->resolveAttributeRowLanguageId($shop_language_id, $default_shop_language_id);

                if (! is_array($attribute_rows)) {
                    continue;
                }

                foreach ($attribute_rows as $row_index => $attribute_row) {
                    if (! is_array($attribute_row)) {
                        continue;
                    }

                    $attribute_text                                = (string) Arr::get($attribute_row, 'text', '');
                    [$attribute_paths, $duplicate_attribute_paths] = $this->parseHierarchyPathsWithDuplicates(
                        (string) Arr::get($attribute_row, 'attribute_name', ''),
                        false
                    );

                    if ($duplicate_attribute_paths !== []) {
                        throw ValidationException::withMessages([
                            "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                        ]);
                    }

                    foreach ($attribute_paths as $attribute_path) {
                        $attribute_id = $this->resolveOrCreateAttributeIdByPath($attribute_path, $resolved_shop_language_id);
                        $pair_key     = ($resolved_shop_language_id ?? 0).':'.$attribute_id;

                        if (array_key_exists($pair_key, $seen_attribute_pairs)) {
                            throw ValidationException::withMessages([
                                "attributes_custom_by_language.$shop_language_id.$row_index.attribute_name" => __('admin/product_imports/batches.product_edit.errors.duplicate_attribute'),
                            ]);
                        }

                        $seen_attribute_pairs[$pair_key] = true;
                        $attribute_rows_to_create[]      = [
                            'attribute_id'     => $attribute_id,
                            'shop_language_id' => $resolved_shop_language_id,
                            'text'             => $attribute_text,
                        ];
                    }
                }
            }
        }

        foreach ($attribute_rows_to_create as $attribute_row_to_create) {
            ProductToAttribute::query()->create([
                'product_id'       => $product_id,
                'attribute_id'     => (int) $attribute_row_to_create['attribute_id'],
                'shop_language_id' => $attribute_row_to_create['shop_language_id'] !== null
                    ? (int) $attribute_row_to_create['shop_language_id']
                    : null,
                'text'             => (string) $attribute_row_to_create['text'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncProductManufacturerBrandFromFormData(int $product_id, array $data): void
    {
        $manufacturer_id = (int) Arr::get($data, 'manufacturer_id', 0);
        $brand_id        = (int) Arr::get($data, 'brand_id', 0);

        if ($manufacturer_id <= 0 && $brand_id <= 0) {
            ProductToManufacturerBrand::query()
                ->where('product_id', $product_id)
                ->delete();

            return;
        }

        ProductToManufacturerBrand::query()->updateOrCreate(
            [
                'product_id' => $product_id,
            ],
            [
                'manufacturer_id' => $manufacturer_id > 0 ? $manufacturer_id : null,
                'brand_id'        => $brand_id > 0 ? $brand_id : null,
            ]
        );
    }

    /**
     * @return array{0: list<list<string>>, 1: list<string>}
     */
    private function parseHierarchyPathsWithDuplicates(string $raw_value, bool $allow_hierarchy = true): array
    {
        $normalized_input = Str::trim($raw_value);
        if ($normalized_input === '') {
            return [[], []];
        }

        $path_chunks     = preg_split('/\s*\|\s*/u', $normalized_input) ?: [];
        $paths           = [];
        $seen_paths      = [];
        $duplicate_paths = [];

        foreach ($path_chunks as $path_chunk) {
            $path_chunk = Str::trim((string) $path_chunk);
            if ($path_chunk === '') {
                continue;
            }

            $path_segments = $allow_hierarchy
                ? (preg_split('/\s*>\s*/u', $path_chunk) ?: [])
                : [$path_chunk];
            $path_segments = array_values(array_filter(
                array_map(static fn ($segment): string => Str::trim((string) $segment), $path_segments),
                static fn (string $segment): bool => $segment !== '',
            ));

            if ($path_segments === []) {
                continue;
            }

            $path_key = Str::lower($allow_hierarchy ? implode(' > ', $path_segments) : implode(' ', $path_segments));
            if (array_key_exists($path_key, $seen_paths)) {
                $duplicate_paths[] = $allow_hierarchy ? implode(' > ', $path_segments) : implode(' ', $path_segments);

                continue;
            }

            $seen_paths[$path_key] = true;
            $paths[]               = $path_segments;
        }

        return [$paths, array_values(array_unique($duplicate_paths))];
    }

    /**
     * @param  list<string>  $category_path
     */
    private function resolveOrCreateCategoryIdByPath(array $category_path, int $shop_language_id): ?int
    {
        $category_path = array_values(array_filter(
            array_map(static fn ($segment): string => Str::trim((string) $segment), $category_path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($category_path === []) {
            return null;
        }

        $parent_category_id = null;

        foreach ($category_path as $category_name) {
            $existing_category_id = $this->findCategoryIdByParentAndName($parent_category_id, $category_name, $shop_language_id);

            if ($existing_category_id === null) {
                $category = Category::query()->create([
                    'parent_id'  => $parent_category_id,
                    'sort_order' => 0,
                    'is_active'  => true,
                ]);

                $existing_category_id = (int) $category->id;
            }

            $this->ensureCategoryDescription($existing_category_id, $shop_language_id, $category_name);
            $parent_category_id = $existing_category_id;
        }

        return $parent_category_id;
    }

    private function findCategoryIdByParentAndName(?int $parent_category_id, string $category_name, int $shop_language_id): ?int
    {
        $normalized_category_name = Str::lower(Str::trim($category_name));
        if ($normalized_category_name === '') {
            return null;
        }

        $query = Category::query()
            ->select('categories.id')
            ->join('category_descriptions', 'category_descriptions.category_id', '=', 'categories.id')
            ->whereRaw('LOWER(category_descriptions.name) = ?', [$normalized_category_name])
            ->when(
                $parent_category_id === null,
                static fn ($builder) => $builder->whereNull('categories.parent_id'),
                static fn ($builder) => $builder->where('categories.parent_id', $parent_category_id),
            )
            ->orderByRaw(
                'CASE WHEN category_descriptions.shop_language_id = ? THEN 0 ELSE 1 END',
                [$shop_language_id]
            )
            ->orderBy('categories.id');

        $category_id = $query->value('categories.id');

        return $category_id !== null ? (int) $category_id : null;
    }

    private function ensureCategoryDescription(int $category_id, int $shop_language_id, string $category_name): void
    {
        $clean_category_name = Str::trim($category_name);
        if ($clean_category_name === '') {
            return;
        }

        CategoryDescription::query()->firstOrCreate(
            [
                'category_id'      => $category_id,
                'shop_language_id' => $shop_language_id,
            ],
            [
                'name'             => $clean_category_name,
                'description'      => null,
                'h1_title'         => $clean_category_name,
                'meta_title'       => $clean_category_name,
                'meta_description' => null,
                'meta_keywords'    => null,
            ]
        );
    }

    /**
     * @param  list<string>  $attribute_path
     */
    private function resolveOrCreateAttributeIdByPath(array $attribute_path, ?int $shop_language_id): int
    {
        $attribute_path = array_values(array_filter(
            array_map(static fn ($segment): string => Str::trim((string) $segment), $attribute_path),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($attribute_path === []) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        $resolved_attribute_id = null;

        foreach ($attribute_path as $attribute_name) {
            $existing_attribute_query = AttributeDescription::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($attribute_name)])
                ->when(
                    $shop_language_id !== null,
                    static fn ($query) => $query->where('shop_language_id', $shop_language_id),
                    static fn ($query) => $query->whereNull('shop_language_id'),
                );

            $existing_attribute_id = $existing_attribute_query->value('attribute_id');

            if ($existing_attribute_id !== null) {
                $resolved_attribute_id = (int) $existing_attribute_id;

                continue;
            }

            $attribute = Attribute::query()->create([
                'sort_order' => 1,
                'is_active'  => true,
            ]);

            $resolved_attribute_id = (int) $attribute->id;

            AttributeDescription::query()->firstOrCreate([
                'attribute_id'     => $resolved_attribute_id,
                'shop_language_id' => $shop_language_id,
            ], [
                'name' => $attribute_name,
            ]);
        }

        if ($resolved_attribute_id <= 0) {
            throw ValidationException::withMessages([
                'attributes_custom_by_language' => __('admin/product_imports/batches.product_edit.errors.invalid_attribute'),
            ]);
        }

        return $resolved_attribute_id;
    }

    private function resolveAttributeRowLanguageId(int|string|null $shop_language_id, int $default_shop_language_id): ?int
    {
        $resolved_shop_language_id = is_numeric($shop_language_id) ? (int) $shop_language_id : 0;

        if ($resolved_shop_language_id > 0) {
            return $resolved_shop_language_id;
        }

        if ($default_shop_language_id > 0) {
            return $default_shop_language_id;
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function queueProductUpdateViaApi(Product $product): array
    {
        $summary = [
            'products_total'              => 1,
            'shops_total'                 => 0,
            'updates_queued'              => 0,
            'already_failed'              => 0,
            'already_queued_or_exported'  => 0,
            'skipped_not_bound'           => 0,
            'skipped_without_external_id' => 0,
            'failed_created'              => 0,
            'errors'                      => 0,
        ];

        $form_data           = $this->form->getState();
        $update_instructions = $this->buildUpdateInstructionsFromModes($product, $form_data);

        if ($update_instructions === []) {
            return $summary;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($shop_ids === []) {
            $summary['skipped_not_bound'] = 1;

            return $summary;
        }

        return app(ProductUpdateQueueService::class)->queueForEditProductApi(
            $product,
            $shop_ids,
            $update_instructions,
            is_numeric(auth()->id()) ? (int) auth()->id() : null,
        );
    }

    /**
     * @param  array<string, mixed>  $form_data
     * @return array<string, mixed>
     */
    private function buildUpdateInstructionsFromModes(Product $product, array $form_data): array
    {
        $mode_state = Arr::get($form_data, 'api_update_modes', []);
        if (! is_array($mode_state) || $mode_state === []) {
            return [];
        }

        $value_map    = $this->resolveUpdateInstructionValueMap($product);
        $instructions = [];

        foreach (self::API_UPDATE_FIELD_MAP as $group_key => $group_config) {
            $sheet_name = (string) Arr::get($group_config, 'sheet', '');
            $fields     = Arr::get($group_config, 'fields', []);

            if ($sheet_name === '' || ! is_array($fields) || $fields === []) {
                continue;
            }

            $sheet_fields = [];

            foreach ($fields as $field_key) {
                $mode   = Str::lower(Str::trim((string) Arr::get($mode_state, $group_key.'.'.$field_key, 'skip')));
                $action = $this->resolveUpdateInstructionActionByMode($mode);
                if ($action === 'skip') {
                    continue;
                }

                $sheet_fields[$field_key] = [
                    'action' => $action,
                    'value'  => $action === 'delete' ? null : Arr::get($value_map, $group_key.'.'.$field_key),
                ];
            }

            if ($sheet_fields === []) {
                continue;
            }

            $instructions[$sheet_name] = [
                'fields' => $sheet_fields,
            ];
        }

        return $instructions;
    }

    private function resolveUpdateInstructionActionByMode(string $mode): string
    {
        return match ($mode) {
            'delete' => 'delete',
            'update' => 'set',
            default  => 'skip',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUpdateInstructionValueMap(Product $product): array
    {
        $product->loadMissing([
            'descriptions',
            'images',
            'categories.descriptions',
            'productToAttributes',
            'specials',
            'discounts',
            'productToManufacturerBrand.manufacturer.descriptions',
            'productToManufacturerBrand.brand.descriptions',
        ]);

        $category_names = $product->categories
            ->map(static fn (Category $category): string => (string) ($category->descriptions->sortBy('id')->first()?->name ?? ''))
            ->filter(static fn (string $name): bool => Str::trim($name) !== '')
            ->values()
            ->all();

        $attribute_pairs = $product->productToAttributes
            ->map(static fn (ProductToAttribute $attribute): array => [
                'attribute_id'     => (int) ($attribute->attribute_id ?? 0),
                'shop_language_id' => (int) ($attribute->shop_language_id ?? 0),
            ])
            ->filter(static fn (array $pair): bool => $pair['attribute_id'] > 0 && $pair['shop_language_id'] > 0)
            ->values()
            ->all();

        $attribute_name_map = AttributeDescription::getNameMapByAttributeLanguagePairs($attribute_pairs);

        $attribute_names = [];
        $attribute_texts = [];
        foreach ($product->productToAttributes as $product_to_attribute) {
            $attribute_id     = (int) ($product_to_attribute->attribute_id ?? 0);
            $shop_language_id = (int) ($product_to_attribute->shop_language_id ?? 0);
            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $attribute_name = (string) ($attribute_name_map[$attribute_id.':'.$shop_language_id] ?? '');
            if (Str::trim($attribute_name) !== '') {
                $attribute_names[] = $attribute_name;
            }

            $attribute_text = Str::trim((string) ($product_to_attribute->text ?? ''));
            if ($attribute_text !== '') {
                $attribute_texts[] = $attribute_text;
            }
        }

        $seo_urls = SeoUrl::query()
            ->where('seoable_type', Product::class)
            ->where('seoable_id', (int) $product->id)
            ->orderBy('id')
            ->get();

        $product_descriptions = $product->descriptions
            ->mapWithKeys(static fn (ProductDescription $description): array => [
                (int) $description->shop_language_id => [
                    'name'             => $description->name,
                    'description'      => $description->description,
                    'meta_title'       => $description->meta_title,
                    'meta_description' => $description->meta_description,
                    'meta_keywords'    => $description->meta_keywords,
                ],
            ])
            ->toArray();

        return [
            'product' => [
                'model'          => $product->model,
                'sku'            => $product->sku,
                'ean'            => $product->ean,
                'quantity'       => $product->quantity,
                'minimum'        => $product->minimum,
                'image'          => $product->image,
                'price'          => $product->price,
                'manufacturer'   => (string) ($product->productToManufacturerBrand?->manufacturer?->descriptions->sortBy('id')->first()?->name ?? ''),
                'brand'          => (string) ($product->productToManufacturerBrand?->brand?->descriptions->sortBy('id')->first()?->name ?? ''),
                'is_active'      => (bool) $product->is_active,
                'date_available' => $this->normalizeDateTimeValue($product->date_available),
                'date_added'     => $this->normalizeDateTimeValue($product->date_added),
            ],
            'description' => [
                'name'             => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'name'))->toArray(),
                'description'      => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'description'))->toArray(),
                'meta_title'       => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_title'))->toArray(),
                'meta_description' => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_description'))->toArray(),
                'meta_keywords'    => collect($product_descriptions)->map(static fn (array $row): mixed => Arr::get($row, 'meta_keywords'))->toArray(),
            ],
            'image' => [
                'image'      => $product->images->pluck('image')->values()->all(),
                'sort_order' => $product->images->pluck('sort_order')->values()->all(),
            ],
            'product_category' => [
                'category_name' => array_values(array_unique($category_names)),
            ],
            'product_attribute' => [
                'attribute_name' => array_values(array_unique($attribute_names)),
                'attribute_text' => array_values($attribute_texts),
            ],
            'seo_url' => [
                'query_key'   => $seo_urls->pluck('query_key')->values()->all(),
                'query_value' => $seo_urls->pluck('query_value')->values()->all(),
                'keyword'     => $seo_urls->pluck('keyword')->values()->all(),
                'sort_order'  => $seo_urls->pluck('sort_order')->values()->all(),
            ],
            'special' => [
                'user_group_id' => $product->specials->pluck('user_group_id')->values()->all(),
                'price'         => $product->specials->pluck('price')->values()->all(),
                'priority'      => $product->specials->pluck('priority')->values()->all(),
                'date_start'    => $product->specials->pluck('date_start')->values()->all(),
                'date_end'      => $product->specials->pluck('date_end')->values()->all(),
            ],
            'discount' => [
                'user_group_id' => $product->discounts->pluck('user_group_id')->values()->all(),
                'quantity'      => $product->discounts->pluck('quantity')->values()->all(),
                'price'         => $product->discounts->pluck('price')->values()->all(),
                'priority'      => $product->discounts->pluck('priority')->values()->all(),
                'date_start'    => $product->discounts->pluck('date_start')->values()->all(),
                'date_end'      => $product->discounts->pluck('date_end')->values()->all(),
            ],
        ];
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toDateTimeString')) {
            return (string) $value->toDateTimeString();
        }

        $clean_value = trim((string) $value);

        return $clean_value !== '' ? $clean_value : null;
    }
}
