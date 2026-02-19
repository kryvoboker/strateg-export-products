<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\ShopLanguages\Tables;

use App\Filament\Resources\Trait\Filters\BooleanFilterTrait;
use App\Filament\Resources\Trait\Tables\BooleanTableTrait;
use App\Filament\Resources\Trait\Tables\CommonTextTableTrait;
use App\Filament\Resources\Trait\Tables\DateTableTrait;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Table;

class ShopLanguagesTable
{
    use BooleanFilterTrait, BooleanTableTrait, CommonTextTableTrait, DateTableTrait;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                self::getTextTableField([
                    'field_name' => 'shop.name',
                    'label'      => __('admin/shops/languages.columns.shop'),
                ]),

                self::getTextTableField([
                    'field_name' => 'code',
                    'label'      => __('admin/shops/languages.columns.code'),
                ]),

                self::getTextTableField([
                    'field_name' => 'name',
                    'label'      => __('admin/shops/languages.columns.name'),
                ]),

                self::getIsDefaultTableField([
                    'label' => __('admin/shops/languages.columns.is_default'),
                ]),

                self::getIsActiveTableField(),

                self::getCreatedAtTableField(),
            ])
            ->filters([
                self::getIsActiveFilterField(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
