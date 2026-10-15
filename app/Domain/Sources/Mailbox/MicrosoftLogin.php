<?php

namespace App\Domain\Sources\Mailbox;

use App\Ai\Support\SecretRedactor;
use App\Domain\Sources\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Eenmalig inloggen bij Microsoft 365 met een code ("device code flow"), zonder eigen
 * app-registratie in de tenant. De gebruiker logt in op de inlogpagina van Microsoft en geeft
 * toestemming voor het lezen en markeren van mail in mailboxen waar hij toegang toe heeft.
 * De app bewaart alleen het vernieuwingstoken (versleuteld) en vernieuwt het zelf.
 */
class MicrosoftLogin
{
    /**
     * Openbare client van Microsoft ("Microsoft Graph Command Line Tools"), dezelfde die
     * Microsoft Graph PowerShell gebruikt. Daardoor is geen eigen app-registratie nodig.
     */
    public const CLIENT_ID = '14d82eec-204b-4c2f-b7e8-296a70dab67e';

    public const SCOPES = 'offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.ReadWrite.Shared';

    private const AUTHORITY = 'https://login.microsoftonline.com';

    /**
     * Start de inlogstroom.
     *
     * @return array{user_code: string, verification_uri: string, expires_at: int, interval: int}
     */
    public function start(Source $source): array
    {
        $response = $this->post('/organizations/oauth2/v2.0/devicecode', [
            'client_id' => self::CLIENT_ID,
            'scope' => self::SCOPES,
        ]);

        $code = (string) $response['device_code'];
        $result = [
            'user_code' => (string) $response['user_code'],
            'verification_uri' => (string) ($response['verification_uri'] ?? 'https://microsoft.com/devicelogin'),
            'expires_at' => time() + (int) ($response['expires_in'] ?? 900),
            'interval' => max(3, (int) ($response['interval'] ?? 5)),
        ];

        Cache::put(self::cacheKey($source), Crypt::encryptString($code), $result['expires_at'] - time());

        return $result;
    }

    /**
     * Kijkt of de gebruiker klaar is met inloggen. Bij succes wordt de verbinding bij de bron
     * opgeslagen.
     *
     * @return 'pending'|'connected'|'expired'
     */
    public function poll(Source $source): string
    {
        $encrypted = Cache::get(self::cacheKey($source));

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
            Cache::forget(self::cacheKey($source));

            if ($error === 'expired_token') {
                return 'expired';
            }

            throw new RuntimeException(match ($error) {
                'authorization_declined' => 'Het inloggen is geweigerd of afgebroken.',
                'invalid_grant' => 'Deze inlogcode is al gebruikt of ongeldig; start opnieuw.',
                default => 'Inloggen bij Microsoft is mislukt ('.$error.'): '.mb_substr((string) $response->json('error_description', ''), 0, 200),
            });
        }

        Cache::forget(self::cacheKey($source));
        $this->store($source, $response->json());

        return 'connected';
    }

    /**
     * Nieuw toegangstoken met het vernieuwingstoken. Microsoft geeft daarbij een nieuw
     * vernieuwingstoken terug; dat wordt meteen bewaard, zodat de verbinding blijft werken.
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

        SecretRedactor::register($refresh);

        return Cache::remember(self::tokenCacheKey($sourceId), now()->addMinutes(50), function () use ($config, $refresh, $sourceId): string {
            $tenant = (string) ($config['tenant'] ?? 'organizations');

            try {
                $response = Http::asForm()->timeout(20)->post(self::AUTHORITY."/{$tenant}/oauth2/v2.0/token", [
                    'grant_type' => 'refresh_token',
                    'client_id' => self::CLIENT_ID,
                    'refresh_token' => $refresh,
                    'scope' => self::SCOPES,
                ]);
            } catch (ConnectionException) {
                throw new RuntimeException('Het aanmeldpunt van Microsoft is niet bereikbaar.');
            }

            $token = $response->json('access_token');

            if (! $response->successful() || ! is_string($token)) {
                throw new RuntimeException('De verbinding met Microsoft 365 is verlopen of ingetrokken. Log bij de bron opnieuw in met "Inloggen bij Microsoft".');
            }

            $source = Source::query()->find($sourceId);

            if ($source !== null && is_string($response->json('refresh_token'))) {
                $source->forceFill(['config' => [...($source->config ?? []), 'refresh_token' => $response->json('refresh_token')]])->saveQuietly();
            }

            return $token;
        });
    }

    public function disconnect(Source $source): void
    {
        $config = $source->config ?? [];
        unset($config['refresh_token'], $config['connected_account'], $config['connected_at'], $config['tenant']);
        $source->forceFill(['config' => $config, 'enabled' => false])->save();
        Cache::forget(self::tokenCacheKey($source->id));
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function store(Source $source, array $tokens): void
    {
        $claims = self::claims((string) ($tokens['id_token'] ?? $tokens['access_token'] ?? ''));
        Cache::forget(self::tokenCacheKey($source->id));

        $source->forceFill(['config' => [
            ...($source->config ?? []),
            'auth' => 'delegated',
            'refresh_token' => (string) ($tokens['refresh_token'] ?? ''),
            'tenant' => (string) ($claims['tid'] ?? 'organizations'),
            'connected_account' => (string) ($claims['preferred_username'] ?? $claims['upn'] ?? $claims['unique_name'] ?? ''),
            'connected_at' => now()->toIso8601String(),
        ]])->save();

        activity('bronnen')->causedBy(auth()->user())->performedOn($source)
            ->withProperties(['account' => $claims['preferred_username'] ?? null])
            ->log('Verbonden met Microsoft 365');
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

    private static function cacheKey(Source $source): string
    {
        return 'microsoft-device-code:'.$source->id;
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
