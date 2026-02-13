<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shops\Shops\Pages;

use App\Filament\Resources\Shops\Shops\ShopResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    public function getTitle(): string
    {
        return __('admin/shops/shops.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/shops/shops.navigation_label');
    }
}
