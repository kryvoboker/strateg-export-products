<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

trait EditPageTrait
{
    public function getTitle(): Htmlable|string
    {
        $attribute_name = $this->record->descriptions->first()?->name;

        if ($attribute_name === null) {
            return __('filament-panels::resources/pages/edit-record.title', [
                'label' => $this->getRecordTitle(),
            ]);
        }

        return __('filament-panels::resources/pages/edit-record.title', [
            'label' => Str::wrap($attribute_name, '"'),
        ]);
    }
}
