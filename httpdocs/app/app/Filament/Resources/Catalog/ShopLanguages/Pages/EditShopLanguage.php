<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShopLanguage extends EditRecord
{
    protected static string $resource = ShopLanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
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

