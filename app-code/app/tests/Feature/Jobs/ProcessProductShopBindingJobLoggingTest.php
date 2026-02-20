<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessProductShopBindingJob;
use App\Supports\Services\Catalog\ProductShopBindingService;
use Illuminate\Support\Arr;
use Tests\TestCase;

class ProcessProductShopBindingJobLoggingTest extends TestCase
{
    public function test_it_logs_catalog_strategy_summary_from_binding_result(): void
    {
        $original_log = $this->app->bound('log') ? $this->app->make('log') : null;

        $fake_logger = new class
        {
            /**
             * @var array<int, array{channel:string,level:string,message:string,context:array<string,mixed>}>
             */
            public array $records = [];

            private string $current_channel = 'stack';

            public function channel(string $name): self
            {
                $this->current_channel = $name;

                return $this;
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function info(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'info',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function warning(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'warning',
                    'message' => $message,
                    'context' => $context,
                ];
            }

            /**
             * @param  array<string, mixed>  $context
             */
            public function error(string $message, array $context = []): void
            {
                $this->records[] = [
                    'channel' => $this->current_channel,
                    'level'   => 'error',
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };

        try {
            $this->app->instance('log', $fake_logger);

            $service = new class extends ProductShopBindingService
            {
                /**
                 * @return array<string, mixed>
                 */
                public function bindProductToShopAndReturnTargetProduct(
                    int $product_id,
                    int $shop_id,
                    int $product_import_batch_id = 0,
                    array $source_payload = []
                ): array {
                    return [
                        'product_id' => $product_id,
                        'bound'      => 1,
                        'duplicated' => 0,
                        'catalog_sync_summary' => [
                            'categories_relinked'    => 1,
                            'categories_assigned'    => 1,
                            'categories_created'     => 0,
                            'categories_reused'      => 0,
                            'attributes_relinked'    => 1,
                            'attributes_assigned'    => 1,
                            'attributes_created'     => 0,
                            'attributes_reused'      => 0,
                            'manufacturer_relinked'  => 0,
                            'manufacturers_assigned' => 1,
                            'manufacturers_created'  => 0,
                            'manufacturers_reused'   => 0,
                            'brand_relinked'         => 0,
                            'brands_assigned'        => 1,
                            'brands_created'         => 0,
                            'brands_reused'          => 0,
                        ],
                    ];
                }
            };

            $job = new ProcessProductShopBindingJob(
                product_id: 501,
                shop_id: 11,
                product_import_batch_id: 0,
                source_payload: [],
                requested_by_user_id: 1,
            );
            $job->handle($service);

            $processed_record = collect($fake_logger->records)
                ->first(static fn (array $record): bool => $record['message'] === 'Product shop binding processed');

            self::assertIsArray($processed_record);
            self::assertSame('daily', Arr::get($processed_record, 'channel'));
            self::assertSame(4, (int) Arr::get($processed_record, 'context.catalog_strategy_summary.assigned'));
            self::assertSame(0, (int) Arr::get($processed_record, 'context.catalog_strategy_summary.reused'));
            self::assertSame(0, (int) Arr::get($processed_record, 'context.catalog_strategy_summary.duplicated'));
        } finally {
            if ($original_log !== null) {
                $this->app->instance('log', $original_log);
            }
        }
    }
}
