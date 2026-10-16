<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alleen de toegestane Microsoft-accounts (config phpzoeker.allowed_accounts) mogen de app
 * gebruiken (D56). Een sessie van een ander account wordt uitgelogd.
 */
class EnsureAllowedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('phpzoeker.allowed_accounts');
        $user = Filament::auth()->user();
        $email = strtolower((string) $user?->email);

        // Een lege lijst (alleen in de testsuite) betekent: geen beperking.
        if ($user !== null && $allowed !== [] && ! in_array($email, $allowed, true)) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(Filament::getLoginUrl() ?? '/login');
        }

        return $next($request);
    }
}
