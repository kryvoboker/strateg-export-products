<?php

declare(strict_types=1);

namespace App\Models\Trait;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait DescriptionsTrait
{
    protected function getNameForFilamentPage(): string
    {
        $description_name = collect($this->getRelationValue('descriptions'))
            ->map(static fn ($description): string => Str::trim((string) Arr::get($description, 'name', '')))
            ->first(static fn (string $name): bool => $name !== '');

        if (is_string($description_name) && $description_name !== '') {
            return $description_name;
        }

        $name_from_db = Str::trim((string) ($this->descriptions()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->orderByRaw('shop_language_id IS NULL DESC')
            ->orderBy('id')
            ->value('name') ?? ''));

        if ($name_from_db !== '') {
            return $name_from_db;
        }

        return '#'.(int) $this->id;
    }
}
