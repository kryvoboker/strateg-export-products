<?php

declare(strict_types=1);

namespace App\Supports\Services\Products\Traits;

use Illuminate\Support\Str;

trait NormalizesProductServiceInput
{
    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    protected function normalizePositiveIntList(array $values, bool $is_sort = false): array
    {
        $normalized_values = collect($values)
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique();

        if ($is_sort) {
            $normalized_values = $normalized_values->sort();
        }

        return $normalized_values
            ->values()
            ->all();
    }

    protected function normalizeLanguageCode(string $language_code): string
    {
        $normalized_language_code = Str::lower(Str::trim($language_code));

        return $normalized_language_code === 'ua' ? 'uk' : $normalized_language_code;
    }
}
