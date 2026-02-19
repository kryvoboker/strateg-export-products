<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Users\Schemas;

use App\Filament\Resources\Trait\Forms\CommonTextFormTrait;
use App\Filament\Resources\Trait\Forms\DateFormTrait;
use App\Filament\Resources\Trait\Forms\ImageFormTrait;
use App\Filament\Resources\Trait\Forms\ToggleCheckboxFormTrait;
use App\Models\Users\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class UserForm
{
    use CommonTextFormTrait, DateFormTrait, ImageFormTrait, ToggleCheckboxFormTrait;

    public static function configure(Schema $schema): Schema
    {
        /** @var User|null $record */
        $record = $schema->getRecord();

        return $schema
            ->components([
                self::getTextFormField([
                    'field_name'  => 'name',
                    'label'       => __('admin/default.labels.name'),
                    'max_length'  => 255,
                    'placeholder' => 'John',
                ]),

                self::getTextFormField([
                    'field_name'  => 'lastname',
                    'label'       => __('admin/default.labels.lastname'),
                    'max_length'  => 255,
                    'placeholder' => 'Doe',
                    'rules'       => ['nullable', 'string', 'max:255'],
                ]),

                self::getEmailFormField([
                    'max_length' => 255,
                    'rules'      => ['email', 'max:255', Rule::unique('users', 'email')->ignore($record?->id)],
                ]),

                self::getTelFormField([
                    'max_length' => 20,
                    'rules'      => ['nullable', 'string', 'max:20', Rule::unique('users', 'telephone')->ignore($record?->id), 'regex:'.config('app.regex_validate_conditions.telephone')],
                ]),

                self::getImageFormField([
                    'field_name'   => 'avatar',
                    'label'        => __('admin/default.labels.avatar'),
                    'directory'    => config('app.images.user.image_path'),
                    'max_size'     => (int) config('app.images.user.upload.max_size_kb'),
                    'rules'        => ['nullable', Rule::file()->types(['jpeg', 'jpg', 'png'])->max((int) config('app.images.user.upload.max_size_kb'))],
                    'image_width'  => (int) config('app.images.user.preview_in_page_in_admin.width'),
                    'image_height' => (int) config('app.images.user.preview_in_page_in_admin.height'),
                ]),

                self::getEmailVerifiedAtFormField(),

                TextInput::make('password')
                    ->label(__('admin/default.labels.password'))
                    ->helperText(__('admin/users/users.helpers.password'))
                    ->password()
                    ->rules(['nullable', 'string', 'min:3', 'confirmed', 'regex:'.config('app.regex_validate_conditions.password')])
                    ->default(null),

                TextInput::make('password_confirmation')
                    ->label(__('admin/default.labels.password_confirmation'))
                    ->password()
                    ->rules(['nullable', 'required_with:password', 'confirmed'])
                    ->default(null),

                self::getIsActiveFormField([
                    'helper_text' => __('admin/users/users.helpers.is_active'),
                    'default'     => false,
                ]),

                self::getIsActiveFormField([
                    'helper_text' => __('admin/users/users.helpers.is_active'),
                    'default'     => false,
                ]),
            ]);
    }
}
