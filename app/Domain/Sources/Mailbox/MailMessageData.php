<?php

namespace App\Domain\Sources\Mailbox;

use DateTimeImmutable;

final readonly class MailMessageData
{
    public function __construct(
        public string $uid,
        public string $messageId,
        public string $from,
        public string $subject,
        public DateTimeImmutable $date,
        public string $text,
        public ?string $html,
    ) {}

    public function senderDomain(): string
    {
        return strtolower((string) substr(strrchr($this->from, '@') ?: '', 1));
    }
}
