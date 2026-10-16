<?php

namespace App\Domain\Sources\Mailbox;

use App\Ai\Support\SecretRedactor;
use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Eenmalig inloggen bij Microsoft 365 met een code ("device code flow"), zonder eigen
 * app-registratie in de tenant. De gebruiker logt in op de inlogpagina van Microsoft en geeft
 * toestemming voor de rechten van de verbinding: lezen en markeren van mail (aanvragen-mailbox)
 * of versturen (afzendermailbox). De app bewaart alleen het vernieuwingstoken (versleuteld) en
 * vernieuwt het zelf.
 */
class MicrosoftLogin
{
    /**
     * Openbare client van Microsoft ("Microsoft Graph Command Line Tools"), dezelfde die
     * Microsoft Graph PowerShell gebruikt. Daardoor is geen eigen app-registratie nodig.
     */
    public const CLIENT_ID = '14d82eec-204b-4c2f-b7e8-296a70dab67e';

    public const SCOPES = SourceConnection::SCOPES;

    private const AUTHORITY = 'https://login.microsoftonline.com';

    /**
     * Start de inlogstroom.
     *
     * @return array{user_code: string, verification_uri: string, expires_at: int, interval: int}
     */
    public function start(Source|MicrosoftConnection $connection): array
    {
        $connection = self::connection($connection);

        $response = $this->post('/organizations/oauth2/v2.0/devicecode', [
            'client_id' => self::CLIENT_ID,
            'scope' => $connection->scopes(),
        ]);

        $code = (string) $response['device_code'];
        $result = [
            'user_code' => (string) $response['user_code'],
            'verification_uri' => (string) ($response['verification_uri'] ?? 'https://microsoft.com/devicelogin'),
            'expires_at' => time() + (int) ($response['expires_in'] ?? 900),
            'interval' => max(3, (int) ($response['interval'] ?? 5)),
        ];

        Cache::put(self::cacheKey($connection), Crypt::encryptString($code), $result['expires_at'] - time());

        return $result;
    }

    /**
     * Kijkt of de gebruiker klaar is met inloggen. Bij succes wordt de verbinding opgeslagen.
     *
     * @return 'pending'|'connected'|'expired'
     */
    public function poll(Source|MicrosoftConnection $connection): string
    {
        $connection = self::connection($connection);
        $encrypted = Cache::get(self::cacheKey($connection));

        if (! is_string($encrypted)) {
            return 'expired';
        }

        try {
            $response = Http::asForm()->timeout(20)->post(self::AUTHORITY.'/organizations/oauth2/v2.0/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => self::CLIENT_ID,
                'device_code' => Crypt::decryptString($encrypted),
            ]);
        } catch (ConnectionException) {
            return 'pending';
        }

        $error = (string) $response->json('error', '');

        if (in_array($error, ['authorization_pending', 'slow_down'], true)) {
            return 'pending';
        }

        if ($error !== '') {
            Cache::forget(self::cacheKey($connection));

            if ($error === 'expired_token') {
                return 'expired';
            }

            throw new RuntimeException(match ($error) {
                'authorization_declined' => 'Het inloggen is geweigerd of afgebroken.',
                'invalid_grant' => 'Deze inlogcode is al gebruikt of ongeldig; start opnieuw.',
                default => 'Inloggen bij Microsoft is mislukt ('.$error.'): '.mb_substr((string) $response->json('error_description', ''), 0, 200),
            });
        }

        Cache::forget(self::cacheKey($connection));
        $this->store($connection, (array) $response->json());

