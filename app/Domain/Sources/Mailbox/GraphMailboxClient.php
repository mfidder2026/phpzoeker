<?php

namespace App\Domain\Sources\Mailbox;

use App\Ai\Support\SecretRedactor;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft 365-mailbox via Microsoft Graph (§8.5), bijvoorbeeld het gedeelde postvak
 * aanvragen@fremen.app. Werkt met een app-registratie (client credentials) die alleen op die
 * mailbox rechten heeft. Verwerkte mails krijgen een categorie; gelezen/ongelezen blijft van
 * de collega's die de mailbox gebruiken, en er wordt niets verwijderd.
 */
class GraphMailboxClient implements MailboxClient
{
    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    public const DEFAULT_CATEGORY = 'PHP Zoeker – verwerkt';

    public function unprocessed(array $config, ?DateTimeImmutable $since, int $limit): iterable
    {
        $mailbox = $this->mailbox($config);
        $category = self::category($config);
        $folder = rawurlencode((string) ($config['folder'] ?? 'Inbox'));
        $from = $since?->modify('-1 day') ?? new DateTimeImmutable('-'.max(1, (int) ($config['since_days'] ?? 14)).' days');

        $response = $this->request($config, 'get', "/users/{$mailbox}/mailFolders/{$folder}/messages", [
            '$select' => 'id,internetMessageId,subject,from,receivedDateTime,body,categories',
            '$filter' => 'receivedDateTime ge '.$from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime desc',
            '$top' => min(100, max(1, $limit * 2)),
        ], ['Prefer' => 'outlook.body-content-type="text"']);

        $messages = [];

        foreach ((array) $response->json('value', []) as $item) {
            if (! is_array($item) || in_array($category, (array) ($item['categories'] ?? []), true)) {
                continue;
            }

            $messages[] = new MailMessageData(
                uid: (string) $item['id'],
                messageId: (string) ($item['internetMessageId'] ?? $item['id']),
                from: (string) ($item['from']['emailAddress']['address'] ?? ''),
                subject: (string) ($item['subject'] ?? ''),
                date: new DateTimeImmutable((string) ($item['receivedDateTime'] ?? 'now')),
                text: (string) ($item['body']['content'] ?? ''),
                html: null,
            );

            if (count($messages) >= $limit) {
                break;
            }
        }

        return $messages;
    }

    public function markProcessed(array $config, string $uid): void
    {
        $mailbox = $this->mailbox($config);
        $category = self::category($config);
        $id = rawurlencode($uid);

        $current = (array) $this->request($config, 'get', "/users/{$mailbox}/messages/{$id}", ['$select' => 'categories'])->json('categories', []);

        if (in_array($category, $current, true)) {
            return;
        }

        $this->request($config, 'patch', "/users/{$mailbox}/messages/{$id}", ['categories' => [...$current, $category]]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function category(array $config): string
    {
        $category = trim((string) ($config['processed_category'] ?? ''));

        return $category !== '' ? $category : self::DEFAULT_CATEGORY;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mailbox(array $config): string
    {
        $mailbox = trim((string) ($config['mailbox'] ?? ''));

        if ($mailbox === '' || ! filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Vul het adres van de mailbox in (bijv. aanvragen@fremen.app).');
        }

        return rawurlencode($mailbox);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function request(array $config, string $method, string $path, array $data = [], array $headers = []): Response
    {
        try {
            $request = Http::withToken($this->token($config))->withHeaders($headers)->timeout(30)->acceptJson();
            $response = $method === 'patch' ? $request->patch(self::GRAPH.$path, $data) : $request->get(self::GRAPH.$path, $data);
        } catch (ConnectionException) {
            throw new RuntimeException('Microsoft Graph is niet bereikbaar.');
        }

        if ($response->successful()) {
            return $response;
        }

        throw new RuntimeException(match ($response->status()) {
            401 => 'Microsoft Graph weigert de toegang: het token is ongeldig of verlopen.',
            403 => 'De app-registratie heeft geen rechten op deze mailbox. Controleer de rol "Application Mail.ReadWrite" met het bereik op deze mailbox (RBAC for Applications); een nieuwe toewijzing kan tot 2 uur duren.',
            404 => 'De mailbox of map is niet gevonden. Controleer het mailadres en de mapnaam (standaard "Inbox").',
            429 => 'Microsoft Graph beperkt het aantal verzoeken; de app probeert het bij de volgende run opnieuw.',
            default => 'Microsoft Graph gaf HTTP '.$response->status().': '.SecretRedactor::redact(mb_substr((string) $response->json('error.message', ''), 0, 200)),
        });
    }

    /**
     * Client-credentials-token, 50 minuten gecachet per app-registratie.
     *
     * @param  array<string, mixed>  $config
     */
    private function token(array $config): string
    {
        $tenant = trim((string) ($config['tenant_id'] ?? ''));
        $client = trim((string) ($config['client_id'] ?? ''));
        $secret = (string) ($config['client_secret'] ?? '');

        if ($tenant === '' || $client === '' || $secret === '') {
            throw new RuntimeException('Vul tenant-ID, client-ID en client-secret van de app-registratie in.');
        }

        SecretRedactor::register($secret);

        return Cache::remember('graph-token:'.sha1($tenant.$client.$secret), now()->addMinutes(50), function () use ($tenant, $client, $secret): string {
            try {
                $response = Http::asForm()->timeout(20)->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                    'client_id' => $client,
                    'client_secret' => $secret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);
            } catch (ConnectionException) {
                throw new RuntimeException('Het aanmeldpunt van Microsoft is niet bereikbaar.');
            }

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                $error = (string) $response->json('error', '');

                throw new RuntimeException(match ($error) {
                    'invalid_client' => 'Client-ID of client-secret is ongeldig (of het secret is verlopen).',
                    'unauthorized_client' => 'De app-registratie is niet gevonden in deze tenant.',
                    'invalid_request' => 'De tenant-ID is ongeldig.',
                    default => 'Aanmelden bij Microsoft is mislukt ('.($error !== '' ? $error : 'HTTP '.$response->status()).').',
                });
            }

            return $token;
        });
    }
}
