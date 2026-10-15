<?php

namespace App\Domain\Sources\Mailbox;

use DateTimeImmutable;

/**
 * Leestoegang tot de alerts-mailbox (§8.5). Mails worden nooit verwijderd; verwerkte mails
 * krijgen een markering.
 */
interface MailboxClient
{
    /**
     * @param  array<string, mixed>  $config
     * @return iterable<MailMessageData>
     */
    public function unprocessed(array $config, ?DateTimeImmutable $since, int $limit): iterable;

    /**
     * @param  array<string, mixed>  $config
     */
    public function markProcessed(array $config, string $uid): void;
}
