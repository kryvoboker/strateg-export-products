<?php

declare(strict_types=1);

namespace App\Models\Seo;

use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoUrl extends Model
{
    protected $fillable = [
        'seoable_type',
        'seoable_id',
        'shop_language_id',
        'query_value',
        'keyword',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seoable_id' => 'integer',
            'shop_language_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param list<int> $shop_language_ids
     * @return Collection<int, self>
     */
    public static function getForSeoableAndLanguageIds(string $seoable_type, int $seoable_id, array $shop_language_ids = []): Collection
    {
        if ($seoable_type === '' || $seoable_id <= 0) {
            return self::query()->whereRaw('1 = 0')->get();
        }

        return self::query()
            ->where('seoable_type', $seoable_type)
            ->where('seoable_id', $seoable_id)
            ->when(
                $shop_language_ids !== [],
                static fn ($query) => $query->where(function ($inner_query) use ($shop_language_ids): void {
                    $inner_query
                        ->whereIn('shop_language_id', $shop_language_ids)
                        ->orWhereNull('shop_language_id');
                })
            )
            ->orderBy('id')
            ->get();
    }
}
