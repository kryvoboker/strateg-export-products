<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers\Pages;

use App\Filament\Resources\Catalog\Manufacturers\ManufacturerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListManufacturers extends ListRecords
{
    protected static string $resource = ManufacturerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
