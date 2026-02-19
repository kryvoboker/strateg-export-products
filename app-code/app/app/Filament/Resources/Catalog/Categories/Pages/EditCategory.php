<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Pages;

use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Trait\EditPageTrait;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Models\Categories\Category;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;

class EditCategory extends EditRecord
{
    use EditPageTrait;

    #[Locked]
    public Model|int|string|null|Category $record;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading(__('admin/categories/categories.delete.modal_heading'))
                ->modalDescription(function (): string {
                    $children_count = $this->record instanceof Category
                        ? (int) $this->record->children()->count()
                        : 0;

                    return __('admin/categories/categories.delete.modal_description', [
                        'children_count' => $children_count,
                    ]);
                })
                ->modalSubmitActionLabel(__('admin/categories/categories.delete.modal_confirm')),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->record instanceof Category) {
            $data['category_name'] = (string) ($this->record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name') ?? '');
            $data['shop_ids']      = $this->record->shops()->pluck('shops.id')->map(static fn ($shop_id): int => (int) $shop_id)->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model|Category $record, array $data): Category
    {
        $record->update([
            'parent_id'  => Arr::get($data, 'parent_id'),
            'sort_order' => (int) Arr::get($data, 'sort_order', 0),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        CategoryForm::syncCategoryAdditionalData($record, $data);
        ProcessCategoryNameTranslationJob::dispatch(
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
