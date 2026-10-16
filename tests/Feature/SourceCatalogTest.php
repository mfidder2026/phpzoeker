<?php

use App\Domain\Sources\Actions\SourceSearch;
use App\Domain\Sources\Connectors\FeedConnector;
use App\Domain\Sources\Connectors\JsonApiConnector;
use App\Domain\Sources\Enums\RawPostingStatus;
use App\Domain\Sources\Enums\SourceRunStatus;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\DispatchDueSourcesJob;
use App\Domain\Sources\Jobs\FetchSourceJob;
use App\Domain\Sources\Models\RawPosting;
use App\Domain\Sources\Models\Source;
use App\Domain\Sources\Models\SourceRun;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Livewire\SourceSearchPanel;
use Database\Seeders\SourceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('seeds the full source catalogue with every external source switched off', function () {
    (new SourceSeeder)->run();
    (new SourceSeeder)->run();

    expect(Source::query()->where('key', 'linkedin-jobs')->value('type'))->toBe(SourceType::Alert)
        ->and(Source::query()->where('type', SourceType::Alert->value)->count())->toBe(33)
        ->and(Source::query()->where('type', SourceType::Feed->value)->count())->toBe(9)
        ->and(Source::query()->where('key', 'tenderned-ict')->exists())->toBeTrue()
        ->and(Source::query()->where('enabled', true)->pluck('key')->all())->toBe(['handmatig']);
});

it('lets a job alert be marked as set up without a ToS check and never fetches it', function () {
    Queue::fake();
    (new SourceSeeder)->run();

    $alert = Source::query()->where('key', 'freelance-nl')->firstOrFail();
    $alert->update(['enabled' => true, 'tos_reviewed_at' => null]);

    (new DispatchDueSourcesJob)->handle();

    expect($alert->refresh()->enabled)->toBeTrue()
        ->and($alert->isDue())->toBeFalse();
    Queue::assertNotPushed(FetchSourceJob::class, fn (FetchSourceJob $job): bool => $job->sourceId === $alert->id);
});

it('still refuses to switch a feed on without a ToS check', function () {
    (new SourceSeeder)->run();

    Source::query()->where('key', 'quest4-opdrachten')->firstOrFail()->update(['enabled' => true]);
})->throws(DomainException::class);

it('searches all active sources at once from the sources page', function () {
    Queue::fake();
    (new SourceSeeder)->run();
    $this->actingAs(userWithRoles(Role::Beheerder));

    $feed = Source::query()->where('key', 'quest4-opdrachten')->firstOrFail();
    $feed->update(['tos_reviewed_at' => now(), 'enabled' => true]);
    Source::query()->where('key', 'freelance-nl')->update(['enabled' => true]);

    Livewire::test(ListSources::class)->callAction('searchAll')->assertDispatched('source-search-started');

    Queue::assertPushed(FetchSourceJob::class, 1);
    Queue::assertPushed(FetchSourceJob::class, fn (FetchSourceJob $job): bool => $job->sourceId === $feed->id);
});

it('shows the search button and the alert status on the dashboard', function () {
    Queue::fake();
    (new SourceSeeder)->run();
    $this->actingAs(userWithRoles(Role::Beheerder));

    Livewire::test(Dashboard::class)
        ->assertSee('Nu zoeken')
        ->assertSee('0 van 33 ingesteld')
        ->callAction('searchAll')
        ->assertNotified('Geen actieve bronnen');

    Queue::assertNothingPushed();
});

it('tracks a search from fetching through AI reading to finished', function () {
    Queue::fake();
    (new SourceSeeder)->run();
    $user = userWithRoles(Role::Beheerder);
    $this->actingAs($user);

    $feed = Source::query()->where('key', 'quest4-opdrachten')->firstOrFail();
    $feed->update(['tos_reviewed_at' => now(), 'enabled' => true]);

    $search = app(SourceSearch::class)->start($user);
    expect($search)->not->toBeNull();

    $progress = app(SourceSearch::class)->progress($search);
    expect($progress['finished'])->toBeFalse()
        ->and($progress['phase'])->toBe('Bronnen ophalen')
        ->and($progress['sources'][0]['status'])->toBe('pending');

    $this->travel(5)->seconds();
    SourceRun::query()->create(['source_id' => $feed->id, 'status' => SourceRunStatus::Succeeded, 'started_at' => now(), 'finished_at' => now(), 'fetched_count' => 12, 'new_count' => 2, 'error_count' => 0]);
    foreach (['a', 'b'] as $externalId) {
        RawPosting::query()->create(['source_id' => $feed->id, 'external_id' => $externalId, 'url' => 'https://quest4.nl/opdracht/'.$externalId, 'title' => 'Opdracht '.$externalId, 'body_text' => 'Tekst', 'content_hash' => $externalId, 'received_at' => now(), 'status' => RawPostingStatus::Pending]);
    }

    $progress = app(SourceSearch::class)->progress($search);
    expect($progress['phase'])->toBe('Opdrachten lezen met AI')
        ->and($progress['sources'][0])->toMatchArray(['status' => 'done', 'fetched' => 12, 'new' => 2])
        ->and($progress['postings_total'])->toBe(2)
        ->and($progress['percent'])->toBe(20);

    $this->travel(20)->seconds();
    RawPosting::query()->where('external_id', 'a')->update(['status' => RawPostingStatus::Irrelevant->value]);

    $progress = app(SourceSearch::class)->progress($search);
    expect($progress['postings_done'])->toBe(1)
        ->and($progress['remaining_seconds'])->toBeGreaterThan(0);

    RawPosting::query()->where('external_id', 'b')->update(['status' => RawPostingStatus::Irrelevant->value]);

    $progress = app(SourceSearch::class)->progress($search);
    expect($progress['finished'])->toBeTrue()->and($progress['percent'])->toBe(100);

    Livewire::test(SourceSearchPanel::class)
        ->assertSet('mode', 'minimized')
        ->call('expand')
        ->assertSee('Zoeken klaar')
        ->assertSee('12 bekeken, 2 nieuw')
        ->call('close')
        ->assertSet('mode', 'hidden');

    expect(app(SourceSearch::class)->current($user))->toBeNull();
});

