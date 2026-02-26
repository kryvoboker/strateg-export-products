<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\File;

class ProductImportBatchForm
{
    public static function configure(Schema $schema): Schema
    {
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
                                    ->rules([
                                        'nullable',
                                        File::types(['xlsx', 'xls'])
                                            ->max((int) config('app.sheets.upload.max_size_kb')),
                                    ])
                                    ->directory(sprintf('upload/excel/%s', date('Y/m')))
                                    ->maxSize((int) config('app.sheets.upload.max_size_kb'))
                                    ->preserveFilenames(), // not generate unique names,
                            ]),

                        Tab::make('sheets')
                            ->label(__('admin/product_imports/batches.tabs.google_sheets'))
                            ->schema([
                                TextInput::make('sheets_url')
                                    ->label(__('admin/product_imports/batches.labels.google_sheets_url'))
                                    ->maxLength(2000)
                                    ->url()
                                    ->rules(['nullable', 'url', 'max:2000'])
                                    ->placeholder('https://docs.google.com/spreadsheets/d/...')
                                    ->required(false)
                                    ->columnSpanFull(),
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
                                                DateTimePicker::make('date_start')
                                                    ->format(config('app.datetime_format'))
                                                    ->label('Початок'),
                                                DateTimePicker::make('date_end')
                                                    ->format(config('app.datetime_format'))
                                                    ->label('Завершення'),
                                            ])->columns(3),
                                    ]),

                                Section::make('Спецпропозиції')
                                    ->schema([
                                        Repeater::make('admin_specials')
                                            ->schema([
                                                TextInput::make('user_group_id')->label('Група користувачів')->numeric()->default(1),
                                                TextInput::make('price')->label('Ціна')->numeric()->default(0),
                                                TextInput::make('priority')->label('Пріоритет')->numeric()->default(1),
                                                DateTimePicker::make('date_start')
                                                    ->format(config('app.datetime_format'))
                                                    ->label('Початок'),
                                                DateTimePicker::make('date_end')
                                                    ->format(config('app.datetime_format'))
                                                    ->label('Завершення'),
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
                    ])
                    ->columnSpanFull()
                    ->contained(false),
            ]);
    }
}
