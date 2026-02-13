<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Category;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Categories\CategoryNameHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use Illuminate\Database\Eloquent\Model;

class CategoryNameAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function findCached(string $hash): ?string
    {
        $category_id = (int) ($this->category_id ?? 0);
        if ($category_id <= 0) {
            return null;
        }

        $category_name_hash = CategoryNameHash::getNameHash($category_id, $hash);

        $ai_answer_cache = $category_name_hash
            ?->aiAnswerCache()
            ->first();

        return $ai_answer_cache?->answer;
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $category_name_hash = CategoryNameHash::query()->firstOrCreate([
            'category_id' => (int) ($this->category_id ?? 0),
            'hash' => $hash,
        ]);

        return $category_name_hash
            ->aiAnswerCache()
            ->updateOrCreate(
                [],
                [
                    'prompt' => $prompt,
                    'answer' => $translated_text,
                ],
            );
    }
}