it('opens the progress panel when a search starts', function () {
    Queue::fake();
    (new SourceSeeder)->run();
    $user = userWithRoles(Role::Beheerder);
    $this->actingAs($user);
    Source::query()->where('key', 'quest4-opdrachten')->firstOrFail()->update(['tos_reviewed_at' => now(), 'enabled' => true]);
    app(SourceSearch::class)->start($user);

    Livewire::test(SourceSearchPanel::class)
        ->dispatch('source-search-started')
        ->assertSet('mode', 'open')
        ->assertSee('Bronnen ophalen')
        ->assertSee('Quest4 — opdrachten — wacht')
        ->call('minimize')
        ->assertSee('Zoeken 0%');
});

it('only fetches sources on a schedule when automatic fetching is switched on', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'bronnen-ophalen');

    config(['phpzoeker.sources_auto_fetch' => false]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['phpzoeker.sources_auto_fetch' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('reads publications from a JSON API with configured field paths', function () {
    $postings = app(JsonApiConnector::class)->parse([
        'content' => [[
            'publicatieId' => '444914', 'publicatieDatum' => '2026-10-16', 'aanbestedingNaam' => 'Integrale ICT-dienstverlening',
            'opdrachtgeverNaam' => 'Stichting TWijs', 'sluitingsDatum' => '2026-11-30T12:00:00',
            'link' => ['href' => 'https://www.tenderned.nl/aankondigingen/overzicht/444914'],
        ]],
    ], [
        'items_path' => 'content',
        'fields' => ['id' => 'publicatieId', 'title' => 'aanbestedingNaam', 'url' => 'link.href', 'date' => 'publicatieDatum', 'body' => ['Opdrachtgever=opdrachtgeverNaam', 'Sluitingsdatum=sluitingsDatum']],
    ]);

    expect($postings)->toHaveCount(1)
        ->and($postings[0]->externalId)->toBe('444914')
        ->and($postings[0]->title)->toBe('Integrale ICT-dienstverlening')
        ->and($postings[0]->url)->toBe('https://www.tenderned.nl/aankondigingen/overzicht/444914')
        ->and($postings[0]->bodyText)->toContain('Opdrachtgever: Stichting TWijs')
        ->and($postings[0]->publishedAt->format('Y-m-d'))->toBe('2026-10-16');
});

it('reads a job XML feed in Indeed format', function () {
    $postings = app(FeedConnector::class)->parse(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <jobs><job id="abc-1"><id><![CDATA[abc-1]]></id><title><![CDATA[Senior PHP Developer]]></title>
        <url><![CDATA[https://devitjobs.nl/jobs/Voorbeeld-PHP]]></url><city><![CDATA[Utrecht]]></city><company><![CDATA[Voorbeeld BV]]></company>
        <pubdate><![CDATA[15.10.2026]]></pubdate><description><![CDATA[<p>Laravel en Vue</p>]]></description></job></jobs>
        XML);

    expect($postings)->toHaveCount(1)
        ->and($postings[0]->externalId)->toBe('abc-1')
        ->and($postings[0]->url)->toBe('https://devitjobs.nl/jobs/Voorbeeld-PHP')
        ->and($postings[0]->bodyText)->toContain('Voorbeeld BV')->toContain('Laravel en Vue')
        ->and($postings[0]->publishedAt->format('Y-m-d'))->toBe('2026-10-15');
});

it('approves the ToS check and switches on selected sources in one go', function () {
    (new SourceSeeder)->run();
    $this->actingAs(userWithRoles(Role::Beheerder));
    $sources = Source::query()->whereIn('key', ['larajobs', 'opdrachtoverheid', 'freelance-nl'])->get();

    Livewire::test(ListSources::class)
        ->callTableBulkAction('approveAndEnable', $sources)
        ->assertNotified('3 bronnen aangezet');

    expect(Source::query()->whereIn('key', ['larajobs', 'opdrachtoverheid', 'freelance-nl'])->where('enabled', true)->count())->toBe(3)
        ->and(Source::query()->where('key', 'larajobs')->value('tos_reviewed_at'))->not->toBeNull();
});
