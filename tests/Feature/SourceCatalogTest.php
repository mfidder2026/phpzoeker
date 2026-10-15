<?php

use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Jobs\DispatchDueSourcesJob;
use App\Domain\Sources\Jobs\FetchSourceJob;
use App\Domain\Sources\Models\Source;
use App\Enums\Role;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Sources\Pages\ListSources;
use Database\Seeders\SourceSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('seeds the full source catalogue with every external source switched off', function () {
    (new SourceSeeder)->run();
    (new SourceSeeder)->run();

    expect(Source::query()->where('key', 'linkedin-jobs')->value('type'))->toBe(SourceType::Alert)
        ->and(Source::query()->where('type', SourceType::Alert->value)->count())->toBe(33)
        ->and(Source::query()->where('type', SourceType::Feed->value)->count())->toBe(4)
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

    Livewire::test(ListSources::class)->callAction('searchAll')->assertNotified('Zoeken gestart in 1 bron');

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
