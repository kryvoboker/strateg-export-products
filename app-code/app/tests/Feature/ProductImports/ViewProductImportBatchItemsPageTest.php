<?php

declare(strict_types=1);

namespace Tests\Feature\ProductImports;

use App\Models\Products\Imports\ProductImportBatch;
use App\Models\Users\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ViewProductImportBatchItemsPageTest extends TestCase
{
    public function test_product_import_batch_view_page_opens_without_server_error(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('product_import_batches')) {
            $this->markTestSkipped('Test requires users and product_import_batches tables.');
        }

        $user = User::query()->first();
        $batch = ProductImportBatch::query()->first();

        if ($user === null || $batch === null) {
            $this->markTestSkipped('Test requires existing user and product import batch records.');
        }

        $response = $this
            ->actingAs($user)
            ->get(route('filament.opa-chirik.resources.product-imports.product-import-batches.view', [
                'record' => $batch->getKey(),
            ]));

        $response->assertOk();
    }
}
