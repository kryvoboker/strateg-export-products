<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Attributes\Pages;

use App\Filament\Resources\Catalog\Attributes\AttributeResource;
use App\Filament\Resources\Catalog\Attributes\Schemas\AttributeForm;
use App\Jobs\ProcessAttributeNameTranslationJob;
use App\Models\Attributes\Attribute;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateAttribute extends CreateRecord
{
    protected static string $resource = AttributeResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Attribute
    {
        /** @var Attribute $attribute */
        $attribute = Attribute::query()->create([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active' => (bool) Arr::get($data, 'is_active', true),
        ]);

        AttributeForm::syncAttributeAdditionalData($attribute, $data);
        ProcessAttributeNameTranslationJob::dispatch(
            (int) $attribute->id,
            collect(Arr::get($data, 'shop_ids', []))
                ->map(static fn ($shop_id): int => (int) $shop_id)
                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                ->unique()
                ->values()
                ->all()
        );

        return $attribute;
    }
}
