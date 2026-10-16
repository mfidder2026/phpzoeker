<?php

use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Filament\Auth\Login;
use App\Livewire\MicrosoftSignInPanel;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\MailSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'phpzoeker.allowed_accounts' => ['michel@fremen.app', 'mark@fremen.app', 'sten@fremen.app'],
        'phpzoeker.admin.email' => 'michel@fremen.app',
    ]);
});

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

it('shows only the Microsoft sign-in on the login page', function () {
    $this->get('/login')->assertOk()
        ->assertSee('Inloggen met Microsoft')
        ->assertDontSee('type="password"', false);
});

it('refuses to log in with a password', function () {
    userWithRoles(Role::Beheerder)->update(['email' => 'michel@fremen.app']);

    Livewire::test(Login::class)
        ->set('data.email', 'michel@fremen.app')
        ->set('data.password', 'password')
        ->call('authenticate')
        ->assertForbidden();

    expect(Filament::auth()->check())->toBeFalse();
});

it('signs Michel in with Microsoft and connects mail sending and the mailbox', function () {
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

it('refuses an account that is not on the list, even when it exists as a user', function () {
    userWithRoles(Role::Beheerder)->update(['email' => 'collega@fremen.app']);
    fakeMicrosoftSignIn('collega@fremen.app');

    Livewire::test(MicrosoftSignInPanel::class)
        ->call('start')
        ->call('check')
        ->assertSet('state', 'error')
        ->assertSee('Het account collega@fremen.app heeft geen toegang tot PHP Zoeker');

    expect(Filament::auth()->check())->toBeFalse();
});

it('refuses an account from another Microsoft tenant', function () {
    $settings = app(GeneralSettings::class);
    $settings->microsoft_tenant_id = 'tenant-fremen';
    $settings->save();
    fakeMicrosoftSignIn('sten@fremen.app', 'tenant-anders');

    Livewire::test(MicrosoftSignInPanel::class)
        ->call('start')
        ->call('check')
        ->assertSet('state', 'error')
        ->assertSee('hoort niet bij de organisatie van Fremen');

    expect(Filament::auth()->check())->toBeFalse();
});

it('creates Mark as beheerder on his first sign-in without touching the mail connections', function () {
    userWithRoles(Role::Beheerder)->update(['email' => 'michel@fremen.app']);
    fakeMicrosoftSignIn('Mark@Fremen.app');

    Livewire::test(MicrosoftSignInPanel::class)->call('start')->call('check')->assertRedirect(Filament::getUrl());

    $mark = User::query()->where('email', 'mark@fremen.app')->sole();
    app()->forgetInstance(MailSettings::class);

    expect(Filament::auth()->id())->toBe($mark->id)
        ->and($mark->hasRole(Role::Beheerder->value))->toBeTrue()
        ->and(app(MailSettings::class)->graph_refresh_token)->toBeNull();
});

it('logs out a session of an account that is not on the list', function () {
    $user = userWithRoles(Role::Beheerder);
    $user->update(['email' => 'oud@fremen.app']);

    $this->actingAs($user)->get(Filament::getUrl())->assertRedirect(Filament::getLoginUrl());
    expect(Filament::auth()->check())->toBeFalse();
});

it('limits the number of sign-in attempts', function () {
    fakeMicrosoftSignIn();
    $component = Livewire::test(MicrosoftSignInPanel::class);

    foreach (range(1, 5) as $attempt) {
        $component->call('start');
    }

    $component->call('start')->assertSet('state', 'error')->assertSee('Te veel pogingen');
});
