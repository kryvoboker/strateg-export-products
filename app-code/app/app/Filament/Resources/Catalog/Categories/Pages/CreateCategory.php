<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Categories\Pages;

use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Resources\Catalog\Categories\Schemas\CategoryForm;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Models\Categories\Category;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Category
    {
        /** @var Category $category */
        $category = Category::query()->create([
            'parent_id'  => $data['parent_id'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active'  => (bool) ($data['is_active'] ?? true),
        ]);

        CategoryForm::syncCategoryAdditionalData($category, $data);
        ProcessCategoryNameTranslationJob::dispatch(
            (int) $category->id,
            collect(Arr::get($data, 'shop_ids', []))
                ->map(static fn ($shop_id): int => (int) $shop_id)
                ->filter(static fn (int $shop_id): bool => $shop_id > 0)
                ->unique()
                ->values()
                ->all()
        );

        return $category;
    }
}
