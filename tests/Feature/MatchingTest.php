<?php

use App\Ai\Runs\AiRun;
use App\Domain\Assignments\Enums\AssignmentStatus;
use App\Domain\Assignments\Events\AssignmentIngested;
use App\Domain\Candidates\Enums\Seniority;
use App\Domain\Candidates\Models\Candidate;
use App\Domain\Matching\Actions\MatchCandidate;
use App\Domain\Matching\Enums\AssessmentStatus;
use App\Domain\Matching\Enums\MatchLabel;
use App\Domain\Matching\Enums\Recommendation;
use App\Domain\Matching\Jobs\MatchAssignmentJob;
use App\Domain\Matching\Jobs\RematchCandidateJob;
use App\Domain\Matching\Jobs\RescoreMatchesJob;
use App\Domain\Matching\Models\CandidateMatch;
use App\Domain\Matching\Support\ScoreCalculator;
use App\Settings\MatchingSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    Notification::fake();
    aiProfile();
});

/**
 * Kandidaat die lijkt op Mark (senior, € 95, ruim tien jaar Laravel).
 */
function seniorCandidate(): Candidate
{
    return matchableCandidate('senior-developer', ['name' => 'Alex Voorbeeld', 'seniority' => Seniority::Senior, 'hourly_rate' => 95, 'min_hourly_rate' => 95]);
}

/**
 * Kandidaat die lijkt op Sten (medior, € 75, Laravel sinds augustus 2025).
 */
function mediorCandidate(): Candidate
{
    return matchableCandidate('medior-developer', ['name' => 'Sam Proefpersoon', 'seniority' => Seniority::Medior, 'hourly_rate' => 75, 'min_hourly_rate' => 75]);
}

it('is honest about years: 5 years of Laravel fails for the medior and passes for the senior', function () {
    // Het model is te mild voor de medior ("deels"); de guard rekent met de echte jaren.
    Http::fakeSequence('ollama.test/*')
        ->push(ollamaReply(assessmentJson(['E1' => 'deels', 'W1' => 'onbekend'], 'twijfel')))
        ->push(ollamaReply(assessmentJson(['E1' => 'voldoet', 'W1' => 'voldoet'], 'aanbieden')));

    $assignment = openAssignment();
    $medior = app(MatchCandidate::class)->handle($assignment, mediorCandidate());
    $senior = app(MatchCandidate::class)->handle($assignment, seniorCandidate());

    $mediorE1 = collect($medior->assessment['eisen'])->firstWhere('id', 'E1');

    expect($mediorE1['oordeel'])->toBe('voldoet_niet')
        ->and($mediorE1['gecorrigeerd'])->toBeTrue()
        ->and($mediorE1['bewijs'])->toContain('1,2 jaar')->toContain('minimaal 5 jaar')
        ->and($medior->knockout_failed)->toBeTrue()
        ->and($medior->recommendation)->toBe(Recommendation::NietAanbieden)
        ->and($medior->label)->toBe(MatchLabel::Zwak)
        ->and($medior->warnings)->toContain('Knock-out-eis niet gehaald: E1');

    expect(collect($senior->assessment['eisen'])->firstWhere('id', 'E1')['oordeel'])->toBe('voldoet')
        ->and($senior->knockout_failed)->toBeFalse()
        ->and($senior->recommendation)->toBe(Recommendation::Aanbieden)
        ->and($senior->label)->toBe(MatchLabel::Sterk)
        ->and($assignment->refresh()->status)->toBe(AssignmentStatus::Gematcht);
});

it('also catches a years requirement the extractor did not structure', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson(['E1' => 'voldoet', 'W1' => 'voldoet'])))]);

    $assignment = openAssignment(['requirements' => [
        ['id' => 'E1', 'tekst' => 'Je hebt 5+ jaar ervaring met Laravel', 'knock_out' => false, 'categorie' => 'ervaring'],
    ]]);

    $match = app(MatchCandidate::class)->handle($assignment, mediorCandidate());

    expect($match->assessment['eisen'][0]['oordeel'])->toBe('voldoet_niet')
        ->and($match->recommendation)->toBe(Recommendation::NietAanbieden)
        ->and($match->knockout_failed)->toBeFalse();
});

