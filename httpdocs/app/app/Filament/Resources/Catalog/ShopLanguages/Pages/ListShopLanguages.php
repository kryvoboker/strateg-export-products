<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\ShopLanguages\Pages;

use App\Filament\Resources\Catalog\ShopLanguages\Pages\Trait\CommonTrait;
use App\Filament\Resources\Catalog\ShopLanguages\ShopLanguageResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShopLanguages extends ListRecords
{
    use CommonTrait;

    protected static string $resource = ShopLanguageResource::class;

    /**
     * @return array|Action[]|ActionGroup[]
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
