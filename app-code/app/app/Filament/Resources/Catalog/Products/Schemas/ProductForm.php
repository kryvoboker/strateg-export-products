<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Schemas;

use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Shops\Shop;
use App\Models\Shops\ShopLanguage;
use App\Services\Products\ProductResourceOptionsService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('product_edit_tabs')
                    ->tabs([
                        Tab::make('main')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.main'))
                            ->schema([
                                Select::make('bind_shop_id')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.bind_shop_id'))
                                    ->options(fn (?Product $record): array => self::getAvailableShopOptionsForProduct((int) ($record?->id ?? 0)))
                                    ->default(fn (?Product $record): ?int => self::resolveBoundShopId($record))
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                                        $shop_id              = (int) ($state ?? 0);
                                        $product_id           = (int) ($get('product_id') ?? 0);
                                        $language_options     = self::getShopLanguageOptions($shop_id);
                                        $selected_language_id = (int) ($get('bind_shop_language_id') ?? 0);
                                        $set('external_product_id', self::resolveExternalProductIdForProductAndShop($product_id, $shop_id));

                                        if ($selected_language_id > 0 && array_key_exists($selected_language_id, $language_options)) {
                                            return;
                                        }

                                        $set('bind_shop_language_id', array_key_first($language_options));
                                    })
                                    ->searchable()
                                    ->helperText(__('admin/product_imports/batches.product_edit.helpers.bind_shop_id')),
                                Select::make('bind_shop_language_id')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.bind_shop_language_id'))
                                    ->options(fn (callable $get): array => self::getShopLanguageOptions((int) ($get('bind_shop_id') ?? 0)))
                                    ->disabled(fn (callable $get): bool => (int) ($get('bind_shop_id') ?? 0) <= 0)
                                    ->searchable(),
                                TextInput::make('product_id')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.product_id'))
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('model')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.model'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->maxLength(255),
                                TextInput::make('sku')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.sku'))
                                    ->maxLength(255),
                                TextInput::make('ean')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.ean'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->maxLength(255),
                                TextInput::make('external_product_id')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.external_product_id'))
                                    ->numeric()
                                    ->visible(fn (callable $get): bool => (int) ($get('bind_shop_id') ?? 0) > 0),
                                Select::make('manufacturer_id')
                                    ->label('Manufacturer')
                                    ->options(fn (callable $get): array => self::getManufacturerOptionsByScope(
                                        (int) ($get('bind_shop_id') ?? 0),
                                        (int) ($get('bind_shop_language_id') ?? 0),
                                        [(int) ($get('manufacturer_id') ?? 0)],
                                    ))
                                    ->searchable()
                                    ->preload(),
                                Select::make('brand_id')
                                    ->label('Brand')
                                    ->options(fn (callable $get): array => self::getBrandOptionsByScope(
                                        (int) ($get('bind_shop_id') ?? 0),
                                        (int) ($get('bind_shop_language_id') ?? 0),
                                        [(int) ($get('brand_id') ?? 0)],
                                    ))
                                    ->searchable()
                                    ->preload(),
                                TextInput::make('quantity')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.quantity'))
                                    ->numeric(),
                                TextInput::make('minimum')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.minimum'))
                                    ->numeric(),
                                TextInput::make('image')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.image'))
                                    ->maxLength(3000),
                                TextInput::make('price')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.price'))
                                    ->numeric(),
                                Toggle::make('is_active')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.is_active')),
                                DateTimePicker::make('date_available')
                                    ->format(config('app.datetime_format'))
                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_available')),
                                DateTimePicker::make('date_added')
                                    ->format(config('app.datetime_format'))
                                    ->label(__('admin/product_imports/batches.product_edit.fields.date_added')),
                            ])
                            ->columns(3),
                        Tab::make('descriptions')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.descriptions'))
                            ->schema([
                                Tabs::make('description_language_tabs')
                                    ->tabs(fn (callable $get): array => self::getDescriptionLanguageTabs((int) ($get('bind_shop_id') ?? 0)))
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('images')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.images'))
                            ->schema([
                                Repeater::make('images')
                                    ->schema([
                                        TextInput::make('image')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.image_path'))
                                            ->maxLength(3000),
                                        TextInput::make('sort_order')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.sort_order'))
                                            ->numeric()
                                            ->default(1),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('categories')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.categories'))
                            ->schema([
                                Select::make('category_source_scope')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.source_scope'))
                                    ->options([
                                        'all'  => __('admin/product_imports/batches.product_edit.source_scopes.all'),
                                        'shop' => __('admin/product_imports/batches.product_edit.source_scopes.shop'),
                                    ])
                                    ->default('shop')
                                    ->native(false)
                                    ->live(),
                                Select::make('categories_existing_ids')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.categories_existing_ids'))
                                    ->options(fn (callable $get): array => self::getCategoryOptionsByScope(
                                        (string) ($get('category_source_scope') ?? 'all'),
                                        (int) ($get('bind_shop_id') ?? 0),
                                        (int) ($get('bind_shop_language_id') ?? 0),
                                        is_array($get('categories_existing_ids')) ? $get('categories_existing_ids') : [],
                                    ))
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('admin/product_imports/batches.product_edit.helpers.select_existing_categories')),
                                Textarea::make('categories_custom_paths')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.categories_custom_paths'))
                                    ->rows(3)
                                    ->helperText(__('admin/product_imports/batches.product_edit.helpers.category_separator')),
                            ]),
                        Tab::make('attributes')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.attributes'))
                            ->schema([
                                Select::make('attribute_source_scope')
                                    ->label(__('admin/product_imports/batches.product_edit.fields.source_scope'))
                                    ->options([
                                        'all'  => __('admin/product_imports/batches.product_edit.source_scopes.all'),
                                        'shop' => __('admin/product_imports/batches.product_edit.source_scopes.shop'),
                                    ])
                                    ->default('shop')
                                    ->native(false)
                                    ->live(),
                                Tabs::make('attribute_language_tabs')
                                    ->tabs(fn (callable $get): array => self::getAttributeLanguageTabs(
                                        (int) ($get('bind_shop_id') ?? 0),
                                        (string) ($get('attribute_source_scope') ?? 'all'),
                                        self::resolveAttributeLanguageIdsFromState($get),
                                    ))
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('seo')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.seo'))
                            ->schema([
                                Tabs::make('seo_language_tabs')
                                    ->tabs(function (callable $get): array {
                                        $seo_urls_by_language = $get('seo_urls_by_language');
                                        $seo_language_ids     = is_array($seo_urls_by_language)
                                            ? Arr::map(array_keys($seo_urls_by_language), static fn ($shop_language_id): int => (int) $shop_language_id)
                                            : [];

                                        return self::getSeoUrlLanguageTabs((int) ($get('bind_shop_id') ?? 0), $seo_language_ids);
                                    })
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('pricing')
                            ->label(__('admin/product_imports/batches.product_edit.tabs.pricing'))
                            ->schema([
                                Repeater::make('discounts')
                                    ->label(__('admin/product_imports/batches.product_edit.sections.discounts'))
                                    ->schema([
                                        TextInput::make('user_group_id')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.user_group_id'))
                                            ->numeric()
                                            ->default(1),
                                        TextInput::make('quantity')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.quantity'))
                                            ->numeric()
                                            ->default(1),
                                        TextInput::make('price')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.price'))
                                            ->numeric()
                                            ->default(0),
                                        TextInput::make('priority')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.priority'))
                                            ->numeric()
                                            ->default(1),
                                        DateTimePicker::make('date_start')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_start')),
                                        DateTimePicker::make('date_end')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_end')),
                                    ])
                                    ->columns(3),
                                Repeater::make('specials')
                                    ->label(__('admin/product_imports/batches.product_edit.sections.specials'))
                                    ->schema([
                                        TextInput::make('user_group_id')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.user_group_id'))
                                            ->numeric()
                                            ->default(1),
                                        TextInput::make('price')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.price'))
                                            ->numeric()
                                            ->default(0),
                                        TextInput::make('priority')
                                            ->label(__('admin/product_imports/batches.product_edit.fields.priority'))
                                            ->numeric()
                                            ->default(1),
                                        DateTimePicker::make('date_start')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_start')),
                                        DateTimePicker::make('date_end')
                                            ->format(config('app.datetime_format'))
                                            ->label(__('admin/product_imports/batches.product_edit.fields.date_end')),
                                    ])
                                    ->columns(3),
                            ]),
                        Tab::make('api_update_modes')
                            ->label(__('admin/products/products.api_update.tab_label'))
                            ->schema(self::getApiUpdateModeSections())
                            ->columns(1),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<Section>
     */
    private static function getApiUpdateModeSections(): array
    {
        return [
            Section::make(__('admin/products/products.api_update.sections.product'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.product.sku', __('admin/product_imports/batches.product_edit.fields.sku')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.quantity', __('admin/product_imports/batches.product_edit.fields.quantity')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.minimum', __('admin/product_imports/batches.product_edit.fields.minimum')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.image', __('admin/product_imports/batches.product_edit.fields.image')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.price', __('admin/product_imports/batches.product_edit.fields.price')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.manufacturer', 'Manufacturer'),
                    self::makeApiUpdateModeToggle('api_update_modes.product.brand', 'Brand'),
                    self::makeApiUpdateModeToggle('api_update_modes.product.is_active', __('admin/product_imports/batches.product_edit.fields.is_active')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.date_available', __('admin/product_imports/batches.product_edit.fields.date_available')),
                    self::makeApiUpdateModeToggle('api_update_modes.product.date_added', __('admin/product_imports/batches.product_edit.fields.date_added')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.tabs.descriptions'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.description.name', __('admin/product_imports/batches.product_edit.fields.name')),
                    self::makeApiUpdateModeToggle('api_update_modes.description.description', __('admin/product_imports/batches.product_edit.fields.description')),
                    self::makeApiUpdateModeToggle('api_update_modes.description.meta_title', __('admin/product_imports/batches.product_edit.fields.meta_title')),
                    self::makeApiUpdateModeToggle('api_update_modes.description.meta_description', __('admin/product_imports/batches.product_edit.fields.meta_description')),
                    self::makeApiUpdateModeToggle('api_update_modes.description.meta_keywords', __('admin/product_imports/batches.product_edit.fields.meta_keywords')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.tabs.images'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.image.image', __('admin/product_imports/batches.product_edit.fields.image_path')),
                    self::makeApiUpdateModeToggle('api_update_modes.image.sort_order', __('admin/product_imports/batches.product_edit.fields.sort_order')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.tabs.categories'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.product_category.category_name', __('admin/product_imports/batches.product_edit.fields.category_id')),
                ])
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.tabs.attributes'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.product_attribute.attribute_name', __('admin/product_imports/batches.product_edit.fields.attribute_name')),
                    self::makeApiUpdateModeToggle('api_update_modes.product_attribute.attribute_text', __('admin/product_imports/batches.product_edit.fields.text')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.tabs.seo'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.seo_url.query_key', __('admin/product_imports/batches.product_edit.fields.query_key')),
                    self::makeApiUpdateModeToggle('api_update_modes.seo_url.query_value', __('admin/product_imports/batches.product_edit.fields.query_value')),
                    self::makeApiUpdateModeToggle('api_update_modes.seo_url.keyword', __('admin/product_imports/batches.product_edit.fields.keyword')),
                    self::makeApiUpdateModeToggle('api_update_modes.seo_url.sort_order', __('admin/product_imports/batches.product_edit.fields.sort_order')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.sections.specials'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.special.user_group_id', __('admin/product_imports/batches.product_edit.fields.user_group_id')),
                    self::makeApiUpdateModeToggle('api_update_modes.special.price', __('admin/product_imports/batches.product_edit.fields.price')),
                    self::makeApiUpdateModeToggle('api_update_modes.special.priority', __('admin/product_imports/batches.product_edit.fields.priority')),
                    self::makeApiUpdateModeToggle('api_update_modes.special.date_start', __('admin/product_imports/batches.product_edit.fields.date_start')),
                    self::makeApiUpdateModeToggle('api_update_modes.special.date_end', __('admin/product_imports/batches.product_edit.fields.date_end')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
            Section::make(__('admin/product_imports/batches.product_edit.sections.discounts'))
                ->schema([
                    self::makeApiUpdateModeToggle('api_update_modes.discount.user_group_id', __('admin/product_imports/batches.product_edit.fields.user_group_id')),
                    self::makeApiUpdateModeToggle('api_update_modes.discount.quantity', __('admin/product_imports/batches.product_edit.fields.quantity')),
                    self::makeApiUpdateModeToggle('api_update_modes.discount.price', __('admin/product_imports/batches.product_edit.fields.price')),
                    self::makeApiUpdateModeToggle('api_update_modes.discount.priority', __('admin/product_imports/batches.product_edit.fields.priority')),
                    self::makeApiUpdateModeToggle('api_update_modes.discount.date_start', __('admin/product_imports/batches.product_edit.fields.date_start')),
                    self::makeApiUpdateModeToggle('api_update_modes.discount.date_end', __('admin/product_imports/batches.product_edit.fields.date_end')),
                ])
                ->columns(2)
                ->visible(static fn (?Product $record): bool => $record instanceof Product && $record->exists),
        ];
    }

    private static function makeApiUpdateModeToggle(string $state_path, string $label): ToggleButtons
    {
        return ToggleButtons::make($state_path)
            ->label($label)
            ->options([
                'skip'   => __('admin/products/products.api_update.actions.skip'),
                'delete' => __('admin/products/products.api_update.actions.delete'),
                'update' => __('admin/products/products.api_update.actions.update'),
            ])
            ->colors([
                'skip'   => 'gray',
                'delete' => 'danger',
                'update' => 'success',
            ])
            ->icons([
                'skip'   => Heroicon::PauseCircle,
                'delete' => Heroicon::Trash,
                'update' => Heroicon::CheckCircle,
            ])
            ->inline()
            ->nullable()
            ->helperText(__('admin/products/products.api_update.mode_helper'));
    }

    private static function resolveBoundShopId(?Product $product): ?int
    {
        if (! $product instanceof Product) {
            return null;
        }

        $shop_id = ProductShop::query()
            ->where('product_id', (int) $product->id)
            ->orderBy('id')
            ->value('shop_id');

        return $shop_id !== null ? (int) $shop_id : null;
    }

    private static function resolveExternalProductIdForProductAndShop(int $product_id, int $shop_id): ?int
    {
        if ($product_id <= 0 || $shop_id <= 0) {
            return null;
        }

        $external_product_id = ProductShop::query()
            ->where('product_id', $product_id)
            ->where('shop_id', $shop_id)
            ->orderByDesc('id')
            ->value('external_product_id');

        return is_numeric($external_product_id) ? (int) $external_product_id : null;
    }

    /**
     * @return array<int, string>
     */
    private static function getAvailableShopOptionsForProduct(int $product_id): array
    {
        if ($product_id <= 0) {
            return Shop::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        }

        $bound_shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        if ($bound_shop_ids !== []) {
            return Shop::query()
                ->whereIn('id', $bound_shop_ids)
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray();
        }

        return Shop::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return array<int, string>
     */
    private static function getShopLanguageOptions(int $shop_id): array
    {
        if ($shop_id <= 0) {
            return [];
        }

        return ShopLanguage::query()
            ->where('shop_id', $shop_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * @return array<Tab>
     */
    private static function getDescriptionLanguageTabs(int $shop_id): array
    {
        $shop_languages = self::getShopLanguages($shop_id);
        if ($shop_languages->isEmpty()) {
            return [
                Tab::make('empty_descriptions')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.descriptions'))
                    ->schema([
                        Placeholder::make('descriptions_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->content(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($shop_languages as $shop_language) {
            $shop_language_id = (int) $shop_language->id;

            $tabs[] = Tab::make('description_language_'.$shop_language_id)
                ->label((string) $shop_language->name)
                ->badge((string) $shop_language->code)
                ->schema([
                    TextInput::make("descriptions_by_language.$shop_language_id.name")
                        ->label(__('admin/product_imports/batches.product_edit.fields.name'))
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$shop_language_id.description")
                        ->label(__('admin/product_imports/batches.product_edit.fields.description'))
                        ->rows(3),
                    TextInput::make("descriptions_by_language.$shop_language_id.meta_title")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_title'))
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$shop_language_id.meta_description")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_description'))
                        ->rows(3)
                        ->maxLength(255),
                    Textarea::make("descriptions_by_language.$shop_language_id.meta_keywords")
                        ->label(__('admin/product_imports/batches.product_edit.fields.meta_keywords'))
                        ->rows(3)
                        ->maxLength(255),
                ]);
        }

        return $tabs;
    }

    /**
     * @param  list<int>  $attribute_language_ids
     * @return array<Tab>
     */
    private static function getAttributeLanguageTabs(int $shop_id, string $scope, array $attribute_language_ids = []): array
    {
        $attribute_tab_contexts = app(ProductResourceOptionsService::class)->getAttributeTabContexts(
            $shop_id,
            $attribute_language_ids,
        );

        if ($attribute_tab_contexts->isEmpty()) {
            return [
                Tab::make('empty_attributes')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.attributes'))
                    ->schema([
                        Placeholder::make('attributes_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->content(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($attribute_tab_contexts as $attribute_tab_context) {
            $shop_language_id = (int) $attribute_tab_context['id'];

            $tab = Tab::make('attribute_language_'.$shop_language_id)
                ->label((string) $attribute_tab_context['name'])
                ->schema([
                    Select::make("attributes_selected_by_language.$shop_language_id")
                        ->label(__('admin/product_imports/batches.product_edit.fields.attributes_existing_ids'))
                        ->options(fn (callable $get): array => self::getAttributeOptionsByScope(
                            $scope,
                            (int) ($get('bind_shop_id') ?? 0),
                            $shop_language_id,
                            is_array($get("attributes_selected_by_language.$shop_language_id")) ? $get("attributes_selected_by_language.$shop_language_id") : [],
                        ))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText(__('admin/product_imports/batches.product_edit.helpers.select_existing_attributes')),
                    Repeater::make("attributes_custom_by_language.$shop_language_id")
                        ->hiddenLabel()
                        ->addActionLabel(__('admin/product_imports/batches.product_edit.actions.add_attribute'))
                        ->schema([
                            TextInput::make('attribute_name')
                                ->label(__('admin/product_imports/batches.product_edit.fields.attribute_name'))
                                ->maxLength(255)
                                ->helperText(__('admin/product_imports/batches.product_edit.helpers.attribute_separator')),
                            TextInput::make('text')
                                ->label(__('admin/product_imports/batches.product_edit.fields.text'))
                                ->maxLength(3000),
                        ])
                        ->columns(),
                ]);

            if ($attribute_tab_context['code'] !== '') {
                $tab->badge((string) $attribute_tab_context['code']);
            }

            $tabs[] = $tab;
        }

        return $tabs;
    }

    /**
     * @return list<int>
     */
    private static function resolveAttributeLanguageIdsFromState(callable $get): array
    {
        return collect([
            ...array_keys(is_array($get('attributes_selected_by_language')) ? $get('attributes_selected_by_language') : []),
            ...array_keys(is_array($get('attributes_custom_by_language')) ? $get('attributes_custom_by_language') : []),
            ...array_keys(is_array($get('attributes_by_language')) ? $get('attributes_by_language') : []),
        ])
            ->map(static fn ($shop_language_id): int => is_numeric($shop_language_id) ? (int) $shop_language_id : 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * SEO tabs are rendered from shop languages. If a product has SEO rows but no current shop binding,
     * we fallback to language IDs already present in seo_urls_by_language state.
     *
     * @param  list<int>  $seo_language_ids
     * @return array<Tab>
     */
    private static function getSeoUrlLanguageTabs(int $shop_id, array $seo_language_ids = []): array
    {
        $shop_languages = self::getShopLanguages($shop_id);
        if ($shop_languages->isEmpty() && $seo_language_ids !== []) {
            $seo_language_ids = collect($seo_language_ids)
                ->map(static fn ($shop_language_id): int => (int) $shop_language_id)
                ->filter(static fn (int $shop_language_id): bool => $shop_language_id > 0)
                ->unique()
                ->values()
                ->all();

            if ($seo_language_ids !== []) {
                $shop_languages = ShopLanguage::query()
                    ->whereIn('id', $seo_language_ids)
                    ->orderBy('name')
                    ->get();
            }
        }

        if ($shop_languages->isEmpty()) {
            return [
                Tab::make('empty_seo_urls')
                    ->label(__('admin/product_imports/batches.product_edit.tabs.seo'))
                    ->schema([
                        Placeholder::make('seo_urls_language_empty')
                            ->label(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_title'))
                            ->content(__('admin/product_imports/batches.product_edit.messages.no_shop_languages_content')),
                    ]),
            ];
        }

        $tabs = [];
        foreach ($shop_languages as $shop_language) {
            $shop_language_id = (int) $shop_language->id;

            $tabs[] = Tab::make('seo_urls_language_'.$shop_language_id)
                ->label((string) $shop_language->name)
                ->badge((string) $shop_language->code)
                ->schema([
                    TextInput::make("seo_urls_by_language.$shop_language_id.0.query_key")
                        ->label(__('admin/product_imports/batches.product_edit.fields.query_key'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$shop_language_id.0.query_value")
                        ->label(__('admin/product_imports/batches.product_edit.fields.query_value'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$shop_language_id.0.keyword")
                        ->label(__('admin/product_imports/batches.product_edit.fields.keyword'))
                        ->maxLength(255),
                    TextInput::make("seo_urls_by_language.$shop_language_id.0.sort_order")
                        ->label(__('admin/product_imports/batches.product_edit.fields.sort_order'))
                        ->numeric()
                        ->default(1),
                ]);
        }

        return $tabs;
    }

    /**
     * @return Collection<int, ShopLanguage>
     */
    private static function getShopLanguages(int $shop_id): Collection
    {
        if ($shop_id <= 0) {
            return new Collection();
        }

        return ShopLanguage::query()
            ->where('shop_id', $shop_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private static function getCategoryOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_category_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getCategoryOptionsByScope(
            $scope,
            $shop_id,
            $shop_language_id,
            $selected_category_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function getAttributeOptionsByScope(
        string $scope,
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_attribute_ids = []
    ): array {
        return app(ProductResourceOptionsService::class)->getAttributeOptionsByScope(
            $scope,
            $shop_id,
            $shop_language_id,
            $selected_attribute_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function getManufacturerOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_manufacturer_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getManufacturerOptionsByScope(
            $shop_id,
            $shop_language_id,
            $selected_manufacturer_ids,
        );
    }

    /**
     * @return array<int, string>
     */
    private static function getBrandOptionsByScope(
        int $shop_id = 0,
        int $shop_language_id = 0,
        array $selected_brand_ids = []
    ): array
    {
        return app(ProductResourceOptionsService::class)->getBrandOptionsByScope(
            $shop_id,
            $shop_language_id,
            $selected_brand_ids,
        );
    }
}
