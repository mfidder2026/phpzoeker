<?php

namespace App\Domain\Sources\Mailbox;

/**
 * Kiest de mailboxkoppeling op basis van de bronconfiguratie: `graph` (Microsoft 365) of `imap`.
 */
class MailboxClientFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function for(array $config): MailboxClient
    {
        return ($config['protocol'] ?? 'imap') === 'graph'
            ? app(GraphMailboxClient::class)
            : app(MailboxClient::class);
    }
}
