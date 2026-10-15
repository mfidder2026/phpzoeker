<?php

use App\Ai\AiGateway;
use App\Ai\Config\AiAgentProfile;
use App\Ai\Config\ConnectionTester;
use App\Ai\Exceptions\AiProviderException;
use App\Ai\Jobs\AiConnectionHealthJob;
use App\Ai\Providers\ProviderName;
use App\Ai\Runs\AiRun;
use App\Ai\Runs\AiTask;
use App\Ai\Runs\RunTracker;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Matching\Support\ScoreCalculator;
use App\Domain\Matching\Support\WeightAdvisor;
use App\Domain\Offers\Actions\ImportOffers;
use App\Domain\Offers\Actions\OfferWorkflow;
use App\Domain\Offers\Actions\SendOutboundEmail;
use App\Domain\Offers\Actions\TrackReplies;
use App\Domain\Offers\Enums\OfferActivityType;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Jobs\ReplyTrackerJob;
use App\Domain\Offers\Models\Offer;
use App\Domain\Offers\Notifications\ReplyReceivedNotification;
use App\Domain\Privacy\Jobs\PruneDataJob;
use App\Domain\Reports\Reports;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use App\Settings\MailSettings;
use App\Settings\NotificationSettings;
use App\Settings\PrivacySettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 10:32:00');
    Storage::fake('local');
    Notification::fake();
    fakePdfConverter();
    withoutConsent();
});

/**
 * Een via Microsoft 365 verzonden aanbieding in conversatie "conv-1".
 *
 * @param  list<array<string, mixed>>  $conversation  berichten die Graph in de conversatie teruggeeft
 */
