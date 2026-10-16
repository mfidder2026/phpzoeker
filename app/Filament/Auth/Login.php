<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Inlogpagina zonder wachtwoord: alleen "Inloggen met Microsoft" (D56). Het wachtwoordformulier
 * en het posten ervan zijn uitgeschakeld.
 */
class Login extends BaseLogin
{
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
        ]);
    }

    public function authenticate(): ?LoginResponse
    {
        abort(403, 'Inloggen kan alleen met Microsoft.');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Alleen voor Fremen. Log in met je Microsoft 365-account.';
    }
}
