<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Product;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Products\ProductNameHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use Illuminate\Database\Eloquent\Model;

class ProductNameAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function findCached(string $hash): ?string
    {
        $product_hash = ProductNameHash::getNameHash($this->product_id, $hash);

        $this->setProductNameHash($product_hash);

        $ai_answer_cache = $product_hash
            ?->aiAnswerCache()
            ->first();

        return $ai_answer_cache?->answer;
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $product_hash = $this->getProductNameHash();

        if ($product_hash === null) {
            $product_hash = ProductNameHash::create([
                'product_id' => $this->product_id,
                'hash' => $hash,
            ]);
        }

        return $product_hash
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
