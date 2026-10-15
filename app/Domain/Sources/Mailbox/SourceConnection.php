<?php

namespace App\Domain\Sources\Mailbox;

use App\Domain\Sources\Models\Source;

/**
 * Verbinding van een mailbox-bron (§8.5): lezen en markeren van mail; het token staat versleuteld
 * in de configuratie van de bron.
 */
class SourceConnection implements MicrosoftConnection
{
    public const SCOPES = 'offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.ReadWrite.Shared';

    public function __construct(public readonly Source $source) {}

    public function key(): string
    {
        return 'source:'.$this->source->id;
    }

    public function scopes(): string
    {
        return self::SCOPES;
    }

    public function refreshToken(): ?string
    {
        $token = $this->source->config['refresh_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function tenant(): string
    {
        return (string) ($this->source->config['tenant'] ?? 'organizations');
    }

    public function account(): ?string
    {
        $account = $this->source->config['connected_account'] ?? null;

        return is_string($account) && $account !== '' ? $account : null;
    }

    public function mailbox(): string
    {
        return (string) ($this->source->config['mailbox'] ?? '');
    }

    public function store(string $refreshToken, string $tenant, string $account): void
    {
        $this->source->forceFill(['config' => [
            ...($this->source->config ?? []),
            'auth' => 'delegated',
            'refresh_token' => $refreshToken,
            'tenant' => $tenant,
            'connected_account' => $account,
            'connected_at' => now()->toIso8601String(),
        ]])->save();
    }

    public function forget(): void
    {
        $config = $this->source->config ?? [];
        unset($config['refresh_token'], $config['connected_account'], $config['connected_at'], $config['tenant']);
        $this->source->forceFill(['config' => $config, 'enabled' => false])->save();
    }
}
