<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Users\Tables;

use App\Filament\Resources\Trait\Filters\BooleanFilterTrait;
use App\Filament\Resources\Trait\Tables\BooleanTableTrait;
use App\Filament\Resources\Trait\Tables\CommonTextTableTrait;
use App\Filament\Resources\Trait\Tables\DateTableTrait;
use App\Filament\Resources\Trait\Tables\ImageTableTrait;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class UsersTable
{
    use CommonTextTableTrait, BooleanTableTrait, DateTableTrait, ImageTableTrait, BooleanFilterTrait;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                self::getTextTableField([
                    'field_name' => 'name',
                    'label'      => __('admin/default.columns.name'),
                ]),

                self::getTextTableField([
                    'field_name' => 'lastname',
                    'label'      => __('admin/default.columns.lastname'),
                ]),

                self::getTextTableField([
                    'field_name' => 'email',
                    'label'      => __('admin/default.columns.email'),
                ]),

                self::getTextTableField([
                    'field_name'                   => 'telephone',
                    'label'                        => __('admin/default.columns.telephone'),
                    'format_state_using_cb'        => function ($state) {
                        return parse_telephone($state);
                    },
                    'is_toggled_hidden_by_default' => true,
                ]),

                self::getImageTableField([
                    'field_name'        => 'avatar',
                    'label'             => __('admin/default.columns.avatar'),
                    'image_size'        => (int)config('app.images.user.preview_in_list_in_admin.width'),
                    'circular'          => true,
                    'default_image_url' => Storage::url(config('app.images.user.no_image')),
                ]),

                self::getIsActiveTableField(),

                self::getEmailVerifiedAtTableField(),

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
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records) {
                            $denied_emails = config('app.denied_delete_emails', []);

                            // Check if any selected record has an email in denied list
                            if ($records->pluck('email')->intersect($denied_emails)->isNotEmpty()) {
                                Notification::make()
                                    ->title(__('admin/default.errors.title'))
                                    ->body(__('admin/users/users.errors.cant_delete_special_user'))
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }
}
