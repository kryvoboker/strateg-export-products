<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ShopForm
{
    /**
     * @param Schema $schema
     *
     * @return Schema
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('admin/default.labels.name'))
                    ->maxLength(255)
                    ->rules(['required', 'string', 'max:255'])
                    ->placeholder('My Awesome Shop')
                    ->required(),

                TextInput::make('type')
                    ->label(__('admin/shops/shops.labels.type'))
                    ->maxLength(50)
                    ->rules(['required', 'string', 'max:50'])
                    ->placeholder('opencart | shopify | custom')
                    ->required(),

                TextInput::make('base_url')
                    ->label(__('admin/shops/shops.labels.base_url'))
                    ->maxLength(255)
                    ->rules(['required', 'url', 'max:255'])
                    ->placeholder('https://example.com')
                    ->required()
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label(__('admin/default.labels.is_active'))
                    ->helperText(__('admin/shops/shops.helpers.is_active'))
                    ->default(true)
                    ->columnSpanFull(),

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
