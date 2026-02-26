<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Category;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Categories\Category;
use App\Services\Api\Ai\OpenAiTranslatorService;

class CategoryDescriptionAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function resolveScopeType(): string
    {
        return Category::class;
    }

    protected function resolveScopeId(): int
    {
        return (int) ($this->category_id ?? 0);
    }
}
