<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductImports\Pages;

use App\Filament\Resources\ProductImports\ProductImportBatchResource;
use App\Models\Products\Imports\ProductImportBatch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;

class ViewProductImportBatch extends ViewRecord
{
    protected static string $resource = ProductImportBatchResource::class;

    #[Locked]
    public Model|int|string|null|ProductImportBatch $record;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadErrorLog')
                ->label(__('admin/product_imports/batches.actions.download_error_log'))
                ->visible(fn (): bool => (bool) $this->record?->hasErrorLog())
                ->url(fn (): ?string => $this->record?->hasErrorLog()
                    ? Storage::url((string) $this->record?->getErrorLogPath())
                    : null)
                ->openUrlInNewTab(),
            Action::make('back')
                ->label(__('actions.close'))
                ->url(static::getResource()::getUrl('index')),
        ];
    }

    public function getTitle(): string
    {
        return __('admin/product_imports/batches.titles.view', [
            'id' => (string) $this->record->id,
        ]);
    }
}