function graphOffer(array $conversation): Offer
{
    aiProfile();
    connectSender();
    fakeGraph([
        'graph.microsoft.com/v1.0/users/michel%40fremen.app/messages?*' => Http::response(['value' => $conversation]),
    ]);
    $user = beheerder();
    $offer = preparedOffer($user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    return $offer->refresh();
}

it('finds a reply to the offer mail, records it once and suggests a status', function () {
    $offer = graphOffer([
        ['id' => 'draft-1', 'internetMessageId' => '<abc@eur.prod.outlook.com>', 'from' => ['emailAddress' => ['address' => 'michel@fremen.app']], 'subject' => 'Aanbieding', 'receivedDateTime' => '2026-11-16T09:32:00Z', 'bodyPreview' => ''],
        ['id' => 'm-2', 'internetMessageId' => '<colleague@fremen.app>', 'from' => ['emailAddress' => ['address' => 'mark@fremen.app']], 'subject' => 'RE: Aanbieding', 'receivedDateTime' => '2026-11-17T08:00:00Z', 'bodyPreview' => 'Top!'],
        ['id' => 'm-3', 'internetMessageId' => '<reply@x.example>', 'from' => ['emailAddress' => ['address' => 'recruiter@x.example']], 'subject' => 'RE: Aanbieding', 'receivedDateTime' => '2026-11-18T13:00:00Z', 'bodyPreview' => 'Wij willen graag kennismaken.'],
    ]);

    Carbon::setTestNow('2026-11-18 15:00:00');

    expect(app(TrackReplies::class)->handle())->toBe(1)
        ->and(app(TrackReplies::class)->handle())->toBe(0);

    $offer->refresh();
    $reply = $offer->activities()->where('type', OfferActivityType::ReplyReceived->value)->sole();

    expect($reply->payload['from'])->toBe('recruiter@x.example')
        ->and($reply->payload['suggested_status'])->toBe(OfferStatus::ReactieOntvangen->value)
        ->and($offer->response_received_at?->toDateTimeString())->toBe('2026-11-18 14:00:00')
        ->and((float) $offer->response_time_workdays)->toBe(2.0)
        ->and($offer->status)->toBe(OfferStatus::Verzonden)
        ->and(TrackReplies::pendingSuggestion($offer)['preview'])->toBe('Wij willen graag kennismaken.');

    Notification::assertSentTo(User::query()->where('id', $offer->sent_by)->sole(), ReplyReceivedNotification::class);
});

it('does not track replies when mail is sent via SMTP', function () {
    Http::fake();
    app(ReplyTrackerJob::class)->handle(app(MailSettings::class), app(TrackReplies::class));

    Http::assertNothingSent();
});

it('reports the funnel, lead times, AI costs and missed chances', function () {
    aiProfile();
    $user = beheerder();
    $offer = preparedOffer($user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);

    // Een aanbieding die het team deed terwijl de app laag scoorde.
    $low = CandidateMatch::query()->forceCreate(['assignment_id' => openAssignment()->id, 'candidate_id' => $offer->candidate_id, 'score' => 40, 'label' => MatchLabel::Zwak, 'hard_filter_passed' => true, 'status' => 'aangeboden', 'assessment_status' => 'klaar']);
    Offer::query()->forceCreate(['match_id' => $low->id, 'assignment_id' => $low->assignment_id, 'candidate_id' => $low->candidate_id, 'dedupe_group_id' => (string) Str::uuid(), 'channel' => 'manual', 'status' => 'verzonden', 'consent_status' => 'niet_nodig', 'sent_at' => now(), 'is_manual' => true]);

    $reports = app(Reports::class);
    $since = Reports::since(90);
    $funnel = collect($reports->funnel($since))->pluck('count', 'label');

    expect($funnel['Sterke of mogelijke match'])->toBe(1)
        ->and($funnel['Aangeboden'])->toBe(2)
        ->and($reports->missedChances($since))->toHaveCount(1)
        ->and($reports->aiCosts(Reports::since(30))['agents'])->not->toBeEmpty()
        ->and(array_sum(array_map('array_sum', $reports->assignmentsPerSource($since)['sources'])))->toBe(1);

    $this->actingAs($user)->get('/rapportages')->assertOk()->assertSee('Funnel')->assertSee('Gemiste kansen')->assertSee('MatchAssessor');
});

it('suggests weights from the feedback that sum to 100', function () {
    $candidate = Candidate::factory()->create();

    foreach ([[1.0, 0.2, 'up'], [0.9, 0.3, 'up'], [1.0, 0.4, 'up'], [0.2, 0.3, 'down'], [0.1, 0.5, 'down'], [0.3, 0.4, 'down']] as [$requirements, $rate, $feedback]) {
        CandidateMatch::query()->forceCreate([
            'assignment_id' => openAssignment()->id, 'candidate_id' => $candidate->id, 'score' => 50, 'label' => $feedback === 'up' ? MatchLabel::Sterk : MatchLabel::Zwak,
            'feedback' => $feedback, 'assessment_status' => 'klaar', 'hard_filter_passed' => true,
            'score_breakdown' => collect(ScoreCalculator::COMPONENTS)->map(fn (string $label, string $key): array => ['label' => $label, 'weight' => 10, 'value' => match ($key) {
                'requirements' => $requirements,
                'rate' => $rate,
                default => 0.5,
            }, 'points' => 0, 'detail' => ''])->all(),
        ]);
    }

    $advice = app(WeightAdvisor::class)->advise();

    expect($advice['ready'])->toBeTrue()
        ->and(array_sum(array_column($advice['components'], 'suggested')))->toBe(100)
        ->and($advice['components']['requirements']['suggested'])->toBeGreaterThan(40)
        ->and($advice['components']['rate']['suggested'])->toBeLessThan(10)
        ->and($advice['agreement'])->toBe(100.0);
});

it('imports earlier offers from a csv and skips duplicates and unknown candidates', function () {
    $user = beheerder();
    offerCandidate(['name' => 'Voorbeeld Kandidaat']);
    $csv = "\xEF\xBB\xBFaangeboden_op;kandidaat;opdracht;referentie;eindklant;via;contactpersoon;contact_email;kanaal;tarief;aangeboden_door;status;reactietermijn;notitie\n"
        ."02-09-2026 10:15;Voorbeeld Kandidaat;Senior PHP Developer;R-1;Gemeente A;Intermediair X;Sam;sam@x.example;mail;95;{$user->email};afgewezen;5;Andere kandidaat\n"
        ."15-09-2026;Voorbeeld Kandidaat;Laravel Developer;;Waterschap B;Platform Y;;;platform;92,50;;intake gepland;;\n"
        ."16-09-2026;Onbekende Persoon;PHP Developer;;;;;;;90;;;;\n";
    Storage::disk('local')->put('import.csv', $csv);

    $result = app(ImportOffers::class)->handle(Storage::disk('local')->path('import.csv'), $user);

    expect($result['imported'])->toBe(2)
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0])->toContain('Onbekende Persoon');

    $offers = Offer::query()->orderBy('sent_at')->get();

    expect($offers->pluck('status')->all())->toBe([OfferStatus::Afgewezen, OfferStatus::IntakeGepland])
        ->and((string) $offers[1]->offered_rate)->toBe('92.50')
        ->and($offers[0]->sent_at?->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i'))->toBe('02-09-2026 10:15')
        ->and($offers[0]->is_manual)->toBeTrue();

    $this->artisan('offers:import', ['file' => Storage::disk('local')->path('import.csv'), '--user' => $user->email])
        ->expectsOutputToContain('0 aanbiedingen geïmporteerd, 3 overgeslagen')
        ->assertSuccessful();
});

