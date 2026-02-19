<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\Shops\Schemas;

use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use App\Filament\Resources\Trait\Forms\ToggleCheckboxFormTrait;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Schema;

class ShopForm
{
    use CommonTextFormTrait, ToggleCheckboxFormTrait;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::getTextFormField([
                    'field_name'  => 'name',
                    'label'       => __('admin/default.labels.name'),
                    'max_length'  => 255,
                    'placeholder' => 'My Awesome Shop',
                    'rules'       => ['required', 'string', 'max:255'],
                ]),

                self::getTextFormField([
                    'field_name'  => 'type',
                    'label'       => __('admin/shops/shops.labels.type'),
                    'max_length'  => 50,
                    'placeholder' => 'opencart | shopify | custom',
                    'rules'       => ['required', 'string', 'max:50'],
                    'unique'      => null,
                ]),

                self::getUrlFormField([
                    'field_name' => 'base_url',
                    'label'      => __('admin/shops/shops.labels.base_url'),
                    'rules'      => ['required', 'string', 'max:255', 'url'],
                ]),

                self::getUrlFormField([
                    'field_name'  => 'api_url',
                    'label'       => __('admin/shops/shops.labels.api_url'),
                    'helper_text' => __('admin/shops/shops.helpers.api_url'),
                    'placeholder' => 'https://example.com/api',
                    'required'    => false,
                ]),

                self::getTextFormField([
                    'field_name'  => 'part_api_url_export_prods',
                    'label'       => __('admin/shops/shops.labels.part_api_url_export_prods'),
                    'helper_text' => __('admin/shops/shops.helpers.part_api_url_export_prods'),
                    'placeholder' => '/export/products',
                    'required'    => false,
                ]),

                self::getTextFormField([
                    'field_name'  => 'part_api_url_restore_prods',
                    'label'       => __('admin/shops/shops.labels.part_api_url_restore_prods'),
                    'helper_text' => __('admin/shops/shops.helpers.part_api_url_restore_prods'),
                    'placeholder' => '/restore/products',
                    'required'    => false,
                ]),

                self::getTextFormField([
                    'field_name'  => 'part_api_url_update_prods',
                    'label'       => __('admin/shops/shops.labels.part_api_url_update_prods'),
                    'helper_text' => __('admin/shops/shops.helpers.part_api_url_update_prods'),
                    'placeholder' => '/update/products',
                    'required'    => false,
                ]),

                self::getTextFormField([
                    'field_name'  => 'part_api_url_login',
                    'label'       => __('admin/shops/shops.labels.part_api_url_login'),
                    'helper_text' => __('admin/shops/shops.helpers.part_api_url_login'),
                    'placeholder' => '/login',
                    'required'    => false,
                ]),

                self::getTextFormField([
                    'field_name'          => 'api_token',
                    'label'               => __('admin/shops/shops.labels.api_token'),
                    'helper_text'         => __('admin/shops/shops.helpers.api_token'),
                    'max_length'          => 500,
                    'required'            => false,
                    'placeholder'         => 'HPrznug57OoJzpMOLLCiuhZb7PJIrrAlUinYc...',
                    'is_column_span_full' => true,
                ]),

                self::getIsActiveFormField([
                    'helper_text'         => __('admin/shops/shops.helpers.is_active'),
                    'is_column_span_full' => true,
                ]),

                KeyValue::make('options')
                    ->label(__('admin/shops/shops.labels.options'))
                    ->helperText(__('admin/shops/shops.helpers.options'))
                    ->addActionLabel(__('admin/shops/shops.labels.add_option'))
                    ->keyLabel(__('admin/shops/shops.labels.option_key'))
                    ->valueLabel(__('admin/shops/shops.labels.option_value'))
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }
}
