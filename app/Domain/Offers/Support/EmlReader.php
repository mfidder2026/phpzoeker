<?php

namespace App\Domain\Offers\Support;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

/**
 * Leest een opgeslagen mail (`.eml`) voor handmatig registreren (§11.8): kopregels, tekst en
 * bijlagen. Outlook-`.msg` vraagt een extra bibliotheek en volgt na akkoord (D41).
 */
final class EmlReader
{
    /**
     * @return array{from: string|null, to: list<string>, cc: list<string>, subject: string, date: CarbonImmutable|null, message_id: string|null, html: string|null, text: string, attachments: list<array{name: string, mime: string, content: string}>}
     *
     * @throws RuntimeException
     */
    public static function read(string $path): array
    {
        try {
            $message = Message::fromFile($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('Het .eml-bestand kan niet worden gelezen: '.$exception->getMessage(), previous: $exception);
        }

        $addresses = function (mixed $attribute): array {
            $result = [];

            foreach ($attribute?->all() ?? [] as $address) {
                if ($address instanceof Address && filled($address->mail)) {
                    $result[] = mb_strtolower((string) $address->mail);
                }
            }

            return $result;
        };

        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => (string) ($attachment->getName() ?: 'bijlage'),
                'mime' => (string) ($attachment->getMimeType() ?: 'application/octet-stream'),
                'content' => (string) $attachment->getContent(),
            ];
        }

        $date = $message->getDate()->first();

        try {
            $sentAt = $date !== null ? CarbonImmutable::parse((string) $date) : null;
        } catch (Throwable) {
            $sentAt = null;
        }

        $html = $message->getHTMLBody();
        $text = $message->getTextBody();

        return [
            'from' => $addresses($message->getFrom())[0] ?? null,
            'to' => $addresses($message->getTo()),
            'cc' => $addresses($message->getCc()),
            'subject' => (string) $message->getSubject(),
            'date' => $sentAt,
            'message_id' => filled((string) $message->getMessageId()) ? trim((string) $message->getMessageId(), '<>') : null,
            'html' => $html !== '' ? $html : null,
            'text' => $text !== '' ? $text : strip_tags($html),
            'attachments' => $attachments,
        ];
    }
}
