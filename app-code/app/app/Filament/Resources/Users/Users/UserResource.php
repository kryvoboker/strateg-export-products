<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Users;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Filament\Resources\Trait\Support\TotalModelItemsResourceTrait;
use App\Filament\Resources\Users\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Users\Pages\EditUser;
use App\Filament\Resources\Users\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Users\Tables\UsersTable;
use App\Models\Users\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    use TotalModelItemsResourceTrait;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::User;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|null|UnitEnum $navigationGroup = AdminNavigationGroupEnum::Users;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Signature in the navigation menu (left panel)
     */
    public static function getNavigationLabel(): string
    {
        return __('admin/users/users.navigation_label');
    }

    /**
     * A single model name (e.g. in headings, "Create X" button)
     */
    public static function getModelLabel(): string
    {
        return __('admin/users/users.labels.model');
    }

    /**
     * Plural model name (e.g. in lists, section headings)
     */
    public static function getPluralModelLabel(): string
    {
        return __('admin/users/users.labels.plural_model');
    }
}
