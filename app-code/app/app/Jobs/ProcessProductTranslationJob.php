<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Supports\Services\Products\ProductShopBindingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessProductTranslationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $product_id,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(ProductShopBindingService $product_shop_binding_service): void
    {
        if ($this->product_id <= 0) {
            return;
        }

        try {
            $product_shop_binding_service->synchronizeProductTranslations($this->product_id);
        } catch (Throwable $exception) {
            Log::channel('stack')->error('Product translation job failed', [
                'product_id' => $this->product_id,
                'message'    => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
            ]);

            throw $exception;
        }
    }
}