it('uses the fallback profile when the primary provider is unavailable', function () {
    aiProfile();
    AiAgentProfile::query()->create([
        'name' => 'Fallback', 'provider' => ProviderName::Ollama, 'api_key' => 'fallback-key-123456', 'base_url' => 'https://fallback.test',
        'models' => ['extract' => 'fallback-model'], 'timeout_seconds' => 30, 'max_concurrency' => 1, 'daily_budget_eur' => 10,
        'prices' => ['*' => ['input' => 0, 'output' => 0]], 'dpa_confirmed_at' => now(), 'is_active' => false, 'is_fallback' => true,
    ]);
    Http::fake([
        'ollama.test/*' => Http::response('Service Unavailable', 503),
        'fallback.test/*' => Http::response(ollamaReply('{"status":"ok"}')),
    ]);

    $run = RunTracker::start(AiTask::Extract, 'test');
    $response = app(AiGateway::class)->chat($run, AiTask::Extract, 'systeem', 'vraag', userInitiated: true);

    expect($response->text)->toContain('ok');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'fallback.test') && $request['model'] === 'fallback-model');
});

it('does not fall back on an invalid key', function () {
    aiProfile();
    AiAgentProfile::query()->create([
        'name' => 'Fallback', 'provider' => ProviderName::Ollama, 'api_key' => 'fallback-key-123456', 'base_url' => 'https://fallback.test',
        'models' => ['extract' => 'fallback-model'], 'timeout_seconds' => 30, 'max_concurrency' => 1, 'daily_budget_eur' => 10,
        'dpa_confirmed_at' => now(), 'is_fallback' => true,
    ]);
    Http::fake(['ollama.test/*' => Http::response(['error' => 'unauthorized'], 401)]);

    expect(fn () => app(AiGateway::class)->chat(RunTracker::start(AiTask::Extract, 'test'), AiTask::Extract, 's', 'u', userInitiated: true))
        ->toThrow(AiProviderException::class);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'fallback.test'));
});

it('warns the beheerder when the daily AI connection test fails', function () {
    $beheerder = userWithRoles(Role::Beheerder);
    $this->actingAs($beheerder);
    aiProfile();
    Http::fake(['ollama.test/*' => Http::response(['error' => 'unauthorized'], 401)]);

    app(AiConnectionHealthJob::class)->handle(app(ConnectionTester::class));

    expect(AiAgentProfile::active()?->last_test_result['passed'])->toBeFalse()
        ->and(SettingsCluster::getNavigationBadge())->toBe('!');
    Notification::assertSentTo($beheerder, DatabaseNotification::class);
});

it('prunes data after the retention periods', function () {
    aiProfile();
    $user = beheerder();
    $offer = preparedOffer($user);
    app(SendOutboundEmail::class)->handle($offer->offerEmail(), $user);
    app(OfferWorkflow::class)->changeStatus($offer->refresh(), OfferStatus::Afgewezen, 'test', $user);
    $recent = RawPosting::query()->first();

    Carbon::setTestNow('2029-01-01 03:00:00');
    $old = RawPosting::query()->forceCreate(['source_id' => $recent->source_id ?? Source::query()->value('id'), 'external_id' => 'oud', 'title' => 'Oud', 'content_hash' => 'x', 'received_at' => now()->subDays(200), 'status' => RawPostingStatus::Processed->value]);
    AiRun::query()->forceCreate(['id' => (string) Str::uuid(), 'agent' => 'oud', 'task' => 'extract', 'status' => 'succeeded', 'queued_at' => now()->subYears(3)]);

    $counts = (new PruneDataJob)->handle(app(PrivacySettings::class));

    expect($counts['aanbiedingen'])->toBe(1)
        ->and($counts['ruwe_publicaties'])->toBeGreaterThanOrEqual(1)
        ->and(Offer::query()->count())->toBe(0)
        ->and(RawPosting::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles("offers/{$offer->id}"))->toBe([])
        ->and(AiRun::query()->where('agent', 'oud')->exists())->toBeFalse();
});

it('posts a strong match to the Teams channel when a webhook is set', function () {
    aiProfile();
    $settings = app(NotificationSettings::class);
    $settings->teams_webhook_url = 'https://teams.example/webhook';
    $settings->save();
    Http::fake([
        'ollama.test/*' => Http::response(ollamaReply(assessmentJson())),
        'teams.example/*' => Http::response('', 202),
    ]);
    userWithRoles(Role::Accountmanager);

    app(MatchCandidate::class)->handle(openAssignment(), matchableCandidate());

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'teams.example')
        && $request['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
        && str_contains($request['attachments'][0]['content']['body'][0]['text'], 'sterke match'));
});

it('renders an English CV for an English assignment', function () {
    aiProfile();
    $user = beheerder();
    $offer = preparedOffer($user, offerAssignment(attributes: ['language' => 'en']));
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($offer->latestCv->docx_path));
    $xml = strip_tags((string) $zip->getFromName('word/document.xml'));
    $zip->close();

    expect($offer->latestCv->language)->toBe('en')
        ->and($xml)->toContain('PROFESSIONAL PROFILE')->toContain('WORK EXPERIENCE')->toContain('FIT WITH THE ASSIGNMENT')
        ->and($xml)->not->toContain('PROFESSIONEEL PROFIEL');
});