it('warns about a rate above the maximum without filtering', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $assignment = openAssignment(['max_rate' => 85, 'requirements' => [], 'wishes' => []]);

    $senior = app(MatchCandidate::class)->handle($assignment, seniorCandidate());
    $medior = app(MatchCandidate::class)->handle($assignment, mediorCandidate());

    expect($senior->warnings)->toContain(ScoreCalculator::WARNING_RATE_ABOVE_MAX)
        ->and($senior->hard_filter_passed)->toBeTrue()
        ->and($senior->score_breakdown['rate']['value'])->toBe(0)
        ->and($medior->warnings)->not->toContain(ScoreCalculator::WARNING_RATE_ABOVE_MAX)
        ->and($medior->score_breakdown['rate']['value'])->toBe(1);
});

it('drops an assignment that falls entirely within an absence, without an AI call', function () {
    Http::fake();
    $candidate = seniorCandidate();
    $candidate->absences()->createQuietly(['type' => 'vakantie', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-31']);

    $match = app(MatchCandidate::class)->handle(openAssignment([
        'start_asap' => false, 'start_date' => '2026-12-21', 'end_date' => '2026-12-30',
    ]), $candidate->refresh());

    $absence = collect($match->filter_results)->firstWhere('key', 'afwezigheid');

    expect($absence['outcome'])->toBe('fail')
        ->and($absence['detail'])->toContain('18-12-2026 t/m 31-12-2026')
        ->and($match->hard_filter_passed)->toBeFalse()
        ->and($match->label)->toBe(MatchLabel::Afgevallen)
        ->and($match->assessment_status)->toBe(AssessmentStatus::NietNodig);

    Http::assertNothingSent();
});

it('only warns about a partial overlap with an absence', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $candidate = seniorCandidate();
    $candidate->absences()->createQuietly(['type' => 'vakantie', 'starts_on' => '2026-12-18', 'ends_on' => '2026-12-31']);

    $match = app(MatchCandidate::class)->handle(openAssignment([
        'start_asap' => false, 'start_date' => '2026-12-01', 'duration_months' => 6,
    ]), $candidate->refresh());

    expect(collect($match->filter_results)->firstWhere('key', 'afwezigheid')['outcome'])->toBe('warning')
        ->and($match->hard_filter_passed)->toBeTrue()
        ->and($match->score_breakdown['hours']['detail'])->toContain('gedeeltelijke afwezigheid');
});

it('applies the other hard filters', function (array $assignment, array $candidate, string $filter) {
    Http::fake();

    $match = app(MatchCandidate::class)->handle(openAssignment($assignment), matchableCandidate('senior-developer', $candidate));

    expect(collect($match->filter_results)->firstWhere('key', $filter)['outcome'])->toBe('fail')
        ->and($match->label)->toBe(MatchLabel::Afgevallen);

    Http::assertNothingSent();
})->with([
    'te veel uren' => [['hours_min' => 40, 'hours_max' => 40], ['hours_per_week' => 32], 'uren'],
    'te weinig uren' => [['hours_min' => 8, 'hours_max' => 16], [], 'te_weinig_uren'],
    'loondienst' => [['contract_type' => 'loondienst'], [], 'contractvorm'],
    'deadline verstreken' => [['deadline_at' => '2026-10-14 12:00:00'], [], 'deadline'],
    'pas later beschikbaar' => [['start_asap' => false, 'start_date' => '2026-11-01'], ['available_from' => '2027-01-01'], 'beschikbaarheid'],
    'niet actief' => [[], ['status' => 'inactief'], 'status'],
]);

it('does not use age or birth date in matching or in the prompt', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $candidate = matchableCandidate('senior-developer', ['name' => 'Alex Voorbeeld', 'email' => 'alex@example.test', 'birth_date' => '1985-03-14', 'phone' => '06-12345678']);

    app(MatchCandidate::class)->handle(openAssignment(), $candidate);

    Http::assertSent(function (Request $request): bool {
        $body = json_encode($request->data());

        return ! str_contains($body, 'Alex Voorbeeld')
            && ! str_contains($body, 'alex@example.test')
            && ! str_contains($body, '1985')
            && ! str_contains($body, '06-12345678')
            && str_contains($body, '<berekende_jaren>');
    });
});

it('skips the AI call when the prefilter fails', function () {
    Http::fake();

    $match = app(MatchCandidate::class)->handle(openAssignment(['title' => 'Stage Laravel Developer']), seniorCandidate());

    expect($match->prefilter_passed)->toBeFalse()
        ->and($match->prefilter_reasons[0])->toContain('stage')
        ->and($match->label)->toBe(MatchLabel::Zwak);

    Http::assertNothingSent();
});

