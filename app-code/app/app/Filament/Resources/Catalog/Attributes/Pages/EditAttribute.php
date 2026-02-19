<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Attributes\Pages;

use App\Filament\Resources\Catalog\Attributes\AttributeResource;
use App\Filament\Resources\Catalog\Attributes\Schemas\AttributeForm;
use App\Filament\Resources\Trait\EditPageTrait;
use App\Jobs\ProcessAttributeNameTranslationJob;
use App\Models\Attributes\Attribute;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class EditAttribute extends EditRecord
{
    use EditPageTrait;

    #[Locked]
    public Model|int|string|null|Attribute $record;

    protected static string $resource = AttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record instanceof Attribute) {
            $data['attribute_name'] = (string) ($this->record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '');
            $data['shop_ids']       = $this->record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model|Attribute $record, array $data): Attribute
    {
        $record->update([
            'sort_order' => (int) Arr::get($data, 'sort_order', 1),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        AttributeForm::syncAttributeAdditionalData($record, $data);
        ProcessAttributeNameTranslationJob::dispatch(
            (int) $record->id,
            collect(Arr::get($data, 'shop_ids', []))
                ->map(static fn ($shop_id): int => (int) $shop_id)
                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                ->unique()
                ->values()
                ->all()
        );

        return $record;
    }
}
