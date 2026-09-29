<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\LatestLoyaltyTransactionsWidget;
use App\Filament\Widgets\LatestRestaurantsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authGuard('super_admins')
            ->brandName('Miva Fid')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('2.25rem')
            ->favicon(url('/favicon.ico'))
            ->colors([
                'primary' => Color::Indigo,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->font('Instrument Sans')
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16.5rem')
            ->collapsedSidebarWidth('4.5rem')
            ->spa()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/filament-saas.css').'?v='.(file_exists(public_path('css/filament-saas.css')) ? filemtime(public_path('css/filament-saas.css')) : time()).'">'
            )
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('Marchands'),
                \Filament\Navigation\NavigationGroup::make('Clients'),
                \Filament\Navigation\NavigationGroup::make('Finance'),
                \Filament\Navigation\NavigationGroup::make('Marketing'),
                \Filament\Navigation\NavigationGroup::make('Système')
                    ->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                StatsOverviewWidget::class,
                LatestLoyaltyTransactionsWidget::class,
                LatestRestaurantsWidget::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
