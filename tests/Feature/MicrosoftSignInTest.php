<?php

use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Livewire\MicrosoftSignInPanel;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeMicrosoftSignIn(string $account = 'michel@fremen.app', string $tenant = 'tenant-fremen'): void
{
    Http::fake([
        'login.microsoftonline.com/organizations/oauth2/v2.0/devicecode' => Http::response(['device_code' => 'device-1', 'user_code' => 'ABCD-1234', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_in' => 900, 'interval' => 5]),
        'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh-from-sign-in', 'id_token' => senderIdToken(['preferred_username' => $account, 'tid' => $tenant])]),
    ]);
}

function alertsMailbox(): Source
{
    return Source::query()->create([
        'key' => 'alerts-mailbox', 'name' => 'Aanvragen-mailbox', 'type' => SourceType::MailboxAlert, 'enabled' => false,
        'config' => ['protocol' => 'graph', 'auth' => 'delegated', 'mailbox' => 'aanvragen@fremen.app', 'folder' => 'Inbox'],
    ]);
}

it('shows the Microsoft sign-in on the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Inloggen met Microsoft');
});

it('signs a beheerder in with Microsoft and connects mail sending and the mailbox', function () {
    $user = userWithRoles(Role::Beheerder);
    $user->update(['email' => 'michel@fremen.app']);
    $source = alertsMailbox();
    fakeMicrosoftSignIn('Michel@Fremen.app');

    Livewire::test(MicrosoftSignInPanel::class)
        ->call('start')
        ->assertSee('ABCD-1234')
        ->call('check')
        ->assertRedirect(Filament::getUrl());

    expect(Filament::auth()->id())->toBe($user->id);

    app()->forgetInstance(MailSettings::class);
    app()->forgetInstance(GeneralSettings::class);
    $mail = app(MailSettings::class);

    expect($mail->provider)->toBe('graph')
        ->and($mail->graph_refresh_token)->toBe('refresh-from-sign-in')
        ->and($source->refresh()->config['refresh_token'])->toBe('refresh-from-sign-in')
        ->and($source->config['connected_account'])->toBe('michel@fremen.app')
        ->and(app(GeneralSettings::class)->microsoft_tenant_id)->toBe('tenant-fremen');
});

it('refuses an account that is not a user of PHP Zoeker', function () {
    userWithRoles(Role::Beheerder);
    fakeMicrosoftSignIn('vreemde@elders.example');

    Livewire::test(MicrosoftSignInPanel::class)
        ->call('start')
        ->call('check')
        ->assertSet('state', 'error')
        ->assertSee('Er is geen gebruiker van PHP Zoeker met het adres vreemde@elders.example');

    expect(Filament::auth()->check())->toBeFalse();
});

it('refuses an account from another Microsoft tenant', function () {
    $user = userWithRoles(Role::Accountmanager);
    $user->update(['email' => 'collega@fremen.app']);
    $settings = app(GeneralSettings::class);
    $settings->microsoft_tenant_id = 'tenant-fremen';
    $settings->save();
    fakeMicrosoftSignIn('collega@fremen.app', 'tenant-anders');

    Livewire::test(MicrosoftSignInPanel::class)
        ->call('start')
        ->call('check')
        ->assertSet('state', 'error')
        ->assertSee('hoort niet bij de organisatie van Fremen');

    expect(Filament::auth()->check())->toBeFalse();
});

it('signs in an account manager without touching the mail connections', function () {
    $user = userWithRoles(Role::Accountmanager);
    $user->update(['email' => 'collega@fremen.app']);
    fakeMicrosoftSignIn('collega@fremen.app');

    Livewire::test(MicrosoftSignInPanel::class)->call('start')->call('check')->assertRedirect(Filament::getUrl());

    app()->forgetInstance(MailSettings::class);
    expect(Filament::auth()->id())->toBe($user->id)
        ->and(app(MailSettings::class)->graph_refresh_token)->toBeNull();
});

it('limits the number of sign-in attempts', function () {
    fakeMicrosoftSignIn();
    $component = Livewire::test(MicrosoftSignInPanel::class);

    foreach (range(1, 5) as $attempt) {
        $component->call('start');
    }

    $component->call('start')->assertSet('state', 'error')->assertSee('Te veel pogingen');
});
