<?php

use App\Domain\Sources\Actions\RunSource;
use App\Domain\Sources\Connectors\MailboxAlertConnector;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\ProcessRawPostingJob;
use App\Domain\Sources\Mailbox\GraphMailboxClient;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Filament\Resources\Sources\Pages\EditSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const GRAPH_SECRET = 'graph-client-secret-abc123XYZ';

function graphSource(array $config = []): Source
{
    return Source::query()->create([
        'key' => 'aanvragen-'.uniqid(),
        'name' => 'Aanvragen-mailbox',
        'type' => SourceType::MailboxAlert,
        'config' => [
            'protocol' => 'graph',
            'auth' => 'app',
            'mailbox' => 'aanvragen@fremen.app',
            'folder' => 'Inbox',
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-456',
            'client_secret' => GRAPH_SECRET,
            'internal_domains' => ['fremen.app'],
            ...$config,
        ],
        'tos_reviewed_at' => now(),
        'tos_notes' => 'Eigen mailbox.',
        'enabled' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function graphMessage(string $id, string $from, string $subject, array $categories = []): array
{
    return [
        'id' => $id,
        'internetMessageId' => "<{$id}@mail.example>",
        'subject' => $subject,
        'from' => ['emailAddress' => ['address' => $from]],
        'receivedDateTime' => now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z'),
        'body' => ['contentType' => 'text', 'content' => 'Laravel developer gezocht, 40 uur.'],
        'categories' => $categories,
    ];
}

it('reads the shared mailbox via Microsoft Graph and categorises processed mails', function () {
    Queue::fake();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-xyz', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/aanvragen%40fremen.app/mailFolders/Inbox/messages*' => Http::response(['value' => [
            graphMessage('m1', 'alerts@platform.example', 'Nieuwe opdrachten voor jou'),
            graphMessage('m2', 'collega@fremen.app', 'Fwd: aanvraag Laravel'),
            graphMessage('m3', 'alerts@platform.example', 'Al verwerkt', ['PHP Zoeker – verwerkt']),
        ]]),
        'graph.microsoft.com/v1.0/users/aanvragen%40fremen.app/messages/*' => function (Request $request) {
            return $request->method() === 'PATCH' ? Http::response([], 200) : Http::response(['categories' => ['Rood']]);
        },
    ]);

    $run = app(RunSource::class)->handle(graphSource());
    $postings = RawPosting::query()->orderBy('id')->get();

    expect($run->new_count)->toBe(2)
        ->and($postings->pluck('meta.kind')->all())->toBe(['alert_mail', 'forwarded_mail'])
        ->and($postings[0]->external_id)->toBe('<m1@mail.example>');

    Queue::assertPushed(ProcessRawPostingJob::class, 2);

    // Categorie toegevoegd met behoud van bestaande categorieën; nooit "isRead" of verwijderen.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['categories'] === ['Rood', 'PHP Zoeker – verwerkt']
        && ! isset($request['isRead']));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'login.microsoftonline.com/tenant-123/oauth2/v2.0/token')
        && $request['scope'] === 'https://graph.microsoft.com/.default');
});

it('explains Graph errors in plain language without leaking the secret', function (int $status, string $expected) {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-xyz']),
        'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'denied '.GRAPH_SECRET]], $status),
    ]);

    $result = app(MailboxAlertConnector::class)->test(graphSource());

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain($expected)
        ->and($result->message)->not->toContain(GRAPH_SECRET);
})->with([
    'geen rechten' => [403, 'Geen rechten op deze mailbox'],
    'niet gevonden' => [404, 'niet gevonden'],
]);

it('reports an invalid client secret', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client'], 401)]);

    $result = app(MailboxAlertConnector::class)->test(graphSource());

    expect($result->message)->toBe('Client-ID of client-secret is ongeldig (of het secret is verlopen).');
});

it('asks for the app registration when it is missing', function () {
    Http::fake();

    $result = app(MailboxAlertConnector::class)->test(graphSource(['client_secret' => '']));

    expect($result->message)->toContain('tenant-ID, client-ID en client-secret');
    Http::assertNothingSent();
});

it('never sends the client secret back to the browser', function () {
    $source = graphSource();

    $this->actingAs(beheerder())->get('/bronnen/'.$source->id.'/edit')
        ->assertOk()
        ->assertSee('aanvragen@fremen.app')
        ->assertDontSee(GRAPH_SECRET);
});

