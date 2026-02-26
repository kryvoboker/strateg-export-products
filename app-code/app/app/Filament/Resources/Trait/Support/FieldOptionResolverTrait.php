<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Support;

trait FieldOptionResolverTrait
{
    protected static function resolveToggleHiddenByDefault(array $params, bool $default = false): bool
    {
        if (array_key_exists('is_toggled_hidden_by_default', $params)) {
            return (bool) $params['is_toggled_hidden_by_default'];
        }

        if (array_key_exists('isToggledHiddenByDefault', $params)) {
            return (bool) $params['isToggledHiddenByDefault'];
        }

        return $default;
    }
}
