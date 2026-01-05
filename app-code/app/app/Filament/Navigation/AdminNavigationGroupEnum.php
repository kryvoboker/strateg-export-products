<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

enum AdminNavigationGroupEnum:string implements HasLabel
{
    case Catalog   = 'catalog';
    case Users     = 'users';

    /**
     * @return string|Htmlable|null
     */
    public function getLabel(): string|Htmlable|null
    {
        return match ($this) {
            self::Catalog   => __('admin/default.menu.item_catalog'),
            self::Users     => __('admin/default.menu.item_users'),
        };
    }
}
