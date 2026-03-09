<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Pages;

use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Models\Products\Imports\ProductImportBatch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
class ViewProductImportBatch extends ViewRecord
{
    protected static string $resource = ProductImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadErrorLog')
                ->label(__('admin/product_imports/batches.actions.download_error_log'))
                ->visible(fn (): bool => $this->getTypedRecord()->hasErrorLog())
                ->url(fn (): ?string => $this->getTypedRecord()->getErrorLogUrl())
                ->openUrlInNewTab(),
            Action::make('back')
                ->label(__('actions.close'))
                ->url(static::getResource()::getUrl('index')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.titles.view', [
            'id' => (string) $this->getTypedRecord()->id,
        ]);
    }

    private function getTypedRecord(): ProductImportBatch
    {
        /** @var ProductImportBatch $record */
        $record = $this->getRecord();

        return $record;
    }
}
