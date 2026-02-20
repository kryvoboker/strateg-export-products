<?php

declare(strict_types=1);

namespace App\Models\Categories;

use App\Models\Trait\OpenAiRelationsTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryNameHash extends Model
{
    use OpenAiRelationsTrait;

    protected $fillable = [
        'category_id',
        'hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'hash'        => 'string',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public static function getNameHash(int $category_id, string $hash): ?self
    {
        return self::query()
            ->where('category_id', $category_id)
            ->where('hash', $hash)
            ->first();
    }
}
