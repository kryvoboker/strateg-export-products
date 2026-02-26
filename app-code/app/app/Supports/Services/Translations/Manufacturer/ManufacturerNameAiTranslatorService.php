<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Manufacturer;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Manufacturers\Manufacturer;
use App\Services\Api\Ai\OpenAiTranslatorService;

class ManufacturerNameAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function resolveScopeType(): string
    {
        return Manufacturer::class;
    }

    protected function resolveScopeId(): int
    {
        return $this->manufacturer_id ?? 0;
    }
}
