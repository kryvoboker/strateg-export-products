<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShopLanguages extends ListRecords
{
    protected static string $resource = ShopLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/shops/languages.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/shops/languages.navigation_label');
    }
}

