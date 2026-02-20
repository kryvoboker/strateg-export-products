<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Support\Contracts\HasLabel;

enum AdminNavigationGroupEnum: string implements HasLabel
{
    case Shops   = 'shops';
    case Catalog = 'catalog';
    case Users   = 'users';

    public function getLabel(): string
    {
        return match ($this) {
            self::Shops   => __('admin/default.menu.item_shops'),
            self::Catalog => __('admin/default.menu.item_catalog'),
            self::Users   => __('admin/default.menu.item_users'),
        };
    }
}
