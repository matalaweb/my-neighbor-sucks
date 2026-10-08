<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\PropertyDashboard;
use App\Models\Account;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->brandName('Noise Monitor')
            // No public registration; owners invite or create members.
            ->login(Login::class)
            ->passwordReset()
            ->profile(isSimple: false)
            ->tenant(Account::class, slugAttribute: 'uuid', ownershipRelationship: 'account')
            ->tenantMenu(fn (): bool => (auth()->user()?->accounts()->count() ?? 0) > 1)
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Slate,
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Monitoring'),
                NavigationGroup::make('Equipment'),
                NavigationGroup::make('Account'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                PropertyDashboard::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render("@vite('resources/js/noise-charts.js')").view('filament.partials.chart-bootstrap')->render())
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => view('filament.partials.footer')->render())
            ->unsavedChangesAlerts()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
