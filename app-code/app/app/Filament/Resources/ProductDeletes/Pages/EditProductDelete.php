<?php

namespace App\Filament\Resources\ProductDeletes\Pages;

use App\Filament\Resources\ProductDeletes\ProductDeleteResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProductDelete extends EditRecord
{
    protected static string $resource = ProductDeleteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
