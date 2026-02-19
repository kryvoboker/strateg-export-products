<?php

declare(strict_types=1);

namespace App\Supports\Services\Translations\Product;

use App\Abstratcts\Ai\AiDbCachedTranslatorAbstract;
use App\Models\Products\ProductAttributeTextHash;
use App\Services\Api\Ai\OpenAiTranslatorService;
use Illuminate\Database\Eloquent\Model;

class ProductAttributeTextAiTranslatorService extends AiDbCachedTranslatorAbstract
{
    public function __construct(
        OpenAiTranslatorService $ai,
    ) {
        parent::__construct($ai);
    }

    protected function findCached(string $hash): ?string
    {
        $product_attribute_text_hash = ProductAttributeTextHash::getAttributeTextHash(
            $this->product_id,
            $this->attribute_id,
            $hash
        );

        $this->setProductAttributeTextHash($product_attribute_text_hash);

        $ai_answer_cache = $product_attribute_text_hash
            ?->aiAnswerCache()
            ->first();

        return $ai_answer_cache?->answer;
    }

    protected function storeTranslation(string $hash, string $prompt, string $translated_text): Model
    {
        $product_attribute_text_hash = $this->getProductAttributeTextHash();

        if ($product_attribute_text_hash === null) {
            $product_attribute_text_hash = ProductAttributeTextHash::create([
                'product_id'   => $this->product_id,
                'attribute_id' => $this->attribute_id,
                'hash'         => $hash,
            ]);
        }

        return $product_attribute_text_hash
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
