<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops\Schemas;

use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use App\Filament\Resources\Trait\Forms\ToggleCheckboxFormTrait;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Schema;

class ShopForm
{
    use CommonTextFormTrait, ToggleCheckboxFormTrait;

    /**
     * @param Schema $schema
     *
     * @return Schema
     */
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
                    'field_name'          => 'base_url',
                    'label'               => __('admin/shops/shops.labels.base_url'),
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
