<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiAnswerCache extends Model
{
    protected $fillable = [
        'hashable_type',
        'hashable_id',
        'prompt',
        'answer',
    ];

    public function hashable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return string[]
     */
    protected function casts(): array
    {
        return [
            'hashable_id' => 'integer',
        ];
    }
}
