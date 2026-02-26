<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiTranslationCache extends Model
{
    protected $fillable = [
        'translatable_type',
        'translatable_id',
        'hash',
        'prompt',
        'answer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'translatable_id' => 'integer',
            'hash'            => 'string',
            'prompt'          => 'string',
            'answer'          => 'string',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
