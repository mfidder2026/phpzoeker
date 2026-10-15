<?php

namespace App\Domain\Offers\Mailers;

use App\Domain\Offers\Models\OutboundEmail;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Verzending via de Laravel-mailer (SMTP, §13.2 fallback). De app zet zelf de Message-ID, zodat
 * die bekend is voor reply-tracking; de conversatie-ID is de Message-ID van de eerste mail.
 * Het volledige MIME-bericht komt terug voor de snapshot (§11.7).
 */
class SmtpOutboundMailer implements OutboundMailer
{
    public function send(OutboundEmail $email): array
    {
        $domain = Str::after($email->from, '@') ?: 'fremen.app';
        $messageId = Str::uuid()->toString().'@'.$domain;

        try {
            $sent = Mail::mailer()->send([], [], function (Message $message) use ($email, $messageId): void {
                $message->from($email->from, $email->from_name)
                    ->to($email->to)
                    ->subject($email->subject)
                    ->html($email->body_html)
                    ->text($email->body_text);

                if (filled($email->cc)) {
                    $message->cc($email->cc);
                }

                if (filled($email->bcc)) {
                    $message->bcc($email->bcc);
                }

                foreach ($email->attachments ?? [] as $attachment) {
                    $message->attach(Storage::disk('local')->path($attachment['path']), ['as' => $attachment['name'], 'mime' => $attachment['mime']]);
                }

                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->addIdHeader('Message-ID', $messageId);

                if (filled($email->in_reply_to)) {
                    $headers->addIdHeader('In-Reply-To', (string) $email->in_reply_to);
                    $headers->addIdHeader('References', (string) $email->in_reply_to);
                }
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('Versturen mislukt: '.$exception->getMessage(), previous: $exception);
        }

        if ($sent === null) {
            throw new RuntimeException('Versturen mislukt: de mailer gaf geen bevestiging.');
        }

        return [
            'provider' => 'smtp',
            'provider_message_id' => $sent->getMessageId(),
            'internet_message_id' => $messageId,
            'conversation_id' => $email->conversation_id ?? $email->in_reply_to ?? $messageId,
            'raw_mime' => $sent->toString(),
        ];
    }
}
