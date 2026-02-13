<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Pages;

use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductImportBatches extends ListRecords
{
    protected static string $resource = ProductImportBatchResource::class;

    protected function getTablePollingInterval(): ?string
    {
        return '5s';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin/product_imports/batches.actions.create')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/product_imports/batches.navigation_label');
    }
}
