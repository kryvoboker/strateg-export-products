<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Attribute;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Attributes\AttributeNameHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use Illuminate\Database\Eloquent\Model;

class AttributeNameAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function findCached(string $hash): ?string
    {
        if ((int) $this->attribute_id <= 0) {
            return null;
        }

        $attribute_name_hash = AttributeNameHash::getNameHash((int) $this->attribute_id, $hash);

        $ai_answer_cache = $attribute_name_hash
            ?->aiAnswerCache()
            ->first();

        return $ai_answer_cache?->answer;
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $attribute_name_hash = AttributeNameHash::query()->firstOrCreate([
            'attribute_id' => (int) $this->attribute_id,
            'hash' => $hash,
        ]);

        return $attribute_name_hash
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
