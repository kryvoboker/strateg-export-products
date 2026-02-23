<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDeletes\Pages;

use App\Filament\Resources\ProductDeletes\ProductDeleteResource;
use App\Models\Products\Deletes\ProductDeleteBatch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;

class ViewProductDeleteBatch extends ViewRecord
{
    protected static string $resource = ProductDeleteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadErrorLog')
                ->label(__('admin/product_deletes/batches.actions.download_error_log'))
                ->visible(fn (): bool => $this->getTypedRecord()->hasErrorLog())
                ->url(fn (): ?string => $this->getTypedRecord()->hasErrorLog()
                    ? Storage::url((string) $this->getTypedRecord()->getErrorLogPath())
                    : null)
                ->openUrlInNewTab(),
            Action::make('back')
                ->label(__('actions.close'))
                ->url(static::getResource()::getUrl('index')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_deletes/batches.navigation_label').' #'.(string) $this->getTypedRecord()->id;
    }

    private function getTypedRecord(): ProductDeleteBatch
    {
        /** @var ProductDeleteBatch $record */
        $record = $this->getRecord();

        return $record;
    }
}
