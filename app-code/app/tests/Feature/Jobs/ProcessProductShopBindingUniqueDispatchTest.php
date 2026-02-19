<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProductShopBindingJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessProductShopBindingUniqueDispatchTest extends TestCase
{
    public function test_duplicate_dispatch_for_same_product_and_shop_is_ignored(): void
    {
        Queue::fake();

        ProcessProductShopBindingJob::dispatch(10, 2);
        ProcessProductShopBindingJob::dispatch(10, 2);

        Queue::assertPushed(ProcessProductShopBindingJob::class, 1);
    }

    public function test_dispatch_for_different_unique_keys_is_not_ignored(): void
    {
        Queue::fake();

        ProcessProductShopBindingJob::dispatch(10, 2);
        ProcessProductShopBindingJob::dispatch(10, 3);
        ProcessProductShopBindingJob::dispatch(11, 2);

        Queue::assertPushed(ProcessProductShopBindingJob::class, 3);
    }
}
