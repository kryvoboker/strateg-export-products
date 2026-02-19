<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers\Pages;

use App\Filament\Resources\Catalog\Manufacturers\ManufacturerResource;
use App\Filament\Resources\Catalog\Manufacturers\Schemas\ManufacturerForm;
use App\Filament\Resources\Trait\EditPageTrait;
use App\Models\Manufacturers\Manufacturer;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class EditManufacturer extends EditRecord
{
    use EditPageTrait;

    #[Locked]
    public Model|int|string|null|Manufacturer $record;

    protected static string $resource = ManufacturerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record instanceof Manufacturer) {
            $data['manufacturer_name'] = (string) ($this->record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '');
            $data['shop_ids']          = $this->record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model|Manufacturer $record, array $data): Manufacturer
    {
        $record->update([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        ManufacturerForm::syncManufacturerAdditionalData($record, $data);

        return $record;
    }
}
