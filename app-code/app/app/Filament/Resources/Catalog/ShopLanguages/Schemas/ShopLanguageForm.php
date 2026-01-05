<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ShopLanguageForm
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
                Select::make('shop_id')
                    ->label(__('admin/shops/languages.labels.shop'))
                    ->relationship('shop', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('code')
                    ->label(__('admin/shops/languages.labels.code'))
                    ->maxLength(10)
                    ->placeholder('uk, en, de')
                    ->rules(['required', 'string', 'max:10'])
                    ->required(),

                TextInput::make('name')
                    ->label(__('admin/shops/languages.labels.name'))
                    ->maxLength(100)
                    ->rules(['required', 'string', 'max:100'])
                    ->placeholder('Українська')
                    ->required()
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label(__('admin/default.labels.is_active'))
                    ->helperText(__('admin/shops/languages.helpers.is_active'))
                    ->default(true)
                    ->columnSpanFull(),
            ]);
    }
}
