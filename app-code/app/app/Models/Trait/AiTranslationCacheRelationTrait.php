<?php

declare(strict_types=1);

namespace App\Models\Trait;

use App\Models\Ai\AiTranslationCache;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait AiTranslationCacheRelationTrait
{
    /**
     * @return MorphMany<AiTranslationCache, $this>
     */
    public function aiTranslationCaches(): MorphMany
    {
        return $this->morphMany(AiTranslationCache::class, 'translatable');
    }
}
