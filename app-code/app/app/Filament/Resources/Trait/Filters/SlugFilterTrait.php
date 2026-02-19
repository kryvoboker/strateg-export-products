<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Filters;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

trait SlugFilterTrait
{
    protected static function getSlugFilterField(array $params = []): Filter
    {
        if (isset($params['min_length']) && $params['min_length'] > 0) {
            $min_length = (int) $params['min_length'];
        } elseif (isset($params['min_length']) === false) {
            $min_length = null;
        } else {
            $min_length = 3;
        }

        if (isset($params['max_length']) && $params['max_length'] > 0) {
            $max_length = (int) $params['max_length'];
        } elseif (isset($params['max_length']) === false) {
            $max_length = null;
        } else {
            $max_length = 500;
        }

        $filter_label = $params['filter_label'] ?? __('admin/default.filters.slug');
        $field_name   = $params['field_name'] ?? 'slugs';

        return Filter::make($params['filter_field_name'] ?? $field_name)
            ->label($filter_label)
            ->schema([
                TextInput::make($field_name)
                    ->label($params['label'] ?? $filter_label)
                    ->placeholder($params['placeholder'] ?? __('admin/default.placeholders.slug'))
                    ->minLength($min_length)
                    ->maxLength($max_length)
                    ->afterStateUpdated($params['after_state_updated_cb'] ?? function ($state, $set) use ($field_name, $min_length): void {
                        // Clear empty input to avoid filtering by empty value
                        if (str_more_or_equal_length($state, $min_length) === false) {
                            $set($field_name, null);
                        }
                    }),
            ])
            ->query($params['query_cb'] ?? function (Builder $query, array $data) use ($field_name, $min_length): Builder {
                $search = $data[$field_name] ?? null;

                // Apply validation in query
                if (str_more_or_equal_length($search, $min_length) === false) {
                    return $query;
                }

                $search = Str::trim($search);

                return $query->whereHas(
                    'slugs',
                    function (Builder $query) use ($search) {
                        return $query
                            ->whereLike('slug', "$search%");
                    }
                );
            })
            ->indicateUsing($params['indicateUsing_cb'] ?? function (array $data) use ($field_name, $filter_label, $min_length): ?string {
                $search = $data[$field_name] ?? null;

                if (str_more_or_equal_length($search, $min_length) === false) {
                    return null;
                }

                return $filter_label.': '.Str::trim($search);
            });
    }
}
