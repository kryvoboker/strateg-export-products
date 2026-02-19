<?php

declare(strict_types=1);

namespace App\Models\Manufacturers;

use App\Models\Shops\ShopLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ManufacturerDescription extends Model
{
    protected $fillable = [
        'manufacturer_id',
        'shop_language_id',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manufacturer_id'  => 'integer',
            'shop_language_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Manufacturer, $this>
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class, 'manufacturer_id');
    }

    /**
     * @return BelongsTo<ShopLanguage, $this>
     */
    public function shopLanguage(): BelongsTo
    {
        return $this->belongsTo(ShopLanguage::class);
    }

    public static function findManufacturerIdByNameForLanguage(string $manufacturer_name, int $shop_language_id): int
    {
        $clean_name = Str::trim($manufacturer_name);
        if ($clean_name === '') {
            return 0;
        }

        return (int) (self::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($clean_name)])
            ->when(
                $shop_language_id > 0,
                function ($query) use ($shop_language_id): void {
                    $query
                        ->where(function ($query) use ($shop_language_id): void {
                            $query
                                ->where('shop_language_id', $shop_language_id)
                                ->orWhereNull('shop_language_id');
                        })
                        ->orderByRaw('CASE WHEN shop_language_id = ? THEN 0 ELSE 1 END', [$shop_language_id]);
                }
            )
            ->value('manufacturer_id') ?? 0);
    }

    public static function upsertName(int $manufacturer_id, int $shop_language_id, string $name): self|Model
    {
        return self::query()->updateOrCreate(
            [
                'manufacturer_id'  => $manufacturer_id,
                'shop_language_id' => $shop_language_id > 0 ? $shop_language_id : null,
            ],
            [
                'name' => Str::trim($name),
            ]
        );
    }
}
