<?php

namespace App\Domain\Offers\Mailers;

use App\Ai\Support\SecretRedactor;
use App\Domain\Offers\Models\OutboundEmail;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Settings\MailSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Verstuurt via Microsoft 365 (§13.2): concept maken in de afzendermailbox, bijlagen toevoegen
 * en versturen. Zo staat de mail ook in "Verzonden items" van de afzender. Een opvolgmail is een
 * antwoord op de oorspronkelijke mail (zelfde conversatie). Met onveranderlijke ID's
 * (`ImmutableId`) blijft het bericht vindbaar nadat het naar Verzonden items is verplaatst; het
 * MIME-bericht gaat mee in de snapshot (§11.7).
 */
class MicrosoftGraphMailer implements OutboundMailer
{
    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Bijlagen tot 3 MB gaan in één keer mee; grotere via een uploadsessie. */
    public const INLINE_ATTACHMENT_LIMIT = 3 * 1024 * 1024;

    /** Aantal pogingen om het verzonden MIME-bericht op te halen. */
    public const MIME_ATTEMPTS = 3;

    public function __construct(
        private readonly MicrosoftLogin $login,
        private readonly MailSettings $settings,
    ) {}

    public function send(OutboundEmail $email): array
    {
        $sender = rawurlencode($email->from);
        $original = $email->in_reply_to !== null ? $this->original($email) : null;

        if ($original !== null) {
            $draft = $this->request('post', "/users/{$sender}/messages/".rawurlencode($original).'/createReply')->json();
            $this->request('patch', "/users/{$sender}/messages/".rawurlencode((string) ($draft['id'] ?? '')), $this->message($email));
        } else {
            $draft = $this->request('post', "/users/{$sender}/messages", $this->message($email))->json();
        }

        $id = (string) ($draft['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Microsoft Graph gaf geen concept terug.');
        }

        foreach ($email->attachments ?? [] as $attachment) {
            $this->attach($sender, $id, $attachment);
        }

        $details = $this->request('get', "/users/{$sender}/messages/".rawurlencode($id), ['$select' => 'internetMessageId,conversationId'])->json();
        $this->request('post', "/users/{$sender}/messages/".rawurlencode($id).'/send');

        return [
            'provider' => 'graph',
            'provider_message_id' => $id,
            'internet_message_id' => trim((string) ($details['internetMessageId'] ?? ''), '<>'),
            'conversation_id' => (string) ($details['conversationId'] ?? $email->conversation_id ?? $id),
            'raw_mime' => $this->mime($sender, $id, $email),
        ];
    }

    /**
     * Berichten in een conversatie in de afzendermailbox (voor reply-tracking, §13.5).
     *
     * @return list<array{id: string, internet_message_id: string, from: string, subject: string, received_at: string, preview: string}>
     */
    public function conversationMessages(string $mailbox, string $conversationId): array
    {
        $filter = "conversationId eq '".str_replace("'", "''", $conversationId)."'";
        $response = $this->request('get', '/users/'.rawurlencode($mailbox).'/messages', [
            '$filter' => $filter,
            '$select' => 'id,internetMessageId,from,subject,receivedDateTime,bodyPreview',
            '$top' => 50,
        ]);

        return array_values(array_map(fn (array $message): array => [
            'id' => (string) ($message['id'] ?? ''),
            'internet_message_id' => trim((string) ($message['internetMessageId'] ?? ''), '<>'),
            'from' => mb_strtolower((string) ($message['from']['emailAddress']['address'] ?? '')),
            'subject' => (string) ($message['subject'] ?? ''),
            'received_at' => (string) ($message['receivedDateTime'] ?? ''),
            'preview' => mb_substr((string) ($message['bodyPreview'] ?? ''), 0, 300),
        ], array_filter((array) $response->json('value', []), 'is_array')));
    }

    /**
     * Velden van het bericht voor Graph.
     *
     * @return array<string, mixed>
     */
    private function message(OutboundEmail $email): array
    {
        $recipients = fn (?array $addresses): array => array_map(fn (string $address): array => ['emailAddress' => ['address' => $address]], array_values($addresses ?? []));

        return [
            'subject' => $email->subject,
            'body' => ['contentType' => 'HTML', 'content' => $email->body_html],
            'toRecipients' => $recipients($email->to),
            'ccRecipients' => $recipients($email->cc),
            'bccRecipients' => $recipients($email->bcc),
            'from' => ['emailAddress' => array_filter(['address' => $email->from, 'name' => $email->from_name])],
        ];
    }

    /**
     * Graph-ID van de oorspronkelijke mail voor een opvolging, als die via Graph is verstuurd.
     */
    private function original(OutboundEmail $email): ?string
    {
        $original = OutboundEmail::query()
            ->where('offer_id', $email->offer_id)
            ->where('internet_message_id', $email->in_reply_to)
            ->where('provider', 'graph')
            ->value('provider_message_id');

        return is_string($original) && $original !== '' ? $original : null;
    }

