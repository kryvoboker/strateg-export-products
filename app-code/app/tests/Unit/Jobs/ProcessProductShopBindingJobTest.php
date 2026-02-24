<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessProductShopBindingJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use PHPUnit\Framework\TestCase;

class ProcessProductShopBindingJobTest extends TestCase
{
    public function test_job_is_unique_per_product_and_shop(): void
    {
        $job = new ProcessProductShopBindingJob(
            product_id: 100,
            shop_ids: [7, 8],
            product_import_batch_id: 0,
            source_payload: [],
            requested_by_user_id: null,
        );

        self::assertInstanceOf(ShouldBeUnique::class, $job);
        self::assertSame('product-shop-binding:100:7,8', $job->uniqueId());
        self::assertSame(120, $job->uniqueFor);
    }
}
