<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Manufacturers\Pages;

use App\Filament\Resources\Catalog\Manufacturers\ManufacturerResource;
use App\Filament\Resources\Catalog\Manufacturers\Schemas\ManufacturerForm;
use App\Models\Manufacturers\Manufacturer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateManufacturer extends CreateRecord
{
    protected static string $resource = ManufacturerResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Manufacturer
    {
        /** @var Manufacturer $manufacturer */
        $manufacturer = Manufacturer::query()->create([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        ManufacturerForm::syncManufacturerAdditionalData($manufacturer, $data);

        return $manufacturer;
    }
}
