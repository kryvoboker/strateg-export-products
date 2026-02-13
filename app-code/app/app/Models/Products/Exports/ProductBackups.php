<?php

declare(strict_types=1);

namespace App\Models\Products\Exports;

use Illuminate\Database\Eloquent\Model;

class ProductBackups extends Model
{
    protected $fillable = [
        'product_id',
        'payload',
        'is_using',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'payload'    => 'array',
            'is_using'   => 'boolean',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function createUsingBackupForProduct(int $product_id, array $payload): self
    {
        static::query()
            ->where('product_id', $product_id)
            ->where('is_using', true)
            ->update([
                'is_using' => false,
            ]);

        /** @var self $backup */
        $backup = static::query()->create([
            'product_id' => $product_id,
            'payload' => $payload,
            'is_using' => true,
        ]);

        return $backup;
    }
}