    /**
     * @param  array{path: string, name: string, mime: string, size: int}  $attachment
     */
    private function attach(string $sender, string $messageId, array $attachment): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($attachment['path'])) {
            throw new RuntimeException("De bijlage {$attachment['name']} ontbreekt.");
        }

        $content = (string) $disk->get($attachment['path']);
        $base = "/users/{$sender}/messages/".rawurlencode($messageId).'/attachments';

        if (strlen($content) <= self::INLINE_ATTACHMENT_LIMIT) {
            $this->request('post', $base, [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['name'],
                'contentType' => $attachment['mime'],
                'contentBytes' => base64_encode($content),
            ]);

            return;
        }

        $session = $this->request('post', $base.'/createUploadSession', [
            'AttachmentItem' => ['attachmentType' => 'file', 'name' => $attachment['name'], 'size' => strlen($content), 'contentType' => $attachment['mime']],
        ])->json();
        $uploadUrl = (string) ($session['uploadUrl'] ?? '');
        $total = strlen($content);

        for ($offset = 0; $offset < $total; $offset += self::INLINE_ATTACHMENT_LIMIT) {
            $chunk = substr($content, $offset, self::INLINE_ATTACHMENT_LIMIT);
            $end = $offset + strlen($chunk) - 1;

            try {
                $response = Http::timeout(60)->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$total}"])
                    ->withBody($chunk, 'application/octet-stream')->put($uploadUrl);
            } catch (ConnectionException) {
                throw new RuntimeException('Microsoft Graph is niet bereikbaar tijdens het uploaden van een bijlage.');
            }

            if (! $response->successful()) {
                throw new RuntimeException("Uploaden van de bijlage {$attachment['name']} is mislukt (HTTP {$response->status()}).");
            }
        }
    }

    /**
     * Het verzonden MIME-bericht uit de mailbox; lukt dat niet direct, dan een lokaal opgebouwde
     * versie met dezelfde inhoud (gemarkeerd met een kopregel).
     */
    private function mime(string $sender, string $id, OutboundEmail $email): string
    {
        for ($attempt = 1; $attempt <= self::MIME_ATTEMPTS; $attempt++) {
            try {
                $response = $this->client()->get(self::GRAPH."/users/{$sender}/messages/".rawurlencode($id).'/$value');

                if ($response->successful() && $response->body() !== '') {
                    return $response->body();
                }
            } catch (ConnectionException) {
                // Volgende poging.
            }

            if ($attempt < self::MIME_ATTEMPTS) {
                usleep(1_000_000);
            }
        }

        return $this->localMime($email);
    }

    private function localMime(OutboundEmail $email): string
    {
        $message = (new Email)
            ->from(new Address($email->from, (string) $email->from_name))
            ->to(...$email->to)
            ->subject($email->subject)
            ->html($email->body_html)
            ->text($email->body_text);

        if (filled($email->cc)) {
            $message->cc(...$email->cc);
        }

        foreach ($email->attachments ?? [] as $attachment) {
            if (Storage::disk('local')->exists($attachment['path'])) {
                $message->attachFromPath(Storage::disk('local')->path($attachment['path']), $attachment['name'], $attachment['mime']);
            }
        }

        $message->getHeaders()->addTextHeader('X-PHPZoeker-Snapshot', 'lokaal opgebouwd; verzonden via Microsoft Graph');

        return $message->toString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $path, array $data = []): Response
    {
        try {
            $client = $this->client();
            $response = match ($method) {
                'post' => $client->post(self::GRAPH.$path, $data === [] ? (object) [] : $data),
                'patch' => $client->patch(self::GRAPH.$path, $data),
                default => $client->get(self::GRAPH.$path, $data),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Microsoft Graph is niet bereikbaar.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw new RuntimeException(match ($response->status()) {
            401 => 'Microsoft 365 weigert de toegang: log opnieuw in via Instellingen → Mail → "Inloggen bij Microsoft".',
            403 => "Het ingelogde account mag niet versturen vanuit {$this->settings->sender_mailbox}. Log in met dat account, of geef het ingelogde account het recht \"Verzenden als\" op die mailbox.",
            404 => "De afzendermailbox {$this->settings->sender_mailbox} is niet gevonden in Microsoft 365.",
            429 => 'Microsoft 365 beperkt het aantal verzoeken; probeer het over een minuut opnieuw.',
            default => 'Microsoft Graph gaf HTTP '.$response->status().': '.SecretRedactor::redact(mb_substr((string) $response->json('error.message', ''), 0, 200)),
        });
    }

    private function client(): PendingRequest
    {
        $token = $this->login->tokenFor(new SenderConnection($this->settings), 'Verzenden via Microsoft 365 is nog niet verbonden. Klik in Instellingen → Mail op "Inloggen bij Microsoft".');

        return Http::withToken($token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->timeout(60)
            ->acceptJson();
    }
}
