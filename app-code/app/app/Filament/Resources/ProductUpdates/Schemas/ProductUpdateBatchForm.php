<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Schemas;

use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\File;

class ProductUpdateBatchForm
{
    use CommonTextFormTrait;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make(__('admin/product_updates/batches.navigation_label'))
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
                                    ->preserveFilenames(),
                            ]),
                        Tab::make('sheets')
                            ->label(__('admin/product_imports/batches.tabs.google_sheets'))
                            ->schema([
                                self::getUrlFormField([
                                    'field_name'          => 'sheets_url',
                                    'label'               => __('admin/product_imports/batches.labels.google_sheets_url'),
                                    'placeholder'         => 'https://docs.google.com/spreadsheets/d/...',
                                    'max_length'          => 2000,
                                    'rules'               => ['nullable', 'url', 'max:2000'],
                                    'required'            => false,
                                    'is_column_span_full' => true,
                                ]),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->contained(false),
            ]);
    }
}
