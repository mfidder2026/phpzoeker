<?php

namespace App\Domain\Sources\Mailbox;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * IMAP-toegang tot de alerts-mailbox via webklex/php-imap (Gmail, Microsoft 365 met IMAP,
 * of een andere mailserver). Ongelezen mails = nog niet verwerkt; na verwerking: gelezen +
 * gemarkeerd. Er wordt niets verwijderd (§8.5).
 */
class ImapMailboxClient implements MailboxClient
{
    public function unprocessed(array $config, ?DateTimeImmutable $since, int $limit): iterable
    {
        $client = $this->connect($config);

        try {
            $folder = $client->getFolderByPath((string) ($config['folder'] ?? 'INBOX'));

            if ($folder === null) {
                throw new RuntimeException('De map '.($config['folder'] ?? 'INBOX').' bestaat niet in de mailbox.');
            }

            $query = $folder->query()->leaveUnread()->setFetchOrder('desc')->unseen();

            if ($since !== null) {
                $query->since($since->modify('-1 day')->format('d.m.Y'));
            }

            $messages = [];

            foreach ($query->limit($limit)->get() as $message) {
                if ($message instanceof Message) {
                    $messages[] = $this->toData($message);
                }
            }

            return $messages;
        } finally {
            $client->disconnect();
        }
    }

    public function markProcessed(array $config, string $uid): void
    {
        $client = $this->connect($config);

        try {
            $folder = $client->getFolderByPath((string) ($config['folder'] ?? 'INBOX'));
            $message = $folder?->query()->getMessageByUid((int) $uid);
            $message?->setFlag(['Seen', 'Flagged']);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connect(array $config): Client
    {
        try {
            return (new ClientManager)->make([
                'host' => (string) ($config['host'] ?? ''),
                'port' => (int) ($config['port'] ?? 993),
                'encryption' => (string) ($config['encryption'] ?? 'ssl'),
                'validate_cert' => true,
                'username' => (string) ($config['username'] ?? ''),
                'password' => (string) ($config['password'] ?? ''),
                'protocol' => 'imap',
                'timeout' => 30,
            ])->connect();
        } catch (Throwable $exception) {
            throw new RuntimeException('Verbinden met de alerts-mailbox is mislukt. Controleer server, gebruikersnaam en (app-)wachtwoord.', 0, $exception);
        }
    }

    private function toData(Message $message): MailMessageData
    {
        $from = $message->getFrom()->first();
        $date = $message->getDate()->first();

        return new MailMessageData(
            uid: (string) $message->getUid(),
            messageId: (string) ($message->getMessageId()->first() ?? $message->getUid()),
            from: is_object($from) && isset($from->mail) ? (string) $from->mail : '',
            subject: (string) $message->getSubject()->first(),
            date: $date instanceof \DateTimeInterface ? DateTimeImmutable::createFromInterface($date) : new DateTimeImmutable,
            text: $message->hasTextBody() ? $message->getTextBody() : '',
            html: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
        );
    }
}
