<?php

use App\Domain\Assignments\Models\AssignmentSource;
use App\Domain\Geo\Geocoder;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\MatchStatus;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Sources\Enums\SourceType;
use App\Domain\Sources\Models\Source;
use App\Filament\Pages\Shortlist;
use App\Filament\Resources\Matches\Pages\ListMatches;
use App\Filament\Resources\Matches\Pages\ViewMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    Notification::fake();
    aiProfile();
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
});

function strongMatch(): CandidateMatch
{
    return app(MatchCandidate::class)->handle(openAssignment(), matchableCandidate('senior-developer', ['name' => 'Alex Voorbeeld']));
}

it('lists matches and shows the detail with breakdown and requirements matrix', function () {
    $match = strongMatch();
    $user = beheerder();

    $this->actingAs($user);

    Livewire::test(ListMatches::class)->assertCanSeeTableRecords([$match]);

    $this->get('/matches/'.$match->id)
        ->assertOk()
        ->assertSee('Score-opbouw')
        ->assertSee('Minimaal 5 jaar ervaring met Laravel')
        ->assertSee('werkervaring[0]')
        ->assertSee('Harde filters');

    expect($match->refresh()->status)->toBe(MatchStatus::Bekeken);
});

it('shows on the match detail for whom the assignment is and that the contact email is missing', function () {
    $match = strongMatch();
    $match->assignment->forceFill(['intermediary_name' => 'Bemiddelaar BV'])->save();
    $source = Source::query()->create(['key' => 'striive', 'name' => 'Striive', 'type' => SourceType::Html, 'enabled' => false]);
    AssignmentSource::query()->create([
        'assignment_id' => $match->assignment_id, 'source_id' => $source->id, 'url' => 'https://striive.test/opdracht/42',
        'contact_name' => 'Petra Contact', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $this->actingAs(beheerder())
        ->get('/matches/'.$match->id)
        ->assertOk()
        ->assertSee('Voor wie en waar te reageren')
        ->assertSee('Gemeente Voorbeeldstad')
        ->assertSee('Bemiddelaar BV')
        ->assertSee('Petra Contact')
        ->assertSee('geen e-mailadres bekend')
        ->assertSee('Opdracht openen bij Striive');
});

it('shows the matches on the assignment detail and the best score in the list', function () {
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function geocode(string $place): ?array
        {
            return null;
        }
    });
    $match = strongMatch();

    $this->actingAs(beheerder())
        ->get('/opdrachten/'.$match->assignment_id.'?tab=matches::data::tab')
        ->assertOk()
        ->assertSee('Alex Voorbeeld')
        ->assertSee('Score-opbouw');

    $this->get('/opdrachten')->assertOk()->assertSee('AV '.$match->score);
});

it('records feedback and moves a match to the shortlist and back', function () {
    $match = strongMatch();
    $this->actingAs(beheerder());

    Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->callAction('feedbackDown', data: ['reason' => 'tarief', 'note' => 'te duur voor deze klant'])
        ->callAction('shortlist');

    expect($match->refresh())
        ->feedback->toBe(CandidateMatch::FEEDBACK_DOWN)
        ->feedback_reason->toBe('Tarief past niet: te duur voor deze klant')
        ->status->toBe(MatchStatus::Shortlist);

    Livewire::test(Shortlist::class)
        ->assertSee($match->assignment->title)
        ->call('move', $match->id, 'afgewezen');

    expect($match->refresh()->status)->toBe(MatchStatus::Afgewezen)
        ->and($match->rejection_reason)->toBe('Afgewezen op de shortlist');
});

it('requires a reason to override a failed knock-out', function () {
    // Medior-profiel: Laravel sinds 2025, dus de eis "5 jaar Laravel" (knock-out) faalt.
    $match = app(MatchCandidate::class)->handle(openAssignment(), matchableCandidate('medior-developer'));
    $this->actingAs(beheerder());

    expect($match->knockout_failed)->toBeTrue();

    Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->callAction('overrideKnockout', data: ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);

    Livewire::test(ViewMatch::class, ['record' => $match->id])
        ->callAction('overrideKnockout', data: ['reason' => 'Bevestigd: 6 jaar Laravel bij een vorige werkgever.']);

    expect($match->refresh()->knockout_override_reason)->toContain('6 jaar');
});

it('lets a kandidaat see and react to only the own matches', function () {
    $own = strongMatch();
    $other = app(MatchCandidate::class)->handle($own->assignment, matchableCandidate('senior-developer', ['name' => 'Andere Kandidaat']));
    $user = kandidaatUser($own->candidate);

    $this->actingAs($user);

    Livewire::test(ListMatches::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
    $this->get('/matches/'.$other->id)->assertNotFound();

    Livewire::test(ViewMatch::class, ['record' => $own->id])
        ->assertActionHidden('shortlist')
        ->callAction('interestUp', data: ['reason' => 'Lijkt me een mooie opdracht']);

    expect($own->refresh()->candidate_interest)->toBe(CandidateMatch::FEEDBACK_UP)
        ->and($own->status)->toBe(MatchStatus::Nieuw);
});

it('shows strong matches on the dashboard', function () {
    $match = strongMatch();

    $this->actingAs(beheerder())->get('/')
        ->assertOk()
        ->assertSee('Sterke match '.$match->score)
        ->assertSee($match->assignment->title);
});
