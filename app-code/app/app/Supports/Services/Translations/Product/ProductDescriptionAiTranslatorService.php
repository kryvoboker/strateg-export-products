<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Product;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Products\ProductDescriptionHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use Illuminate\Database\Eloquent\Model;

class ProductDescriptionAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function findCached(string $hash): ?string
    {
        $product_description_hash = ProductDescriptionHash::getDescriptionHash($this->product_id, $hash);

        $this->setProductDescriptionHash($product_description_hash);

        $ai_answer_cache = $product_description_hash
            ?->aiAnswerCache()
            ->first();

        return $ai_answer_cache?->answer;
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $product_description_hash = $this->getProductDescriptionHash();

        if ($product_description_hash === null) {
            $product_description_hash = ProductDescriptionHash::create([
                'product_id' => $this->product_id,
                'hash' => $hash,
            ]);
        }

        return $product_description_hash
            ?->aiAnswerCache()
            ->updateOrCreate(
                [],
                [
                    'prompt' => $prompt,
                    'answer' => $translated_text,
                ],
            );
    }
}
