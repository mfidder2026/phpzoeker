<?php

namespace App\Domain\Offers\Mailers;

use App\Domain\Sources\Mailbox\MicrosoftConnection;
use App\Settings\MailSettings;

/**
 * Verbinding van de afzendermailbox voor het versturen van aanbiedingen via Microsoft Graph
 * (§13.2). Rechten: versturen (ook namens een gedeelde mailbox) en concepten maken, zodat de
 * mail in "Verzonden items" van de afzender staat. Het token staat versleuteld in de
 * mailinstellingen en gaat nooit naar de browser.
 */
class SenderConnection implements MicrosoftConnection
{
    public const SCOPES = 'offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.ReadWrite.Shared https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.Send.Shared';

    public function __construct(private readonly MailSettings $settings) {}

    public function key(): string
    {
        return 'sender';
    }

    public function scopes(): string
    {
        return self::SCOPES;
    }

    public function refreshToken(): ?string
    {
        return filled($this->settings->graph_refresh_token) ? $this->settings->graph_refresh_token : null;
    }

    public function tenant(): string
    {
        return $this->settings->graph_tenant ?: 'organizations';
    }

    public function account(): ?string
    {
        return $this->settings->graph_account;
    }

    public function mailbox(): string
    {
        return $this->settings->sender_mailbox;
    }

    public function connectedAt(): ?string
    {
        return $this->settings->graph_connected_at;
    }

    public function store(string $refreshToken, string $tenant, string $account): void
    {
        $this->settings->graph_refresh_token = $refreshToken;
        $this->settings->graph_tenant = $tenant;
        $this->settings->graph_account = $account;
        $this->settings->graph_connected_at = now()->toIso8601String();
        $this->settings->save();
    }

    public function forget(): void
    {
        $this->settings->graph_refresh_token = null;
        $this->settings->graph_tenant = null;
        $this->settings->graph_account = null;
        $this->settings->graph_connected_at = null;
        $this->settings->save();
    }
}
