<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\NavigationGroup;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureAllowedAccount;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * De PHP Zoeker-webapp: één panel met een vast linkermenu (§15).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->login(Login::class)
            ->profile(isSimple: false)
            ->brandName('PHP Zoeker')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            ->colors([
                'primary' => Color::hex('#431858'),
                'gray' => Color::Zinc,
                'danger' => Color::hex('#B0132F'),
                'warning' => Color::hex('#8A5000'),
                'success' => Color::hex('#17623F'),
                'info' => Color::hex('#1D4F91'),
            ])
            ->font('Ubuntu Sans')
            ->darkMode()
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16.5rem')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->navigationGroups(NavigationGroup::ordered())
            ->databaseNotifications()
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="stylesheet" href="'.asset('css/phpzoeker.css').'?v='.filemtime(public_path('css/phpzoeker.css')).'">')
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => Blade::render('@livewire(\'ai-gauge\')'))
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string => Blade::render('@livewire(\'ai-gauge\', [\'compact\' => true])'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => Blade::render("@livewire('microsoft-sign-in-panel')"))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render('@livewire(\'ai-run-progress-panel\')'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Blade::render("@livewire('source-search-panel')"))
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
                EnsureAllowedAccount::class,
            ]);
    }
}
