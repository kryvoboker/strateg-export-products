<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops\RelationManagers;

use App\Filament\Resources\Catalog\ShopLanguages\Tables\ShopLanguagesTable;
use App\Models\Shops\Shop;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShopLanguagesRelationManager extends RelationManager
{
    protected static string $relationship = 'shopLanguage';

    /**
     * @param Model  $ownerRecord
     * @param string $pageClass
     *
     * @return string
     */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin/shops/languages.navigation_label');
    }

    /**
     * @param Schema $schema
     *
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        /** @var Shop|null $record */
        $record = $schema->getRecord();

        // Custom inline form without shop selector; shop is inferred from relation.
        return $schema->components([
            TextInput::make('code')
                ->label(__('admin/shops/languages.labels.code'))
                ->maxLength(10)
                ->rules([
                    'required', 'string', 'max:10',
                    Rule::unique('shop_languages', 'code')->ignore($record?->id),
                ])
                ->placeholder('uk, en, de')
                ->required(),

            TextInput::make('name')
                ->label(__('admin/shops/languages.labels.name'))
                ->maxLength(100)
                ->rules(['required', 'string', 'max:100'])
                ->placeholder('Українська')
                ->required(),

            Toggle::make('is_active')
                ->label(__('admin/default.labels.is_active'))
                ->helperText(__('admin/shops/languages.helpers.is_active'))
                ->default(true),
        ]);
    }

    /**
     * @param Table $table
     *
     * @return Table
     */
    public function table(Table $table): Table
    {
        return ShopLanguagesTable::configure($table)
            ->recordTitleAttribute('name');
    }
}
