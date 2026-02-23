<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes\Pages;

use App\Filament\Resources\ProductDeletes\ProductDeleteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductDeletes extends ListRecords
{
    protected static string $resource = ProductDeleteResource::class;

    protected function getTablePollingInterval(): ?string
    {
        return '5s';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin/product_deletes/batches.actions.create')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_deletes/batches.navigation_label');
    }

    public function getHeading(): ?string
    {
        return __('admin/product_deletes/batches.navigation_label');
    }
}
