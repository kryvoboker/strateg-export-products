<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Filters;

use Filament\Tables\Filters\TernaryFilter;

trait BooleanFilterTrait
{
    protected static function getIsActiveFilterField(array $params = []): TernaryFilter
    {
        return TernaryFilter::make($params['filter_field_name'] ?? 'is_active')
            ->label($params['filter_label'] ?? __('admin/default.filters.active'))
            ->placeholder($params['filter_placeholder'] ?? __('admin/default.placeholders.all'))
            ->trueLabel($params['filter_true_label'] ?? __('admin/default.filters.active_only'))
            ->falseLabel($params['filter_false_label'] ?? __('admin/default.filters.inactive_only'));
    }

    protected static function getIsDefaultFilterField(array $params = []): TernaryFilter
    {
        return TernaryFilter::make($params['filter_field_name'] ?? 'is_default')
            ->label($params['filter_label'] ?? __('admin/default.filters.default'))
            ->placeholder($params['filter_placeholder'] ?? __('admin/default.placeholders.all'))
            ->trueLabel($params['filter_true_label'] ?? __('admin/default.filters.default'))
            ->falseLabel($params['filter_false_label'] ?? __('admin/default.filters.default'));
    }
}
