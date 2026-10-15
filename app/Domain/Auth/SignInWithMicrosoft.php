<?php

namespace App\Domain\Auth;

use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Mailbox\SourceConnection;
use App\Domain\Sources\Models\Source;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Inloggen met een Microsoft 365-account (eenmalige code, geen app-registratie). Alleen voor
 * bestaande gebruikers van PHP Zoeker (op e-mailadres) en alleen uit de tenant van Fremen: die
 * wordt vastgelegd bij de eerste inlog van een beheerder. Bij een beheerder koppelt dezelfde inlog
 * ook verzenden via Microsoft 365 en de aanvragen-mailbox, als die nog niet gekoppeld zijn.
 */
class SignInWithMicrosoft
{
    public function __construct(
        private readonly GeneralSettings $general,
        private readonly MailSettings $mail,
    ) {}

    /**
     * @return array{user: User, connected: list<string>}
     *
     * @throws RuntimeException
     */
    public function handle(MicrosoftSignInConnection $connection): array
    {
        $account = (string) $connection->account();
        $user = $account !== '' ? User::query()->whereRaw('lower(email) = ?', [$account])->first() : null;

        if ($user === null) {
            throw new RuntimeException("Er is geen gebruiker van PHP Zoeker met het adres {$account}. Vraag de beheerder om een account.");
        }

        if (! $user->hasAnyRole(['beheerder', 'accountmanager', 'reviewer', 'kandidaat'])) {
            throw new RuntimeException('Dit account heeft nog geen rol in PHP Zoeker.');
        }

        $tenant = $connection->tenant();

        if (filled($this->general->microsoft_tenant_id) && $this->general->microsoft_tenant_id !== $tenant) {
            throw new RuntimeException('Dit Microsoft-account hoort niet bij de organisatie van Fremen.');
        }

        if (blank($this->general->microsoft_tenant_id) && $user->isBeheerder()) {
            $this->general->microsoft_tenant_id = $tenant;
            $this->general->save();
        }

        $connected = $user->isBeheerder() ? $this->connectMail($connection) : [];

        activity('gebruikers')->causedBy($user)->performedOn($user)->withProperties(['account' => $account, 'gekoppeld' => $connected])->log('Ingelogd met Microsoft');

        return ['user' => $user, 'connected' => $connected];
    }

    /**
     * Koppelt verzenden en de aanvragen-mailbox met hetzelfde vernieuwingstoken, als die nog niet
     * gekoppeld zijn.
     *
     * @return list<string>
     */
    private function connectMail(MicrosoftSignInConnection $connection): array
    {
        $refresh = $connection->refreshToken();

        if ($refresh === null) {
            return [];
        }

        $connected = [];
        $sender = new SenderConnection($this->mail);

        if ($sender->refreshToken() === null) {
            $sender->store($refresh, $connection->tenant(), (string) $connection->account());
            $this->mail->provider = 'graph';
            $this->mail->save();
            Cache::forget('graph-delegated-token:sender');
            $connected[] = 'verzenden via Microsoft 365 (vanuit '.$this->mail->sender_mailbox.')';
        }

        $source = Source::query()->where('key', 'alerts-mailbox')->first();

        if ($source !== null && ($source->config['protocol'] ?? null) === 'graph' && ($source->config['auth'] ?? 'delegated') === 'delegated') {
            $sourceConnection = new SourceConnection($source);

            if ($sourceConnection->refreshToken() === null) {
                $sourceConnection->store($refresh, $connection->tenant(), (string) $connection->account());
                $connected[] = 'de mailbox '.($source->config['mailbox'] ?? 'aanvragen');
            }
        }

        return $connected;
    }
}
