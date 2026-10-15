<?php

namespace App\Domain\Auth;

use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Mailbox\MicrosoftConnection;

/**
 * Tijdelijke verbinding voor "Inloggen met Microsoft" op de inlogpagina. Vraagt dezelfde
 * rechten als verzenden (plus OpenID), zodat één inlog ook verzenden en de aanvragen-mailbox kan
 * koppelen. De tokens worden niet bewaard: na het inloggen beslist {@see SignInWithMicrosoft} wat
 * ermee gebeurt.
 */
class MicrosoftSignInConnection implements MicrosoftConnection
{
    public const SCOPES = 'openid profile email '.SenderConnection::SCOPES;

    public ?string $refreshToken = null;

    public ?string $tenant = null;

    public ?string $account = null;

    public function __construct(private readonly string $sessionKey) {}

    public function key(): string
    {
        return 'login:'.$this->sessionKey;
    }

    public function scopes(): string
    {
        return self::SCOPES;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function tenant(): string
    {
        return $this->tenant ?? 'organizations';
    }

    public function account(): ?string
    {
        return $this->account;
    }

    public function mailbox(): string
    {
        return '';
    }

    public function store(string $refreshToken, string $tenant, string $account): void
    {
        $this->refreshToken = $refreshToken !== '' ? $refreshToken : null;
        $this->tenant = $tenant;
        $this->account = mb_strtolower($account);
    }

    public function forget(): void
    {
        $this->refreshToken = null;
        $this->tenant = null;
        $this->account = null;
    }
}