        return 'connected';
    }

    /**
     * Toegangstoken voor een mailbox-bron (configuratie van de bron, inclusief `source_id`).
     *
     * @param  array<string, mixed>  $config
     */
    public function accessToken(array $config): string
    {
        $refresh = (string) ($config['refresh_token'] ?? '');
        $sourceId = (int) ($config['source_id'] ?? 0);

        if ($refresh === '') {
            throw new RuntimeException('De mailbox is nog niet verbonden. Klik bij de bron op "Inloggen bij Microsoft".');
        }

        return $this->refresh(
            cacheKey: self::tokenCacheKey($sourceId),
            refreshToken: $refresh,
            tenant: (string) ($config['tenant'] ?? 'organizations'),
            scopes: SourceConnection::SCOPES,
            expiredMessage: 'De verbinding met Microsoft 365 is verlopen of ingetrokken. Log bij de bron opnieuw in met "Inloggen bij Microsoft".',
            onNewRefreshToken: function (string $token) use ($sourceId): void {
                $source = Source::query()->find($sourceId);
                $source?->forceFill(['config' => [...($source->config ?? []), 'refresh_token' => $token]])->saveQuietly();
            },
        );
    }

    /**
     * Toegangstoken voor een willekeurige verbinding (bijv. de afzendermailbox).
     */
    public function tokenFor(MicrosoftConnection $connection, string $notConnectedMessage): string
    {
        $refresh = $connection->refreshToken();

        if ($refresh === null) {
            throw new RuntimeException($notConnectedMessage);
        }

        return $this->refresh(
            cacheKey: 'graph-delegated-token:'.$connection->key(),
            refreshToken: $refresh,
            tenant: $connection->tenant(),
            scopes: $connection->scopes(),
            expiredMessage: $notConnectedMessage,
            onNewRefreshToken: fn (string $token) => $connection->store($token, $connection->tenant(), (string) $connection->account()),
        );
    }

    public function disconnect(Source|MicrosoftConnection $connection): void
    {
        $connection = self::connection($connection);
        $connection->forget();
        Cache::forget('graph-delegated-token:'.$connection->key());

        if ($connection instanceof SourceConnection) {
            Cache::forget(self::tokenCacheKey($connection->source->id));
        }
    }

    /**
     * Leest de claims uit een JWT zonder handtekeningcontrole: alleen voor weergave
     * (account en tenant); de tokens komen rechtstreeks van Microsoft.
     *
     * @return array<string, mixed>
     */
    public static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        return is_array($claims) ? $claims : [];
    }

    public static function tokenCacheKey(int $sourceId): string
    {
        return 'graph-delegated-token:'.$sourceId;
    }

    public static function connection(Source|MicrosoftConnection $connection): MicrosoftConnection
    {
        return $connection instanceof Source ? new SourceConnection($connection) : $connection;
    }

    /**
     * Nieuw toegangstoken met het vernieuwingstoken (50 minuten gecachet). Microsoft geeft daarbij
     * een nieuw vernieuwingstoken terug; dat wordt meteen bewaard, zodat de verbinding blijft werken.
     *
     * @param  callable(string): void  $onNewRefreshToken
     */
    private function refresh(string $cacheKey, string $refreshToken, string $tenant, string $scopes, string $expiredMessage, callable $onNewRefreshToken): string
    {
        SecretRedactor::register($refreshToken);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($refreshToken, $tenant, $scopes, $expiredMessage, $onNewRefreshToken): string {
            try {
                $response = Http::asForm()->timeout(20)->post(self::AUTHORITY."/{$tenant}/oauth2/v2.0/token", [
                    'grant_type' => 'refresh_token',
                    'client_id' => self::CLIENT_ID,
                    'refresh_token' => $refreshToken,
                    'scope' => $scopes,
                ]);
            } catch (ConnectionException) {
                throw new RuntimeException('Het aanmeldpunt van Microsoft is niet bereikbaar.');
            }

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException($expiredMessage);
            }

            if (is_string($response->json('refresh_token'))) {
                $onNewRefreshToken($response->json('refresh_token'));
            }

            return $token;
        });
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function store(MicrosoftConnection $connection, array $tokens): void
    {
        $claims = self::claims((string) ($tokens['id_token'] ?? $tokens['access_token'] ?? ''));
        Cache::forget('graph-delegated-token:'.$connection->key());

        if ($connection instanceof SourceConnection) {
            Cache::forget(self::tokenCacheKey($connection->source->id));
        }

        $account = (string) ($claims['preferred_username'] ?? $claims['upn'] ?? $claims['unique_name'] ?? '');
        $connection->store((string) ($tokens['refresh_token'] ?? ''), (string) ($claims['tid'] ?? 'organizations'), $account);

        // Inloggen bij de app zelf wordt apart gelogd (SignInWithMicrosoft).
        if (! $connection instanceof SourceConnection && ! $connection instanceof SenderConnection) {
            return;
        }

        $log = activity($connection instanceof SourceConnection ? 'bronnen' : 'instellingen')->causedBy(auth()->user())->withProperties(['account' => $account ?: null]);

        if ($connection instanceof SourceConnection) {
            $log->performedOn($connection->source);
        }

        $log->log($connection instanceof SourceConnection ? 'Verbonden met Microsoft 365' : 'Afzendermailbox verbonden met Microsoft 365');
    }

    private static function cacheKey(MicrosoftConnection $connection): string
    {
        return 'microsoft-device-code:'.$connection->key();
    }

    /**
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        try {
            $response = Http::asForm()->timeout(20)->post(self::AUTHORITY.$path, $data);
        } catch (ConnectionException) {
            throw new RuntimeException('Het aanmeldpunt van Microsoft is niet bereikbaar.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Inloggen bij Microsoft kon niet starten: '.mb_substr((string) $response->json('error_description', 'HTTP '.$response->status()), 0, 200));
        }

        return (array) $response->json();
    }
}
