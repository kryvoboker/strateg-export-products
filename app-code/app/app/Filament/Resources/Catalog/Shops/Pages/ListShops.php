<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Shops\Pages;

use App\Filament\Resources\Catalog\Shops\ShopResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
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