it('reuses an assessment for the same input and rescores weights without an AI call', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $assignment = openAssignment();
    $candidate = seniorCandidate();

    $first = app(MatchCandidate::class)->handle($assignment, $candidate);
    app(MatchCandidate::class)->handle($assignment, $candidate);
    Http::assertSentCount(1);

    $settings = app(MatchingSettings::class);
    $settings->weights = ['requirements' => 100, 'wishes' => 0, 'technology' => 0, 'seniority' => 0, 'rate' => 0, 'location' => 0, 'hours' => 0];
    $settings->save();

    (new RescoreMatchesJob)->handle(app(MatchCandidate::class));

    expect($first->refresh()->score)->toBe(100);
    Http::assertSentCount(1);
});

it('re-assesses when the assignment requirements change', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $assignment = openAssignment();
    $candidate = seniorCandidate();

    app(MatchCandidate::class)->handle($assignment, $candidate);
    $assignment->update(['wishes' => [['id' => 'W1', 'tekst' => 'Ervaring met Filament', 'categorie' => 'techniek']]]);
    app(MatchCandidate::class)->handle($assignment->refresh(), $candidate);

    Http::assertSentCount(2);
});

it('pauses automatic matching when only the extraction reserve of the budget is left', function () {
    Http::fake();
    AiRun::query()->create(['agent' => 'test', 'task' => 'extract', 'status' => 'succeeded', 'cost_eur' => 9.5, 'queued_at' => now()]);

    $match = app(MatchCandidate::class)->handle(openAssignment(), seniorCandidate());

    expect($match->assessment_status)->toBe(AssessmentStatus::Budget)
        ->and($match->label)->toBe(MatchLabel::Zwak)
        ->and($match->warnings)->toContain('Nog niet beoordeeld: het AI-budget voor matching is voor vandaag op.');

    Http::assertNothingSent();
});

it('matches a new assignment with every active candidate that has a master profile', function () {
    Queue::fake();
    $active = seniorCandidate();
    matchableCandidate('senior-developer', ['status' => 'inactief']);
    Candidate::factory()->create();

    AssignmentIngested::dispatch(openAssignment(), true);

    Queue::assertPushed(MatchAssignmentJob::class, 1);
    Queue::assertPushed(MatchAssignmentJob::class, fn (MatchAssignmentJob $job): bool => $job->candidateId === $active->id);
});

it('rematches a candidate after a change of match-relevant values', function () {
    Queue::fake();
    $candidate = seniorCandidate();

    $candidate->update(['hourly_rate' => 99]);
    $candidate->absences()->create(['type' => 'vakantie', 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-10']);

    Queue::assertPushed(RematchCandidateJob::class, fn (RematchCandidateJob $job): bool => $job->candidateId === $candidate->id);
});

it('stores the match only once per assignment and candidate', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $assignment = openAssignment();
    $candidate = seniorCandidate();

    (new MatchAssignmentJob($assignment->id, $candidate->id))->handle(app(MatchCandidate::class));
    (new MatchAssignmentJob($assignment->id, $candidate->id, force: true))->handle(app(MatchCandidate::class));

    expect(CandidateMatch::query()->count())->toBe(1);
    Http::assertSentCount(2);
});

it('evaluates the example set with eval:matching', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson(['E1' => 'voldoet', 'E2' => 'voldoet', 'W1' => 'voldoet', 'W2' => 'voldoet'])))]);
    $senior = seniorCandidate();
    $medior = mediorCandidate();

    $this->artisan('eval:matching', ['--path' => base_path('tests/Fixtures/matching'), '--map' => ["senior={$senior->slug}", "medior={$medior->slug}"], '--min-precision' => 0])
        ->expectsOutputToContain('Sterke match     precisie')
        ->expectsOutputToContain('Precisie "sterke match"')
        ->assertSuccessful();

    expect(CandidateMatch::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('treats the same requirements with a different key order as the same input', function () {
    Http::fake(['ollama.test/*' => Http::response(ollamaReply(assessmentJson()))]);
    $assignment = openAssignment();
    $candidate = seniorCandidate();

    app(MatchCandidate::class)->handle($assignment, $candidate);

    // PostgreSQL (jsonb) geeft objecten terug met de sleutels in een andere volgorde.
    $reordered = array_map(function (array $requirement): array {
        krsort($requirement);

        return $requirement;
    }, $assignment->requirements);
    $assignment->forceFill(['requirements' => $reordered])->saveQuietly();

    app(MatchCandidate::class)->handle($assignment->refresh(), $candidate);

    Http::assertSentCount(1);
});
