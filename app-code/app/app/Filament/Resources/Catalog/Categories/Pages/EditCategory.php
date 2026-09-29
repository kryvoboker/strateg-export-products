<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Pages;

use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Trait\EditPageTrait;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Models\Categories\Category;
use App\Models\Categories\CategoryShop;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditCategory extends EditRecord
{
    use EditPageTrait;

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
            $data['shop_bindings'] = $this->record->categoryShops()
                ->orderBy('shop_id')
                ->get(['shop_id', 'external_category_id'])
                ->map(static fn (CategoryShop $category_shop): array => [
                    'shop_id'              => (int) $category_shop->shop_id,
                    'external_category_id' => $category_shop->external_category_id,
                ])
                ->all();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Category $category */
        $category = $record;

        $category->update([
            'parent_id'  => Arr::get($data, 'parent_id'),
            'sort_order' => (int) Arr::get($data, 'sort_order', 0),
            'is_active'  => (bool) Arr::get($data, 'is_active', true),
        ]);

        CategoryForm::syncCategoryAdditionalData($category, $data);
        ProcessCategoryNameTranslationJob::dispatch(
            (int) $category->id,
            CategoryForm::getSelectedShopIds($data),
        );

        return $category;
    }
}
