<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Users\Pages;

use App\Filament\Resources\Users\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Get page title
     */
    public function getTitle(): string
    {
        return __('admin/users/users.navigation_label');
    }

    /**
     * Get page heading
     */
    public function getHeading(): ?string
    {
        return __('admin/users/users.navigation_label');
    }
}
