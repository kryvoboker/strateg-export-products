<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops\RelationManagers;

use App\Filament\Resources\Catalog\ShopLanguages\Tables\ShopLanguagesTable;
use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use App\Filament\Resources\Trait\Forms\ToggleCheckboxFormTrait;
use App\Models\Shops\Shop;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShopLanguagesRelationManager extends RelationManager
{
    use CommonTextFormTrait, ToggleCheckboxFormTrait;

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
            self::getTextFormField([
                'field_name' => 'code',
                'label'      => __('admin/shops/languages.labels.code'),
                'max_length' => 10,
                'placeholder'=> 'uk, en, de',
                'rules'      => [
                    'required', 'string', 'max:10',
                    Rule::unique('shop_languages', 'code')->ignore($record?->id),
                ],
            ]),

            self::getTextFormField([
                'field_name' => 'name',
                'label'      => __('admin/shops/languages.labels.name'),
                'max_length' => 100,
                'placeholder'=> 'Українська',
                'rules'      => ['required', 'string', 'max:100'],
            ]),

            self::getIsActiveFormField([
                'helper_text' => __('admin/shops/languages.helpers.is_active'),
            ]),
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
