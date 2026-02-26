<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Product;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Products\Product;
use App\Services\Api\Ai\OpenAiTranslatorService;

class ProductDescriptionAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function resolveScopeType(): string
    {
        return Product::class;
    }

    protected function resolveScopeId(): int
    {
        return (int) ($this->product_id ?? 0);
    }
}
