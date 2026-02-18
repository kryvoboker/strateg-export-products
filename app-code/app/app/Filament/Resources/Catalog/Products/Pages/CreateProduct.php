<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalog\Products\Pages;

use App\Filament\Resources\Catalog\Products\ProductResource;
use App\Jobs\ProcessAttributeNameTranslationJob;
use App\Jobs\ProcessCategoryNameTranslationJob;
use App\Models\Categories\CategoryProduct;
use App\Models\Products\Product;
use App\Models\Products\ProductShop;
use App\Models\Products\ProductToAttribute;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        if (! $this->record instanceof Product) {
            return;
        }

        $product_id = (int) $this->record->id;

        $this->dispatchAttributeNameTranslationJobs($product_id);
        $this->dispatchCategoryNameTranslationJobs($product_id);
    }

    private function dispatchAttributeNameTranslationJobs(int $product_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        $attribute_ids = ProductToAttribute::getUniqueAttributeIdsByProductId($product_id);
        if ($attribute_ids === []) {
            return;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $bind_shop_id = (int) Arr::get($this->data, 'bind_shop_id', 0);
        if ($bind_shop_id > 0 && ! in_array($bind_shop_id, $shop_ids, true)) {
            $shop_ids[] = $bind_shop_id;
        }

        foreach ($attribute_ids as $attribute_id) {
            ProcessAttributeNameTranslationJob::dispatchSync(
                $attribute_id,
                $shop_ids
            );
        }
    }

    private function dispatchCategoryNameTranslationJobs(int $product_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        $category_ids = CategoryProduct::getUniqueCategoryIdsByProductId($product_id);
        if ($category_ids === []) {
            return;
        }

        $shop_ids = ProductShop::query()
            ->where('product_id', $product_id)
            ->pluck('shop_id')
            ->map(static fn ($shop_id): int => (int) $shop_id)
            ->filter(static fn (int $shop_id): bool => $shop_id > 0)
            ->unique()
            ->values()
            ->all();

        $bind_shop_id = (int) Arr::get($this->data, 'bind_shop_id', 0);
        if ($bind_shop_id > 0 && ! in_array($bind_shop_id, $shop_ids, true)) {
            $shop_ids[] = $bind_shop_id;
        }

        foreach ($category_ids as $category_id) {
            ProcessCategoryNameTranslationJob::dispatchSync(
                $category_id,
                $shop_ids
            );
        }
    }
}
