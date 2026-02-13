<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductUpdates\Pages;

use App\Filament\Resources\ProductUpdates\ProductUpdateBatchResource;
use App\Models\Products\Updates\ProductUpdateBatch;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;

class ViewProductUpdateBatch extends ViewRecord
{
    protected static string $resource = ProductUpdateBatchResource::class;

    #[Locked]
    public Model|int|string|null|ProductUpdateBatch $record;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadErrorLog')
                ->label(__('admin/product_updates/batches.actions.download_error_log'))
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
        return __('admin/product_updates/batches.navigation_label') . ' #' . (string) $this->record->id;
    }
}