it('keeps the stored secret when the field is left empty', function () {
    $source = graphSource();
    $this->actingAs(beheerder());

    Livewire\Livewire::test(EditSource::class, ['record' => $source->getRouteKey()])
        ->set('data.config.folder', 'Aanvragen')
        ->call('save')
        ->assertHasNoErrors();

    expect($source->refresh()->config['client_secret'])->toBe(GRAPH_SECRET)
        ->and($source->config['folder'])->toBe('Aanvragen')
        ->and(GraphMailboxClient::category($source->config))->toBe('PHP Zoeker – verwerkt');
});

/**
 * Een (niet ondertekend) JWT met de claims die Microsoft in het id-token zet.
 */
function fakeIdToken(array $claims): string
{
    $encode = fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

    return $encode(['alg' => 'none']).'.'.$encode($claims).'.';
}

it('connects the mailbox with a one-time Microsoft sign-in', function () {
    Http::fake([
        'login.microsoftonline.com/organizations/oauth2/v2.0/devicecode' => Http::response([
            'device_code' => 'device-123', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_in' => 900, 'interval' => 5,
        ]),
        'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::sequence()
            ->push(['error' => 'authorization_pending'], 400)
            ->push(['access_token' => 'access-1', 'refresh_token' => 'refresh-1-very-secret', 'id_token' => fakeIdToken(['preferred_username' => 'michel@fremen.app', 'tid' => 'tenant-fremen'])]),
    ]);
    $source = graphSource(['auth' => 'delegated', 'client_secret' => null]);
    $login = app(MicrosoftLogin::class);

    $start = $login->start($source);

    expect($start['user_code'])->toBe('ABCD-EFGH')
        ->and($login->poll($source))->toBe('pending')
        ->and($login->poll($source))->toBe('connected')
        ->and($source->refresh()->config['refresh_token'])->toBe('refresh-1-very-secret')
        ->and($source->config['connected_account'])->toBe('michel@fremen.app')
        ->and($source->config['tenant'])->toBe('tenant-fremen');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'devicecode')
        && $request['client_id'] === MicrosoftLogin::CLIENT_ID
        && str_contains((string) $request['scope'], 'Mail.ReadWrite.Shared')
        && str_contains((string) $request['scope'], 'offline_access'));
});

it('reads the mailbox with the stored sign-in and keeps the renewed token', function () {
    Queue::fake();
    Http::fake([
        'login.microsoftonline.com/tenant-fremen/oauth2/v2.0/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2-renewed']),
        'graph.microsoft.com/v1.0/users/aanvragen%40fremen.app/mailFolders/Inbox/messages*' => Http::response(['value' => [graphMessage('m9', 'alerts@platform.example', 'Opdracht')]]),
        'graph.microsoft.com/v1.0/users/aanvragen%40fremen.app/messages/*' => Http::response(['categories' => []]),
    ]);
    $source = graphSource(['auth' => 'delegated', 'refresh_token' => 'refresh-1-old', 'tenant' => 'tenant-fremen', 'connected_account' => 'michel@fremen.app']);

    $run = app(RunSource::class)->handle($source);

    expect($run->new_count)->toBe(1)
        ->and($source->refresh()->config['refresh_token'])->toBe('refresh-2-renewed');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer access-2'));
});

it('asks to sign in again when the connection is gone', function () {
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

    $result = app(MailboxAlertConnector::class)->test(graphSource(['auth' => 'delegated', 'refresh_token' => 'revoked-token-123']));

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('opnieuw in met "Inloggen bij Microsoft"')
        ->and($result->message)->not->toContain('revoked-token-123');
});

it('shows the connection status but never the refresh token', function () {
    $source = graphSource(['auth' => 'delegated', 'refresh_token' => 'refresh-secret-xyz-987', 'connected_account' => 'michel@fremen.app', 'connected_at' => now()->toIso8601String()]);

    $this->actingAs(beheerder())->get('/bronnen/'.$source->id.'/edit')
        ->assertOk()
        ->assertSee('Verbonden met Microsoft 365 als michel@fremen.app')
        ->assertSee('Opnieuw inloggen bij Microsoft')
        ->assertDontSee('refresh-secret-xyz-987');
});
