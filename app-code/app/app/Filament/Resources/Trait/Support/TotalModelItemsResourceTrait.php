<?php

declare(strict_types=1);

namespace App\Filament\Resources\Trait\Support;

trait TotalModelItemsResourceTrait
{
    public static function getNavigationBadge(): ?string
    {
        $total_items_in_model = self::$model::count();

        return $total_items_in_model > 0 ? (string) $total_items_in_model : '0';
    }
}
