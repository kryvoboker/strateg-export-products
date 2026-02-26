<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Attribute;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Attributes\Attribute;
use App\Services\Api\Ai\OpenAiTranslatorService;

class AttributeDescriptionAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function resolveScopeType(): string
    {
        return Attribute::class;
    }

    protected function resolveScopeId(): int
    {
        return $this->attribute_id ?? 0;
    }
}
