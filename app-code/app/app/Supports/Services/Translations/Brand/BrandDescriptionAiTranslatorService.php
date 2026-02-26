<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Brand;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Brands\Brand;
use App\Services\Api\Ai\OpenAiTranslatorService;

class BrandDescriptionAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function resolveScopeType(): string
    {
        return Brand::class;
    }

    protected function resolveScopeId(): int
    {
        return (int) ($this->brand_id ?? 0);
    }
}
