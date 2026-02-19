<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands\Pages;

use App\Filament\Resources\Catalog\Brands\BrandResource;
use App\Filament\Resources\Catalog\Brands\Schemas\BrandForm;
use App\Models\Brands\Brand;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Brand
    {
        /** @var Brand $brand */
        $brand = Brand::query()->create([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        BrandForm::syncBrandAdditionalData($brand, $data);

        return $brand;
    }
}
