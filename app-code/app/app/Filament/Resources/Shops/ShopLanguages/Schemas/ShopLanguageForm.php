<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\ShopLanguages\Schemas;

use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use App\Filament\Resources\Trait\Forms\ToggleCheckboxFormTrait;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class ShopLanguageForm
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
                Select::make('shop_id')
                    ->label(__('admin/shops/languages.labels.shop'))
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                self::getTextFormField([
                    'field_name'  => 'code',
                    'label'       => __('admin/shops/languages.labels.code'),
                    'max_length'  => 10,
                    'placeholder' => 'uk, en, de',
                    'rules'       => ['alpha', 'lowercase', 'max:10'],
                ]),

                self::getTextFormField([
                    'field_name'          => 'name',
                    'label'               => __('admin/shops/languages.labels.name'),
                    'max_length'          => 100,
                    'rules'               => ['required', 'string', 'max:100'],
                    'placeholder'         => 'Українська',
                    'is_column_span_full' => true,
                ]),

                self::getIsActiveFormField([
                    'helper_text'         => __('admin/shops/languages.helpers.is_active'),
                    'is_column_span_full' => true,
                ]),

                self::getIsDefaultFormField([
                    'helper_text'         => __('admin/shops/languages.helpers.is_default'),
                    'is_column_span_full' => true,
                ]),
            ]);
    }
}
