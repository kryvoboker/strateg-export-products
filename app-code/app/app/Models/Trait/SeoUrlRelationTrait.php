<?php

declare(strict_types=1);

namespace App\Models\Trait;

use App\Models\Seo\SeoUrl;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait SeoUrlRelationTrait
{
    /**
     * @return MorphMany<SeoUrl, $this>
     */
    public function seoUrl(): MorphMany
    {
        return $this->morphMany(SeoUrl::class, 'seoable');
    }
}
