<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use Filament\Support\Contracts\HasLabel;

enum AdminNavigationGroupEnum: string implements HasLabel
{
    case ProductManagement = 'product_management';
    case Catalog           = 'catalog';
    case Shops             = 'shops';
    case Users             = 'users';

    public function getLabel(): string
    {
        return match ($this) {
            self::ProductManagement => __('admin/default.menu.item_product_management'),
            self::Catalog           => __('admin/default.menu.item_catalog'),
            self::Shops             => __('admin/default.menu.item_shops'),
            self::Users             => __('admin/default.menu.item_users'),
        };
    }
}
