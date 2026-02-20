<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

trait EditPageTrait
{
    public function getTitle(): Htmlable|string
    {
        $record = $this->getRecord();

        $attribute_name = method_exists($record, 'descriptions')
            ? $record->descriptions()->orderByRaw('shop_language_id IS NULL DESC')->value('name')
            : null;

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
