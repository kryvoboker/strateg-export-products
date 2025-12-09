<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ProductImports\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ProductImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        $sheet_defs = [
            'products'               => 'Products',
            'product_images'         => 'Product Images',
            'product_descriptions'   => 'Product Descriptions',
            'product_discounts'      => 'Product Discounts',
            'product_specials'       => 'Product Specials',
            'seo_urls'               => 'SEO URLs',
            'attributes'             => 'Attributes',
            'attribute_descriptions' => 'Attribute Descriptions',
            'product_to_attributes'  => 'Product to Attributes',
            'categories'             => 'Categories',
            'category_descriptions'  => 'Category Descriptions',
        ];

        $excel_sheet_tabs = [];

        foreach ($sheet_defs as $key => $title) {
            $excel_sheet_tabs[] = Tab::make($title)
                ->schema([
                    TextInput::make("excel_{$key}_sheet")
                        ->label(__('admin/product_imports/batches.labels.sheet_name'))
                        ->default($title),
                    TextInput::make("excel_{$key}_range")
                        ->label(__('admin/product_imports/batches.labels.sheet_range'))
                        ->placeholder('A1:H1000'),
                    TextInput::make("excel_{$key}_header_row")
                        ->numeric()
                        ->minValue(1)
                        ->label(__('admin/product_imports/batches.labels.header_row'))
                        ->default(1),
                ]);
        }

        $gsheet_tabs = [];

        foreach ($sheet_defs as $key => $title) {
            $gsheet_tabs[] = Tab::make($title)
                ->schema([
                    TextInput::make("sheets_{$key}_sheet")
                        ->label(__('admin/product_imports/batches.labels.sheet_name'))
                        ->default($title),
                    TextInput::make("sheets_{$key}_range")
                        ->label(__('admin/product_imports/batches.labels.sheet_range'))
                        ->placeholder('A1:H1000'),
                    TextInput::make("sheets_{$key}_header_row")
                        ->numeric()
                        ->minValue(1)
                        ->label(__('admin/product_imports/batches.labels.header_row'))
                        ->default(1),
                ]);
        }

        return $schema
            ->components([
                Tabs::make(__('admin/product_imports/batches.navigation_label'))
                    ->tabs([
                        Tab::make('excel')
                            ->label(__('admin/product_imports/batches.tabs.excel'))
                            ->schema([
                                FileUpload::make('excel_file')
                                    ->label(__('admin/product_imports/batches.labels.excel_file'))
                                    ->acceptedFileTypes([
                                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                        'application/vnd.ms-excel',
                                        '.xlsx', '.xls',
                                    ])
                                    ->disk('local')
                                    ->directory('imports/products')
                                    ->preserveFilenames()
                                    ->required(),
                                Section::make(__('admin/product_imports/batches.sections.sheets_coordinates'))
                                    ->schema([
                                        Tabs::make('excel_sheets_tabs')->tabs($excel_sheet_tabs)->contained(false),
                                    ]),
                            ]),

                        Tab::make('sheets')
                            ->label(__('admin/product_imports/batches.tabs.google_sheets'))
                            ->schema([
                                TextInput::make('sheets_url')
                                    ->label(__('admin/product_imports/batches.labels.google_sheets_url'))
                                    ->placeholder('https://docs.google.com/spreadsheets/d/...')
                                    ->url(),
                                Section::make(__('admin/product_imports/batches.sections.sheets_coordinates'))
                                    ->schema([
                                        Tabs::make('gsheets_tabs')->tabs($gsheet_tabs)->contained(false),
                                    ]),
                            ]),

                        Tab::make('admin')
                            ->label(__('admin/product_imports/batches.tabs.admin_form'))
                            ->schema([
                                Section::make(__('admin/product_imports/batches.sections.product_fields'))
                                    ->schema([
                                        TextInput::make('admin_model')->label('Model')->maxLength(255),
                                        TextInput::make('admin_sku')->label('SKU')->maxLength(255),
                                        TextInput::make('admin_ean')->label('EAN')->maxLength(255),
                                        TextInput::make('admin_quantity')->label(__('admin/product_imports/batches.labels.quantity'))->numeric()->default(0),
                                        TextInput::make('admin_minimum')->label('Minimum')->numeric()->default(1),
                                        TextInput::make('admin_price')->label(__('admin/product_imports/batches.labels.price'))->numeric()->default(0),
                                        Toggle::make('admin_is_active')->label(__('admin/default.labels.is_active'))->default(false),
                                        Textarea::make('admin_note')->label(__('admin/product_imports/batches.labels.note'))->rows(2),
                                    ]),

                                Section::make('Опис товару (кілька мов)')
                                    ->schema([
                                        Repeater::make('admin_descriptions')
                                            ->schema([
                                                TextInput::make('shop_language_code')->label('Код мови')->maxLength(10),
                                                TextInput::make('name')->label('Назва')->maxLength(255),
                                                Textarea::make('description')->label('Опис')->rows(3),
                                                TextInput::make('meta_title')->label('Meta Title')->maxLength(255),
                                                TextInput::make('meta_description')->label('Meta Description')->maxLength(255),
                                                TextInput::make('meta_keywords')->label('Meta Keywords')->maxLength(255),
                                            ])->columns(2),
                                    ]),

                                Section::make('Зображення')
                                    ->schema([
                                        Repeater::make('admin_images')
                                            ->schema([
                                                FileUpload::make('image')->label('Зображення')->disk('public')->directory('products')->preserveFilenames(),
                                                TextInput::make('sort_order')->label('Порядок')->numeric()->default(1),
                                            ])->columns(2),
                                    ]),

                                Section::make('Знижки')
                                    ->schema([
                                        Repeater::make('admin_discounts')
                                            ->schema([
                                                TextInput::make('user_group_id')->label('Група користувачів')->numeric()->default(1),
                                                TextInput::make('quantity')->label('Кількість')->numeric()->default(1),
                                                TextInput::make('price')->label('Ціна')->numeric()->default(0),
                                                TextInput::make('priority')->label('Пріоритет')->numeric()->default(1),
                                                DateTimePicker::make('date_start')->label('Початок'),
                                                DateTimePicker::make('date_end')->label('Завершення'),
                                            ])->columns(3),
                                    ]),

                                Section::make('Спецпропозиції')
                                    ->schema([
                                        Repeater::make('admin_specials')
                                            ->schema([
                                                TextInput::make('user_group_id')->label('Група користувачів')->numeric()->default(1),
                                                TextInput::make('price')->label('Ціна')->numeric()->default(0),
                                                TextInput::make('priority')->label('Пріоритет')->numeric()->default(1),
                                                DateTimePicker::make('date_start')->label('Початок'),
                                                DateTimePicker::make('date_end')->label('Завершення'),
                                            ])->columns(3),
                                    ]),

                                Section::make('SEO URLs')
                                    ->schema([
                                        Repeater::make('admin_seo_urls')
                                            ->schema([
                                                TextInput::make('shop_language_code')->label('Код мови')->maxLength(10),
                                                TextInput::make('keyword')->label('Keyword')->maxLength(255),
                                                TextInput::make('sort_order')->label('Порядок')->numeric()->default(1),
                                            ])->columns(3),
                                    ]),

                                Section::make('Атрибути')
                                    ->schema([
                                        Repeater::make('admin_attributes')
                                            ->schema([
                                                TextInput::make('attribute_name')->label('Назва атрибуту')->maxLength(255),
                                                TextInput::make('shop_language_code')->label('Код мови')->maxLength(10),
                                                TextInput::make('text')->label('Значення')->maxLength(3000),
                                            ])->columns(3),
                                    ]),

                                Section::make('Категорії')
                                    ->schema([
                                        Repeater::make('admin_categories')
                                            ->schema([
                                                TextInput::make('name')->label('Назва категорії')->maxLength(255),
                                                TextInput::make('parent_name')->label('Батьківська категорія')->maxLength(255),
                                            ])->columns(2),
                                    ]),
                            ]),
                    ])->contained(false),
            ]);
    }
}
