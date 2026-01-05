<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Users\Schemas;

use App\Models\Users\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class UserForm
{
    /**
     * @param Schema $schema
     *
     * @return Schema
     */
    public static function configure(Schema $schema): Schema
    {
        /** @var User|null $record */
        $record = $schema->getRecord();

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('admin/default.labels.name'))
                    ->maxLength(255)
                    ->placeholder('John')
                    ->rules(['string', 'max:255'])
                    ->required(),

                TextInput::make('lastname')
                    ->label(__('admin/default.labels.lastname'))
                    ->maxLength(255)
                    ->placeholder('Doe')
                    ->rules(['nullable', 'string', 'max:255'])
                    ->default(null),

                TextInput::make('email')
                    ->label(__('admin/default.labels.email'))
                    ->maxLength(255)
                    ->placeholder('knur@gamil.com')
                    ->regex(config('app.regex_validate_conditions.email'))
                    ->email()
                    ->rules(['email', 'max:255', Rule::unique('users', 'email')->ignore($record?->id)])
                    ->required(),

                TextInput::make('telephone')
                    ->label(__('admin/default.labels.telephone'))
                    ->maxLength(20)
                    ->placeholder('+380 (96) 690-64-12')
                    ->telRegex(config('app.regex_validate_conditions.telephone'))
                    ->tel()
                    ->rules(['nullable', 'string', 'max:20', Rule::unique('users', 'telephone')->ignore($record?->id), 'regex:' . config('app.regex_validate_conditions.telephone')])
                    ->default(null),

                FileUpload::make('avatar')
                    ->label(__('admin/default.labels.avatar'))
                    ->image() // accept images only
                    ->directory(config('app.images.user.image_path')) // store under avatars folder
                    ->preserveFilenames() // not generate unique names
                    ->maxSize((int)config('app.images.user.upload.max_size_kb'))
                    ->rules(['nullable', 'image', 'max:' . (int)config('app.images.user.upload.max_size_kb')])
                    ->imageEditor()
                    ->imageEditorViewportWidth((int)config('app.images.user.preview_in_page_in_admin.width'))
                    ->imageEditorViewportHeight((int)config('app.images.user.preview_in_page_in_admin.height'))
                    ->imageEditorAspectRatios([
                        '1:1'  => '1:1',
                        '4:3'  => '4:3',
                        '16:9' => '16:9',
                    ])
                    ->nullable()
                    ->default(null),

                DateTimePicker::make('email_verified_at')
                    ->label(__('admin/default.labels.email_verified_at'))
                    ->helperText(__('admin/users/users.helpers.email_verified_at'))
                    ->rules(['nullable', 'date'])
                    ->default(null),

                TextInput::make('password')
                    ->label(__('admin/default.labels.password'))
                    ->helperText(__('admin/users/users.helpers.password'))
                    ->password()
                    ->rules(['nullable', 'string', 'min:3', 'confirmed', 'regex:' . config('app.regex_validate_conditions.password')])
                    ->default(null),

                TextInput::make('password_confirmation')
                    ->label(__('admin/default.labels.password_confirmation'))
                    ->password()
                    ->rules(['nullable', 'required_with:password', 'confirmed'])
                    ->default(null),

                Toggle::make('is_active')
                    ->label(__('admin/default.labels.is_active'))
                    ->helperText(__('admin/users/users.helpers.is_active'))
                    ->default(false),
            ]);
    }
}
