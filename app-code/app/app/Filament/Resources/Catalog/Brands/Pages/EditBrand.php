<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Brands\Pages;

use App\Filament\Resources\Catalog\Brands\BrandResource;
use App\Filament\Resources\Catalog\Brands\Schemas\BrandForm;
use App\Filament\Resources\Trait\EditPageTrait;
use App\Models\Brands\Brand;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditBrand extends EditRecord
{
    use EditPageTrait;

    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record instanceof Brand) {
            $data['brand_name'] = (string) ($this->record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '');
            $data['shop_ids']   = $this->record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Brand $brand */
        $brand = $record;

        $brand->update([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        BrandForm::syncBrandAdditionalData($brand, $data);

        return $brand;
    }
}
