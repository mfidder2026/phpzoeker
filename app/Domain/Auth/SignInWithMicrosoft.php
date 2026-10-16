<?php

namespace App\Domain\Auth;

use App\Domain\Candidates\Models\Candidate;
use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Mailbox\SourceConnection;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Inloggen met een Microsoft 365-account (eenmalige code, geen app-registratie); de enige manier
 * van inloggen (D56). Alleen voor de toegestane accounts (config phpzoeker.allowed_accounts), die
 * bij hun eerste inlog als beheerder worden aangemaakt, en alleen uit de tenant van Fremen (vastgelegd
 * bij de eerste inlog). Bij het hoofdaccount koppelt dezelfde inlog ook verzenden via Microsoft 365
 * en de aanvragen-mailbox, als die nog niet gekoppeld zijn.
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
        $account = strtolower((string) $connection->account());

        if ($account === '' || ! in_array($account, (array) config('phpzoeker.allowed_accounts'), true)) {
            throw new RuntimeException("Het account {$account} heeft geen toegang tot PHP Zoeker.");
        }

        $tenant = $connection->tenant();

        if (filled($this->general->microsoft_tenant_id) && $this->general->microsoft_tenant_id !== $tenant) {
            throw new RuntimeException('Dit Microsoft-account hoort niet bij de organisatie van Fremen.');
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$account])->first()
            ?? User::query()->create(['name' => $this->nameFor($account), 'email' => $account, 'password' => Str::password(40)]);

        // Alle toegestane accounts zijn beheerder (D56).
        if (! $user->hasRole(Role::Beheerder->value)) {
            RoleModel::findOrCreate(Role::Beheerder->value, 'web');
            RoleModel::findOrCreate(Role::Accountmanager->value, 'web');
            $user->assignRole(Role::Beheerder->value, Role::Accountmanager->value);
        }

        if (blank($this->general->microsoft_tenant_id)) {
            $this->general->microsoft_tenant_id = $tenant;
            $this->general->save();
        }

        // Verzenden en de aanvragen-mailbox koppelen alleen bij het hoofdaccount, zodat de app niet
        // per ongeluk vanuit een collega gaat versturen.
        $connected = $account === strtolower((string) config('phpzoeker.admin.email')) ? $this->connectMail($connection) : [];

        activity('gebruikers')->causedBy($user)->performedOn($user)->withProperties(['account' => $account, 'gekoppeld' => $connected])->log('Ingelogd met Microsoft');

        return ['user' => $user, 'connected' => $connected];
    }

    /**
     * Naam voor een nieuw account: van de kandidaat met dit adres, anders uit het adres zelf.
     */
    private function nameFor(string $account): string
    {
        return Candidate::query()->whereRaw('lower(email) = ?', [$account])->value('name')
            ?? Str::of($account)->before('@')->replace(['.', '_'], ' ')->title()->toString();
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
