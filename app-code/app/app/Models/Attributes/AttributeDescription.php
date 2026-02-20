<?php

declare(strict_types=1);

namespace App\Models\Attributes;

use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttributeDescription extends Model
{
    protected $fillable = [
        'attribute_id',
        'shop_language_id',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_id'     => 'integer',
            'shop_language_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    public static function resolveNameByAttributeAndLanguage(int $attribute_id, int $shop_language_id): string
    {
        if ($attribute_id <= 0) {
            return '';
        }

        $name = (string) (self::query()
            ->where('attribute_id', $attribute_id)
            ->when(
                $shop_language_id > 0,
                static fn ($query) => $query->where('shop_language_id', $shop_language_id),
                static fn ($query) => $query->whereNull('shop_language_id')
            )
            ->value('name') ?? '');

        $name = Str::trim($name);
        if ($name !== '') {
            return $name;
        }

        $name = (string) (self::query()
            ->where('attribute_id', $attribute_id)
            ->whereNull('shop_language_id')
            ->value('name') ?? '');

        $name = Str::trim($name);
        if ($name !== '') {
            return $name;
        }

        return Str::trim((string) (self::query()
            ->where('attribute_id', $attribute_id)
            ->value('name') ?? ''));
    }

    public static function findAttributeIdByNameForLanguage(string $attribute_name, int $shop_language_id): int
    {
        $clean_attribute_name = Str::trim($attribute_name);
        if ($clean_attribute_name === '') {
            return 0;
        }

        return (int) (self::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($clean_attribute_name)])
            ->when(
                $shop_language_id > 0,
                function ($query) use ($shop_language_id): void {
                    $query->where(function ($query) use ($shop_language_id): void {
                        $query
                            ->where('shop_language_id', $shop_language_id)
                            ->orWhereNull('shop_language_id');
                    })->orderByRaw(
                        'CASE WHEN shop_language_id = ? THEN 0 ELSE 1 END',
                        [$shop_language_id]
                    );
                }
            )
            ->value('attribute_id') ?? 0);
    }

    public static function upsertName(int $attribute_id, int $shop_language_id, string $name): self|Model
    {
        return self::query()->updateOrCreate(
            [
                'attribute_id'     => $attribute_id,
                'shop_language_id' => $shop_language_id > 0 ? $shop_language_id : null,
            ],
            [
                'name' => Str::trim($name),
            ]
        );
    }

    /**
     * @param  list<array{attribute_id:int,shop_language_id:int}>  $pairs
     * @return array<string, string>
     */
    public static function getNameMapByAttributeLanguagePairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $attribute_ids = array_values(array_unique(array_filter(array_map(
            static fn (array $pair): int => (int) $pair['attribute_id'],
            $pairs
        ))));

        $language_ids = array_values(array_unique(array_filter(array_map(
            static fn (array $pair): int => (int) $pair['shop_language_id'],
            $pairs
        ))));

        if ($attribute_ids === [] || $language_ids === []) {
            return [];
        }

        /** @var Collection<int, self> $rows */
        $rows = self::query()
            ->whereIn('attribute_id', $attribute_ids)
            ->whereIn('shop_language_id', $language_ids)
            ->get(['attribute_id', 'shop_language_id', 'name']);

        $name_map = [];
        foreach ($rows as $row) {
            $attribute_id     = (int) ($row->attribute_id ?? 0);
            $shop_language_id = (int) ($row->shop_language_id ?? 0);

            if ($attribute_id <= 0 || $shop_language_id <= 0) {
                continue;
            }

            $name_map[$attribute_id.':'.$shop_language_id] = (string) ($row->name ?? '');
        }

        return $name_map;
    }
}
