<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Navigation\AdminNavigationGroupEnum;
use App\Http\Middleware\LogFilamentErrors;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class OpaChirikPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('opa-chirik')
            ->path('opa-chirik')
            ->login()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->maxContentWidth(Width::Full)
//            ->viteTheme('resources/assets/filament/alyo-admin/theme.css')
            // Show group menu list if user visited page from group
            ->collapsibleNavigationGroups()
            // Show group menu list if user visited page from group
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(fn() => AdminNavigationGroupEnum::Catalog->getLabel())
                    ->collapsed(),

                NavigationGroup::make()
                    ->label(fn() => AdminNavigationGroupEnum::Shops->getLabel())
                    ->collapsed(),

                NavigationGroup::make()
                    ->label(fn() => AdminNavigationGroupEnum::Users->getLabel())
                    ->collapsed(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                LogFilamentErrors::class
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
