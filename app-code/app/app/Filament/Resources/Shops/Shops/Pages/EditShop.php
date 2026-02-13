<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\Shops\Pages;

use App\Filament\Resources\Shops\Shops\ShopResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/shops/shops.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/shops/shops.navigation_label');
    }
}
