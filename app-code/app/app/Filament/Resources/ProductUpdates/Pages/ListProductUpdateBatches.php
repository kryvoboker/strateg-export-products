<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Pages;

use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductUpdateBatches extends ListRecords
{
    protected static string $resource = ProductUpdateBatchResource::class;

    protected function getTablePollingInterval(): ?string
    {
        return '5s';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin/product_updates/batches.actions.create')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_updates/batches.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/product_updates/batches.navigation_label');
    }
}

