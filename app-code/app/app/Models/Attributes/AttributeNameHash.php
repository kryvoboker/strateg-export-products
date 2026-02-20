<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use App\Models\Trait\OpenAiRelationsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeNameHash extends Model
{
    use OpenAiRelationsTrait;

    protected $fillable = [
        'attribute_id',
        'hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_id' => 'integer',
            'hash'         => 'string',
        ];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    public static function getNameHash(int $attribute_id, string $hash): ?self
    {
        return self::query()
            ->where('attribute_id', $attribute_id)
            ->where('hash', $hash)
            ->first();
    }
}
