<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Filters;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

trait NumericFilterTrait
{
    protected static function getNumericFilterField(array $params = []): Filter
    {
        if (isset($params['min_length']) && is_numeric($params['min_length'])) {
            $min_length = $params['min_length'];
        } elseif (isset($params['min_length']) === false) {
            $min_length = null;
        } else {
            $min_length = 0;
        }

        $filter_label = $params['filter_label'];
        $field_name   = $params['field_name'];

        return Filter::make($params['filter_field_name'] ?? $field_name)
            ->label($filter_label)
            ->schema([
                TextInput::make($field_name)
                    ->label($params['label'] ?? $filter_label)
                    ->placeholder($params['placeholder'])
                    ->numeric()
                    ->minValue($min_length)
                    ->afterStateUpdated($params['after_state_updated_cb'] ?? function ($state, $set) use ($field_name, $min_length): void {
                        // Clear invalid input
                        if (num_more_or_equal_num($state, $min_length) === false) {
                            $set($field_name, null);
                        }
                    }),
            ])
            ->query($params['query_cb'] ?? function (Builder $query, array $data) use ($field_name, $min_length): Builder {
                $search = $data[$field_name] ?? null;

                // Apply validation in query
                if (num_more_or_equal_num($search, $min_length) === false) {
                    return $query;
                }

                return $query->where($field_name, $search);
            })
            ->indicateUsing($params['indicateUsing_cb'] ?? function (array $data) use ($field_name, $filter_label, $min_length): ?string {
                $search = $data[$field_name] ?? null;

                if (num_more_or_equal_num($search, $min_length) === false) {
                    return null;
                }

                return $filter_label.': '.$search;
            });
    }
}
