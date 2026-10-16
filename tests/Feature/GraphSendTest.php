<?php

use App\Domain\Offers\Actions\CreateFollowUpMail;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\SendOutboundEmail;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Enums\OutboundEmailStatus;
use App\Domain\Offers\Mailers\MicrosoftGraphMailer;
use App\Domain\Offers\Mailers\OutboundMailer;
use App\Domain\Offers\Mailers\SenderConnection;
use App\Domain\Sources\Mailbox\MicrosoftLogin;
use App\Livewire\MicrosoftLoginPanel;
use App\Settings\MailSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 10:32:00');
    Storage::fake('local');
    Notification::fake();
    fakePdfConverter();
    aiProfile();
    withoutConsent();
});

it('sends the offer via Microsoft 365 from the sender mailbox and keeps the sent MIME', function () {
    connectSender();
    fakeGraph();
    $user = beheerder();
    $offer = preparedOffer($user);

    expect(app(OutboundMailer::class))->toBeInstanceOf(MicrosoftGraphMailer::class);

    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/users/michel%40fremen.app/messages')) {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer graph-access')
            && $request->hasHeader('Prefer', 'IdType="ImmutableId"')
            && $request['toRecipients'] === [['emailAddress' => ['address' => 'recruiter@x.example']]]
            && $request['ccRecipients'] === [['emailAddress' => ['address' => 'mark@fremen.app']], ['emailAddress' => ['address' => 'michel@fremen.app']]]
            && str_contains($request['body']['content'], '€ 95 per uur, exclusief btw');
    });
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/draft-1/attachments')
        && $request['name'] === 'CV Alex Voorbeeld - Fremen.pdf'
        && base64_decode($request['contentBytes']) === "%PDF-1.4\n% test\n");
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/draft-1/send') && $request->method() === 'POST');

    $offer->refresh();
    $email = $offer->offerEmail();

    expect($offer->status)->toBe(OfferStatus::Verzonden)
        ->and($email->provider)->toBe('graph')
        ->and($email->provider_message_id)->toBe('draft-1')
        ->and($email->internet_message_id)->toBe('abc@eur.prod.outlook.com')
        ->and($email->conversation_id)->toBe('conv-1')
        ->and(Storage::disk('local')->get($offer->snapshot->eml_path))->toContain('Message-ID: <abc@eur.prod.outlook.com>')
        ->and($offer->snapshot->isIntact())->toBeTrue();

    // Het vernieuwde token is bewaard, versleuteld.
    app()->forgetInstance(MailSettings::class);
    expect(app(MailSettings::class)->graph_refresh_token)->toBe('sender-refresh-renewed')
        ->and((string) DB::table('settings')->where('group', 'mail')->where('name', 'graph_refresh_token')->value('payload'))->not->toContain('sender-refresh-renewed');
});

it('sends a follow-up as a reply in the same conversation', function () {
    connectSender();
    fakeGraph([
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages/draft-1/createReply' => Http::response(['id' => 'reply-1'], 201),
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages/reply-1/attachments' => Http::response(['id' => 'att-2'], 201),
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages/reply-1/send' => Http::response('', 202),
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages/reply-1/$value' => Http::response("Message-ID: <reply@eur.prod.outlook.com>\r\n\r\nOpvolging\r\n"),
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages/reply-1*' => Http::response(['id' => 'reply-1', 'internetMessageId' => '<reply@eur.prod.outlook.com>', 'conversationId' => 'conv-1']),
    ]);
    $user = beheerder();
    $offer = preparedOffer($user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    $followUp = app(CreateFollowUpMail::class)->handle($offer->refresh());
    app(OfferWorkflow::class)->approveMail($followUp, $user);
    app(SendOutboundEmail::class)->handle($followUp, $user);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/draft-1/createReply'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/messages/reply-1') && str_starts_with($request['subject'], 'Re: '));

    expect($followUp->refresh()->conversation_id)->toBe('conv-1')
        ->and($offer->refresh()->follow_up_count)->toBe(1);
});

it('explains a missing send permission and keeps the mail retryable', function () {
    connectSender();
    fakeGraph(['graph.microsoft.com/v1.0/users/michel%40fremen.app/messages' => Http::response(['error' => ['message' => 'Access denied']], 403)]);
    $user = beheerder();
    $offer = preparedOffer($user);

    expect(fn () => app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user))
        ->toThrow(RuntimeException::class, 'mag niet versturen vanuit michel@fremen.app');

    expect($offer->offerEmail()->status)->toBe(OutboundEmailStatus::Fout)
        ->and($offer->refresh()->status)->toBe(OfferStatus::KlaarVoorVerzending);
});

it('asks to sign in when sending via Microsoft 365 is not connected', function () {
    $settings = app(MailSettings::class);
    $settings->provider = 'graph';
    $settings->save();
    Http::fake();

    expect(fn () => app(MicrosoftLogin::class)->tokenFor(new SenderConnection(app(MailSettings::class)), 'Niet verbonden.'))
        ->toThrow(RuntimeException::class, 'Niet verbonden.');
});

it('connects the sender mailbox with a one-time sign-in and never shows the token', function () {
    Http::fake([
        'login.microsoftonline.com/organizations/oauth2/v2.0/devicecode' => Http::response(['device_code' => 'device-123', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_in' => 900, 'interval' => 5]),
        'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'sender-refresh-very-secret', 'id_token' => senderIdToken(['preferred_username' => 'michel@fremen.app', 'tid' => 'tenant-fremen'])]),
    ]);
    $user = beheerder();
    $this->actingAs($user);

    Livewire\Livewire::test(MicrosoftLoginPanel::class, ['target' => 'sender'])
        ->assertSee('ABCD-EFGH')
        ->assertSee('versturen')
        ->call('check')
        ->assertSet('state', 'connected');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'devicecode') && str_contains((string) $request['scope'], 'Mail.Send'));

    app()->forgetInstance(MailSettings::class);
    $settings = app(MailSettings::class);
    expect($settings->graph_refresh_token)->toBe('sender-refresh-very-secret')
        ->and($settings->graph_account)->toBe('michel@fremen.app')
        ->and($settings->graph_tenant)->toBe('tenant-fremen');

    $this->get('/instellingen/mail')
        ->assertOk()
        ->assertSee('verbonden als michel@fremen.app')
        ->assertDontSee('sender-refresh-very-secret');
});
